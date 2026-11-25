<?php
declare(strict_types=1);

namespace DevDelivery;

use PDOException;

final readonly class GitDelivery
{
    public function __construct(private Store $store, private Projects $projects, private Audit $audit)
    {
    }

    public function register(Actor $actor, int $project, array $input): array
    {
        $name = Input::string($input, 'name', 160);
        $provider = Input::choice($input, 'provider', ['github', 'gitee', 'gitlab', 'manual']);
        $remoteId = self::externalId($input, 'provider_repository_id');
        try {
            return $this->store->transaction(function () use ($actor, $project, $name, $provider, $remoteId): array {
                $this->projects->lockForPlanning($actor, $project);
                $id = $this->store->insert('INSERT INTO repositories
                    (tenant_id, project_id, name, provider, provider_repository_id, connector_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$actor->tenantId, $project, $name, $provider, $remoteId, bin2hex(random_bytes(16)), $actor->accountId]);
                $this->audit->record($actor, 'project', $project, 'repository_registered', ['repository_id' => $id, 'provider' => $provider]);
                return $this->repository($actor->tenantId, $project, $id, false);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'repository_identity_exists');
            }
            throw $error;
        }
    }

    public function listing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        $rows = $this->store->all('SELECT id, name, provider, provider_repository_id, created_at
            FROM repositories WHERE tenant_id=? AND project_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $project, $after]);
        return self::page($rows);
    }

    public function branches(Actor $actor, int $project, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        $repository = $this->repository($actor->tenantId, $project, $id, false);
        $rows = $this->store->all('SELECT s.id, s.repository_id, s.branch, s.generation, s.commit_sha, s.previous_sha, s.previous_snapshot_id, s.forced,
                s.message, s.created_at, b.provider_run_id, b.status AS build_status
            FROM git_snapshots s LEFT JOIN build_runs b ON b.id=(SELECT br.id FROM build_runs br
                WHERE (br.tenant_id,br.project_id,br.repository_id,br.snapshot_id)=(s.tenant_id,s.project_id,s.repository_id,s.id)
                ORDER BY br.run_sequence DESC LIMIT 1)
            WHERE s.tenant_id=? AND s.project_id=? AND s.repository_id=? AND s.id>?
            AND NOT EXISTS (SELECT 1 FROM git_snapshots n WHERE
                (n.tenant_id,n.project_id,n.repository_id,n.branch)=(s.tenant_id,s.project_id,s.repository_id,s.branch)
                AND n.generation>s.generation)
            ORDER BY s.id LIMIT 51', [$actor->tenantId, $project, $id, $after]);
        return $repository + self::page($rows);
    }

    public function snapshotView(Actor $actor, int $project, int $repository, int $snapshot): array
    {
        $this->projects->view($actor, $project);
        $repo = $this->repository($actor->tenantId, $project, $repository, false);
        $item = $this->snapshot($repo, $snapshot);
        $identity = [...$this->identity($repo), $snapshot];
        $item['build_runs'] = $this->store->all('SELECT id, provider_run_id, run_sequence, status, updated_at FROM build_runs
            WHERE tenant_id=? AND project_id=? AND repository_id=? AND snapshot_id=? ORDER BY run_sequence DESC LIMIT 50', $identity);
        $item['merge_requests'] = $this->store->all('SELECT id, remote_id, source_version, state, title, updated_at FROM merge_requests
            WHERE tenant_id=? AND project_id=? AND repository_id=? AND snapshot_id=? ORDER BY id DESC LIMIT 50', $identity);
        return $item + ['current' => $this->current($repo, $item['branch'])['id'] === $snapshot];
    }

    public function claim(Actor $actor, int $project, int $work): array
    {
        return $this->store->transaction(function () use ($actor, $project, $work): array {
            $this->projects->lockForEngineering($actor, $project);
            $item = $this->work($actor, $project, $work);
            if ($item['owner_id'] !== $actor->accountId) {
                throw new Problem(403, 'work_item_owner_required');
            }
            if ($item['claimed_by'] === null) {
                $this->store->execute('UPDATE work_items SET claimed_by=?, claimed_at=UTC_TIMESTAMP(6)
                    WHERE tenant_id=? AND project_id=? AND id=?', [$actor->accountId, $actor->tenantId, $project, $work]);
                $this->audit->record($actor, 'work_item', $work, 'work_item_claimed', ['project_id' => $project]);
            }
            return $this->work($actor, $project, $work);
        });
    }

    public function link(Actor $actor, int $project, int $work, array $input): array
    {
        $repo = Input::integer($input, 'repository_id', 1);
        $snapshot = Input::integer($input, 'snapshot_id', 1);
        return $this->store->transaction(function () use ($actor, $project, $work, $repo, $snapshot): array {
            $this->projects->lockForEngineering($actor, $project);
            if ($this->work($actor, $project, $work)['claimed_by'] !== $actor->accountId) {
                throw new Problem(403, 'work_item_claim_required');
            }
            $repository = $this->repository($actor->tenantId, $project, $repo, false);
            $item = $this->snapshot($repository, $snapshot);
            $identity = [$actor->tenantId, $project, $work, $repo, $snapshot];
            $existing = $this->store->one('SELECT id, linked_by, linked_at FROM work_git_links
                WHERE tenant_id=? AND project_id=? AND work_item_id=? AND repository_id=? AND snapshot_id=?', $identity);
            if (!$existing) {
                $id = $this->store->insert('INSERT INTO work_git_links (tenant_id, project_id, work_item_id, repository_id, snapshot_id, linked_by)
                    VALUES (?, ?, ?, ?, ?, ?)', [...$identity, $actor->accountId]);
                $this->audit->record($actor, 'work_item', $work, 'git_snapshot_linked', ['project_id' => $project,
                    'repository_id' => $repo, 'snapshot_id' => $snapshot, 'branch' => $item['branch'], 'commit_sha' => $item['commit_sha']]);
                $existing = $this->store->one('SELECT id, linked_by, linked_at FROM work_git_links WHERE id=?', [$id]);
            }
            return $existing + ['repository_id' => $repo, 'snapshot_id' => $snapshot, 'commit_sha' => $item['commit_sha']];
        });
    }

    public function workLinks(Actor $actor, int $project, int $work, int $after): array
    {
        $this->projects->view($actor, $project);
        $this->work($actor, $project, $work);
        $rows = $this->store->all('SELECT l.id, r.name AS repository_name, r.provider, s.repository_id, s.id AS snapshot_id, s.branch,
                s.generation, s.commit_sha, l.linked_by, l.linked_at
            FROM work_git_links l JOIN git_snapshots s ON s.id=l.snapshot_id JOIN repositories r ON r.id=s.repository_id
            WHERE l.tenant_id=? AND l.project_id=? AND l.work_item_id=? AND l.id>? ORDER BY l.id LIMIT 51',
            [$actor->tenantId, $project, $work, $after]);
        return self::page($rows);
    }

    public function advance(array $repository, array $input): array
    {
        $branch = Input::string($input, 'branch', 240);
        if ($branch !== $input['branch'] || $branch === '@' || str_starts_with($branch, '-') || str_ends_with($branch, '.')
            || preg_match('#(?:\.\.|@\{|[\x00-\x20\x7f~^:?*\[\\\\])#', $branch)
            || preg_match('~(?:\A|/)(?:\.|/)|\.lock(?:/|\z)|/\z~', $branch)) {
            throw new Problem(422, 'git_branch_invalid');
        }
        if (!array_key_exists('before_sha', $input)) {
            throw new Problem(422, 'invalid_input', ['field' => 'before_sha']);
        }
        if (!array_key_exists('before_snapshot_id', $input)) {
            throw new Problem(422, 'invalid_input', ['field' => 'before_snapshot_id']);
        }
        $before = $input['before_sha'] === null ? null : self::sha($input, 'before_sha');
        $beforeSnapshot = $input['before_snapshot_id'] === null ? null : Input::integer($input, 'before_snapshot_id', 1);
        $after = self::sha($input, 'commit_sha');
        $forced = Input::boolean($input, 'forced');
        $message = Input::string($input, 'message', 500, 0);
        $current = $this->current($repository, $branch);
        if ($before !== ($current['commit_sha'] ?? null) || $beforeSnapshot !== ($current['id'] ?? null)) {
            throw new Problem(409, 'git_reference_stale', ['snapshot_id' => $current['id'] ?? null, 'commit_sha' => $current['commit_sha'] ?? null]);
        }
        if ($current && $before === $after) {
            return ['state' => 'unchanged', 'snapshot' => $current];
        }
        $id = $this->store->insert('INSERT INTO git_snapshots
            (tenant_id, project_id, repository_id, branch, generation, commit_sha, previous_sha, previous_snapshot_id, forced, message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [...$this->identity($repository), $branch, ($current['generation'] ?? 0) + 1, $after, $before, $beforeSnapshot, $forced, $message]);
        return ['state' => 'advanced', 'snapshot' => $this->snapshot($repository, $id)];
    }

    public function repository(int $tenant, int $project, int $id, bool $lock): array
    {
        $row = $this->store->one('SELECT id, tenant_id, project_id, name, provider, provider_repository_id, created_at'
            . ($lock ? ', connector_id' : '') . ' FROM repositories WHERE tenant_id=? AND project_id=? AND id=?'
            . ($lock ? ' FOR UPDATE' : ''), [$tenant, $project, $id]);
        return $row ?? throw new Problem(404, 'repository_not_found');
    }

    public function snapshot(array $repository, int $id): array
    {
        $row = $this->store->one('SELECT id, repository_id, branch, generation, commit_sha, previous_sha, previous_snapshot_id, forced, message, created_at
            FROM git_snapshots WHERE tenant_id=? AND project_id=? AND repository_id=? AND id=?', [...$this->identity($repository), $id]);
        return $row ?? throw new Problem(404, 'git_snapshot_not_found');
    }

    public function current(array $repository, string $branch): ?array
    {
        return $this->store->one('SELECT id, repository_id, branch, generation, commit_sha, previous_sha, previous_snapshot_id, forced, message, created_at
            FROM git_snapshots WHERE tenant_id=? AND project_id=? AND repository_id=? AND branch=? ORDER BY generation DESC LIMIT 1',
            [...$this->identity($repository), $branch]);
    }

    public static function sha(array $input, string $field): string
    {
        $sha = strtolower(Input::string($input, $field, 64, 40));
        if (!preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $sha) || trim($sha, '0') === '') {
            throw new Problem(422, 'git_commit_invalid', ['field' => $field]);
        }
        return $sha;
    }

    public static function externalId(array $input, string $field): string
    {
        $id = Input::string($input, $field, 160);
        if (!preg_match('~\A[A-Za-z0-9][A-Za-z0-9._:/@-]*\z~', $id)) {
            throw new Problem(422, 'invalid_input', ['field' => $field]);
        }
        return $id;
    }

    public function identity(array $repo): array
    {
        return [$repo['tenant_id'], $repo['project_id'], $repo['id']];
    }

    private function work(Actor $actor, int $project, int $id): array
    {
        return $this->store->one('SELECT id, owner_id, claimed_by, claimed_at FROM work_items WHERE tenant_id=? AND project_id=? AND id=?',
            [$actor->tenantId, $project, $id]) ?? throw new Problem(404, 'work_item_not_found');
    }

    private static function page(array $rows): array
    {
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }
}
