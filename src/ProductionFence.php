<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class ProductionFence
{
    public function __construct(private Store $store)
    {
    }

    public function checkTestPlan(Actor $actor, int $project, int $plan): void
    {
        $deployment = $this->store->one("SELECT d.id FROM deployments d
            JOIN environments e ON (e.tenant_id,e.project_id,e.id)=(d.tenant_id,d.project_id,d.environment_id)
            JOIN release_candidates c ON (c.tenant_id,c.project_id,c.id)=(d.tenant_id,d.project_id,d.candidate_id)
            WHERE d.tenant_id=? AND d.project_id=? AND e.stage='production' AND d.kind='promote' AND d.active_slot=1
                AND c.test_plan_id=? ORDER BY d.id LIMIT 1", [$actor->tenantId, $project, $plan]);
        if ($deployment) {
            throw new Problem(409, 'deployment_uses_test_plan', ['deployment_id' => $deployment['id']]);
        }
    }

    public function checkCriteria(Actor $actor, int $project, array $criteria): void
    {
        $placeholders = implode(',', array_fill(0, count($criteria), '?'));
        $deployment = $this->store->one("SELECT d.id FROM deployments d
            JOIN environments e ON (e.tenant_id,e.project_id,e.id)=(d.tenant_id,d.project_id,d.environment_id)
            JOIN candidate_scope s ON (s.tenant_id,s.project_id,s.candidate_id)=(d.tenant_id,d.project_id,d.candidate_id)
            WHERE d.tenant_id=? AND d.project_id=? AND e.stage='production' AND d.kind='promote' AND d.active_slot=1
                AND s.criterion_id IN ($placeholders) ORDER BY d.id LIMIT 1", [$actor->tenantId, $project, ...$criteria]);
        if ($deployment) {
            throw new Problem(409, 'deployment_uses_criteria', ['deployment_id' => $deployment['id']]);
        }
    }
}
