<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class HotfixBase
{
    public function __construct(private Store $store)
    {
    }

    public function fromProduction(Actor $actor, int $project, int $environment, int $deployment, array $artifact): array
    {
        $identity = [$actor->tenantId, $project];
        $base = $this->store->one("SELECT d.id AS deployment_id, e.id AS environment_id, d.artifact_id, a.sha256 AS artifact_sha256,
                s.repository_id, s.id AS snapshot_id, s.branch, s.commit_sha
            FROM environment_heads h JOIN environments e ON (e.tenant_id,e.project_id,e.id)=(h.tenant_id,h.project_id,h.environment_id)
            JOIN deployments d ON (d.tenant_id,d.project_id,d.id)=(h.tenant_id,h.project_id,h.deployment_id)
            JOIN build_artifacts a ON (a.tenant_id,a.project_id,a.id)=(d.tenant_id,d.project_id,d.artifact_id)
            JOIN build_runs b ON (b.tenant_id,b.project_id,b.id)=(a.tenant_id,a.project_id,a.build_id)
            JOIN git_snapshots s ON (s.tenant_id,s.project_id,s.repository_id,s.id)=(b.tenant_id,b.project_id,b.repository_id,b.snapshot_id)
            WHERE h.tenant_id=? AND h.project_id=? AND h.environment_id=? AND h.deployment_id=?
                AND e.stage='production' AND d.status='succeeded'", [...$identity, $environment, $deployment]);
        if (!$base) {
            throw new Problem(409, 'hotfix_production_base_changed');
        }
        if ($artifact['repository_id'] !== $base['repository_id'] || $artifact['branch'] === $base['branch'] || $artifact['generation'] < 2
            || $artifact['commit_sha'] === $base['commit_sha']) {
            throw new Problem(422, 'hotfix_requires_branch_from_production');
        }
        $root = $this->store->one('SELECT id, commit_sha FROM git_snapshots WHERE tenant_id=? AND project_id=? AND repository_id=?
            AND branch=? AND generation=1', [...$identity, $artifact['repository_id'], $artifact['branch']]);
        if (($root['commit_sha'] ?? null) !== $base['commit_sha'] || $this->store->one('SELECT id FROM git_snapshots
            WHERE tenant_id=? AND project_id=? AND repository_id=? AND branch=? AND generation<=? AND forced=1 LIMIT 1',
            [...$identity, $artifact['repository_id'], $artifact['branch'], $artifact['generation']])) {
            throw new Problem(422, 'hotfix_requires_branch_from_production');
        }
        return $base + ['fork_snapshot_id' => $root['id']];
    }

    public function current(Actor $actor, int $project, int $environment, int $deployment): bool
    {
        return $this->store->one('SELECT deployment_id FROM environment_heads
            WHERE tenant_id=? AND project_id=? AND environment_id=? AND deployment_id=?', [$actor->tenantId, $project, $environment, $deployment]) !== null;
    }
}
