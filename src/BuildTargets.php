<?php
declare(strict_types=1);

namespace DevDelivery;

use PDOException;

final readonly class BuildTargets
{
    public const MAX_CONTENT_BYTES = 8_388_608;

    public function __construct(private Store $store, private Projects $projects, private Audit $audit)
    {
    }

    public function register(Actor $actor, int $project, array $input): array
    {
        $id = Input::integer($input, 'build_id', 1);
        $name = Input::string($input, 'name', 160);
        $sha = Input::sha256($input, 'sha256');
        return $this->store->transaction(function () use ($actor, $project, $id, $name, $sha): array {
            $this->projects->lockForRelease($actor, $project);
            $identity = [$actor->tenantId, $project, $id];
            $build = $this->store->one('SELECT status FROM build_runs WHERE tenant_id=? AND project_id=? AND id=?', $identity);
            if (!$build) {
                throw new Problem(404, 'build_not_found');
            }
            if ($build['status'] !== 'passed') {
                throw new Problem(409, 'passed_build_required');
            }
            $existing = $this->store->one('SELECT id, name FROM build_artifacts
                WHERE tenant_id=? AND project_id=? AND build_id=? AND sha256=?', [...$identity, $sha]);
            if ($existing) {
                if ($existing['name'] !== $name) {
                    throw new Problem(409, 'artifact_metadata_conflict');
                }
                return $this->artifact($actor, $project, $existing['id']);
            }
            $artifact = $this->store->insert('INSERT INTO build_artifacts (tenant_id, project_id, build_id, name, sha256, registered_by)
                VALUES (?, ?, ?, ?, ?, ?)', [...$identity, $name, $sha, $actor->accountId]);
            $this->audit->record($actor, 'project', $project, 'artifact_registered', ['artifact_id' => $artifact, 'build_id' => $id, 'sha256' => $sha]);
            return $this->artifact($actor, $project, $artifact);
        });
    }

    public function createEnvironment(Actor $actor, int $project, array $input): array
    {
        $code = Input::slug($input, 'code');
        $name = Input::string($input, 'name', 160);
        $stage = Input::choice($input, 'stage', ['development', 'staging', 'production']);
        try {
            return $this->store->transaction(function () use ($actor, $project, $code, $name, $stage): array {
                $this->projects->lockForRelease($actor, $project);
                $id = $this->store->insert('INSERT INTO environments (tenant_id, project_id, code, name, stage) VALUES (?, ?, ?, ?, ?)',
                    [$actor->tenantId, $project, $code, $name, $stage]);
                $this->audit->record($actor, 'project', $project, 'environment_registered', ['environment_id' => $id, 'stage' => $stage]);
                return $this->environment($actor, $project, $id);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'environment_code_exists');
            }
            throw $error;
        }
    }

    public function upload(Actor $actor, int $project, int $id, string $bytes): array
    {
        $length = strlen($bytes);
        if ($length === 0 || $length > self::MAX_CONTENT_BYTES) {
            throw new Problem($length === 0 ? 422 : 413, $length === 0 ? 'artifact_content_empty' : 'payload_too_large');
        }
        return $this->store->transaction(function () use ($actor, $project, $id, $bytes, $length): array {
            $this->projects->lockForRelease($actor, $project);
            $artifact = $this->artifact($actor, $project, $id);
            if (!hash_equals($artifact['sha256'], hash('sha256', $bytes))) {
                throw new Problem(422, 'artifact_digest_mismatch');
            }
            if ($artifact['byte_length'] !== null) {
                return $artifact;
            }
            $this->store->execute('INSERT INTO artifact_contents (tenant_id, project_id, artifact_id, byte_length, content, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?)', [$actor->tenantId, $project, $id, $length, $bytes, $actor->accountId]);
            $this->audit->record($actor, 'project', $project, 'artifact_content_verified', ['artifact_id' => $id, 'byte_length' => $length]);
            return $this->artifact($actor, $project, $id);
        });
    }

    public function download(Actor $actor, int $project, int $id): string
    {
        $this->projects->view($actor, $project);
        if (!$actor->has('release_manager') && !$actor->has('tester') && !$actor->has('engineer')) {
            throw new Problem(403, 'artifact_content_access_required');
        }
        $artifact = $this->store->one('SELECT c.content, a.sha256 FROM artifact_contents c
            JOIN build_artifacts a ON (a.tenant_id,a.project_id,a.id)=(c.tenant_id,c.project_id,c.artifact_id)
            WHERE c.tenant_id=? AND c.project_id=? AND c.artifact_id=?', [$actor->tenantId, $project, $id]);
        if (!$artifact) {
            throw new Problem(404, 'artifact_content_not_found');
        }
        if (!hash_equals($artifact['sha256'], hash('sha256', $artifact['content']))) {
            throw new Problem(503, 'artifact_integrity_error');
        }
        return $artifact['content'];
    }

    public function listing(Actor $actor, int $project, int $after, string $kind): array
    {
        $this->projects->view($actor, $project);
        $query = match ($kind) {
            'artifacts' => 'SELECT a.id, a.build_id, a.name, a.sha256, a.registered_at, b.provider_run_id, c.byte_length,
                s.commit_sha FROM build_artifacts a JOIN build_runs b ON b.id=a.build_id JOIN git_snapshots s ON s.id=b.snapshot_id
                LEFT JOIN artifact_contents c ON (c.tenant_id,c.project_id,c.artifact_id)=(a.tenant_id,a.project_id,a.id)
                WHERE a.tenant_id=? AND a.project_id=? AND a.id>? ORDER BY a.id LIMIT 51',
            'environments' => 'SELECT id, code, name, stage FROM environments WHERE tenant_id=? AND project_id=? AND id>? ORDER BY id LIMIT 51',
        };
        $rows = $this->store->all($query, [$actor->tenantId, $project, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function artifact(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        return $this->store->one('SELECT a.id, a.build_id, a.name, a.sha256, a.registered_by, a.registered_at,
                b.provider_run_id, b.snapshot_id, s.repository_id, s.commit_sha, s.branch, c.byte_length
            FROM build_artifacts a JOIN build_runs b ON b.id=a.build_id JOIN git_snapshots s ON s.id=b.snapshot_id
            LEFT JOIN artifact_contents c ON (c.tenant_id,c.project_id,c.artifact_id)=(a.tenant_id,a.project_id,a.id)
            WHERE a.tenant_id=? AND a.project_id=? AND a.id=?', [$actor->tenantId, $project, $id])
            ?? throw new Problem(404, 'artifact_not_found');
    }

    public function environment(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        return $this->store->one('SELECT id, code, name, stage FROM environments WHERE tenant_id=? AND project_id=? AND id=?',
            [$actor->tenantId, $project, $id]) ?? throw new Problem(404, 'environment_not_found');
    }
}
