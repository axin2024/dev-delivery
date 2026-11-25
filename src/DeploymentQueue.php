<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class DeploymentQueue
{
    public function __construct(private Store $store, private Projects $projects, private BuildTargets $targets,
        private ReleaseCandidates $candidates, private Audit $audit)
    {
    }

    public function request(Actor $actor, int $project, array $input, ?int $rollbackOf = null): array
    {
        $environmentId = Input::integer($input, 'environment_id', 1);
        $key = GitDelivery::externalId($input, 'request_key');
        $reason = Input::string($input, 'reason', 1000);
        $digest = Input::sha256($input, 'artifact_sha256');
        $candidateId = $rollbackOf === null ? Input::integer($input, 'candidate_id', 1) : null;
        $sourceId = isset($input['promotion_source_id']) ? Input::integer($input, 'promotion_source_id', 1) : null;
        $headId = isset($input['expected_head_id']) ? Input::integer($input, 'expected_head_id', 1) : null;
        if ($rollbackOf !== null && ($sourceId || $headId !== $rollbackOf)) {
            throw new Problem(422, 'rollback_expected_head_required');
        }
        $requestDigest = hash('sha256', json_encode([$environmentId, $candidateId, $sourceId, $headId, $rollbackOf, $digest, $reason], JSON_THROW_ON_ERROR));

        return $this->store->transaction(function () use ($actor, $project, $environmentId, $key, $reason, $digest, $candidateId, $sourceId, $headId, $rollbackOf, $requestDigest): array {
            $this->projects->lockForRelease($actor, $project);
            $identity = [$actor->tenantId, $project];
            $env = $this->targets->environment($actor, $project, $environmentId);
            $existing = $this->byKey($actor->tenantId, $project, $environmentId, $key);
            if ($existing) {
                if ($existing['request_digest'] !== $requestDigest) {
                    throw new Problem(409, 'deployment_request_conflict', ['deployment_id' => $existing['id']]);
                }
                return $this->view($actor, $project, $existing['id']);
            }
            if ($this->head($actor->tenantId, $project, $environmentId) !== $headId) {
                throw new Problem(409, 'environment_head_changed');
            }
            if ($active = $this->store->one('SELECT id FROM deployments WHERE tenant_id=? AND project_id=? AND environment_id=? AND active_slot=1', [...$identity, $environmentId])) {
                throw new Problem(409, 'environment_has_active_deployment', ['deployment_id' => $active['id']]);
            }
            if ($rollbackOf === null) {
                $candidate = $this->candidates->view($actor, $project, $candidateId);
                $artifactId = $candidate['artifact_id'];
                if ($digest !== $candidate['manifest']['artifact_sha256']) {
                    throw new Problem(409, 'deployment_artifact_mismatch');
                }
                $this->validatePromotion($actor, $project, $env, $candidate, $sourceId);
                $gate = $env['stage'] === 'production' ? $candidate['gate_digest'] : null;
                $restore = null;
            } else {
                $current = $this->row($actor->tenantId, $project, $rollbackOf);
                if ($env['stage'] !== 'production' || $current['environment_id'] !== $environmentId || !$current['previous_head_id']) {
                    throw new Problem(409, 'rollback_source_not_available');
                }
                $restore = $this->row($actor->tenantId, $project, $current['previous_head_id']);
                if (!$restore['approved_gate_digest'] || $restore['status'] !== 'succeeded' || !$restore['byte_length']
                    || $digest === $current['artifact_sha256']) {
                    throw new Problem(409, 'rollback_source_not_available');
                }
                if ($digest !== $restore['artifact_sha256']) {
                    throw new Problem(409, 'deployment_artifact_mismatch');
                }
                $candidateId = $restore['candidate_id'];
                $artifactId = $restore['artifact_id'];
                $gate = $restore['approved_gate_digest'];
            }
            $kind = $rollbackOf === null ? 'promote' : 'rollback';
            $id = $this->store->insert('INSERT INTO deployments (tenant_id, project_id, environment_id, candidate_id, artifact_id,
                request_key, request_digest, kind, promotion_source_id, previous_head_id, restored_from_id, approved_gate_digest, requested_by, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$identity, $environmentId, $candidateId, $artifactId, $key, $requestDigest, $kind, $sourceId, $headId, $restore['id'] ?? null, $gate, $actor->accountId, $reason]);
            $this->record($actor->tenantId, $project, $id, $actor->accountId, 'deployment_queued',
                ['environment_id' => $environmentId, 'artifact_sha256' => $digest, 'kind' => $kind, 'previous_head_id' => $headId]);
            return $this->view($actor, $project, $id);
        });
    }

    public function timeout(Actor $actor, int $project, int $id, array $input): array
    {
        $key = GitDelivery::externalId($input, 'request_key');
        $reason = Input::string($input, 'reason', 1000);
        return $this->store->transaction(function () use ($actor, $project, $id, $key, $reason): array {
            $this->projects->lockForRelease($actor, $project);
            $deployment = $this->row($actor->tenantId, $project, $id);
            if ($deployment['request_key'] !== $key) {
                throw new Problem(409, 'deployment_request_conflict');
            }
            if (in_array($deployment['status'], ['queued', 'running'], true)) {
                $this->store->execute("UPDATE deployments SET status='unknown', updated_at=UTC_TIMESTAMP(6)
                    WHERE tenant_id=? AND project_id=? AND id=?", [$actor->tenantId, $project, $id]);
                $this->record($actor->tenantId, $project, $id, $actor->accountId, 'deployment_unknown', ['reason' => $reason]);
            }
            return $this->view($actor, $project, $id);
        });
    }

    public function listing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        $rows = $this->store->all('SELECT d.id, d.environment_id, e.code AS environment_code, e.stage, d.candidate_id, d.artifact_id,
                d.kind, d.request_key, d.status, d.external_run_id, d.updated_at
            FROM deployments d JOIN environments e ON e.id=d.environment_id
            WHERE d.tenant_id=? AND d.project_id=? AND d.id>? ORDER BY d.id LIMIT 51', [$actor->tenantId, $project, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function lookup(Actor $actor, int $project, int $environment, string $key): array
    {
        $this->targets->environment($actor, $project, $environment);
        $key = GitDelivery::externalId(['request_key' => $key], 'request_key');
        $request = $this->byKey($actor->tenantId, $project, $environment, $key) ?? throw new Problem(404, 'deployment_not_found');
        return $this->view($actor, $project, $request['id']);
    }

    public function view(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        $row = $this->row($actor->tenantId, $project, $id);
        $row['current_head_id'] = $this->head($actor->tenantId, $project, $row['environment_id']);
        unset($row['request_digest']);
        return $row;
    }

    public function timeline(Actor $actor, int $project, int $id, int $after): array
    {
        $this->view($actor, $project, $id);
        return $this->audit->timeline($actor, 'deployment', $id, $after);
    }

    public function row(int $tenant, int $project, int $id): array
    {
        return $this->store->one('SELECT d.id, d.environment_id, e.code AS environment_code, e.stage, d.candidate_id, d.artifact_id,
                a.sha256 AS artifact_sha256, ac.byte_length, d.request_key, d.request_digest, d.kind, d.promotion_source_id,
                d.previous_head_id, d.restored_from_id, d.approved_gate_digest, d.status, d.external_run_id, d.external_sequence,
                d.requested_by, d.reason, d.created_at, d.updated_at, d.completed_at
            FROM deployments d JOIN environments e ON (e.tenant_id,e.project_id,e.id)=(d.tenant_id,d.project_id,d.environment_id)
            JOIN build_artifacts a ON (a.tenant_id,a.project_id,a.id)=(d.tenant_id,d.project_id,d.artifact_id)
            LEFT JOIN artifact_contents ac ON (ac.tenant_id,ac.project_id,ac.artifact_id)=(a.tenant_id,a.project_id,a.id)
            WHERE d.tenant_id=? AND d.project_id=? AND d.id=?', [$tenant, $project, $id]) ?? throw new Problem(404, 'deployment_not_found');
    }

    public function head(int $tenant, int $project, int $environment): ?int
    {
        return $this->store->one('SELECT deployment_id FROM environment_heads WHERE tenant_id=? AND project_id=? AND environment_id=?',
            [$tenant, $project, $environment])['deployment_id'] ?? null;
    }

    public function record(int $tenant, int $project, int $deployment, ?int $actor, string $event, array $details): void
    {
        $this->audit->recordFor($tenant, $actor, 'deployment', $deployment, $event, ['project_id' => $project] + $details);
        $this->audit->recordFor($tenant, $actor, 'project', $project, $event, ['deployment_id' => $deployment] + $details);
    }

    private function byKey(int $tenant, int $project, int $environment, string $key): ?array
    {
        return $this->store->one('SELECT id, request_digest FROM deployments WHERE tenant_id=? AND project_id=? AND environment_id=? AND request_key=?',
            [$tenant, $project, $environment, $key]);
    }

    private function validatePromotion(Actor $actor, int $project, array $environment, array $candidate, ?int $sourceId): void
    {
        $stage = $environment['stage'];
        if (!in_array($candidate['state'], ['frozen', 'testing', 'pending_approval', 'approved', 'deployed', 'accepted'], true)
            || !$candidate['quality']['artifact_verified'] || !$candidate['quality']['production_base_current'] || $candidate['quality']['stale_criteria']) {
            throw new Problem(409, 'deployment_candidate_not_ready');
        }
        if ($stage === 'production' && $candidate['track'] === 'hotfix' && $environment['id'] !== $candidate['production_environment_id']) {
            throw new Problem(409, 'hotfix_production_target_mismatch');
        }
        if ($stage === 'production' && !$candidate['approval_valid']) {
            throw new Problem(409, 'deployment_candidate_approval_required');
        }
        if ($stage === 'staging' && $candidate['manifest']['test_environment_id'] !== $environment['id']) {
            throw new Problem(409, 'deployment_staging_target_mismatch');
        }
        if ($stage === 'development' && $sourceId === null) {
            return;
        }
        if ($stage === 'development' || $sourceId === null) {
            throw new Problem(422, 'deployment_promotion_source_invalid');
        }
        $source = $this->row($actor->tenantId, $project, $sourceId);
        if ($source['stage'] !== ($stage === 'production' ? 'staging' : 'development') || $source['status'] !== 'succeeded'
            || $source['candidate_id'] !== $candidate['id'] || $source['artifact_id'] !== $candidate['artifact_id']
            || ($stage === 'production' && $source['environment_id'] !== $candidate['manifest']['test_environment_id'])
            || $this->head($actor->tenantId, $project, $source['environment_id']) !== $sourceId) {
            throw new Problem(409, 'deployment_promotion_source_mismatch');
        }
    }
}
