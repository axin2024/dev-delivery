<?php
declare(strict_types=1);

namespace DevDelivery;

use PDOException;

final readonly class TestPlans
{
    public function __construct(private Store $store, private Projects $projects, private BuildTargets $targets, private Audit $audit)
    {
    }

    public function create(Actor $actor, int $project, array $input): array
    {
        $code = Input::slug($input, 'code');
        $name = Input::string($input, 'name', 160);
        $baseline = Input::integer($input, 'baseline_id', 1);
        $artifact = Input::integer($input, 'artifact_id', 1);
        $environment = Input::integer($input, 'environment_id', 1);
        $versions = Input::list($input, 'case_version_ids', 1, 500);
        foreach ($versions as $version) {
            if (!is_int($version) || $version < 1) {
                throw new Problem(422, 'invalid_input', ['field' => 'case_version_ids']);
            }
        }
        if (count($versions) !== count(array_unique($versions))) {
            throw new Problem(422, 'test_plan_duplicate_case');
        }
        try {
            return $this->store->transaction(function () use ($actor, $project, $code, $name, $baseline, $artifact, $environment, $versions): array {
                $this->projects->lockForTesting($actor, $project);
                $this->targets->artifact($actor, $project, $artifact);
                $this->targets->environment($actor, $project, $environment);
                $identity = [$actor->tenantId, $project];
                if (!$this->store->one('SELECT id FROM baselines WHERE tenant_id=? AND project_id=? AND id=?', [...$identity, $baseline])) {
                    throw new Problem(404, 'baseline_not_found');
                }
                $marks = implode(',', array_fill(0, count($versions), '?'));
                $rows = $this->store->all("SELECT v.id, v.case_id, v.junit_class, v.junit_name, b.revision_id
                    FROM test_case_versions v LEFT JOIN baseline_criteria b
                    ON (b.tenant_id,b.project_id,b.criterion_id,b.revision_id)=(v.tenant_id,v.project_id,v.criterion_id,v.criterion_revision_id)
                        AND b.baseline_id=?
                    WHERE v.tenant_id=? AND v.project_id=? AND v.id IN ($marks)", [$baseline, ...$identity, ...$versions]);
                $invalid = array_diff($versions, array_column($rows, 'id'));
                $cases = [];
                $keys = [];
                foreach ($rows as $row) {
                    if ($row['revision_id'] === null) {
                        $invalid[] = $row['id'];
                    }
                    $key = JUnit::key($row['junit_class'], $row['junit_name']);
                    if (isset($cases[$row['case_id']]) || isset($keys[$key])) {
                        throw new Problem(422, 'test_plan_duplicate_case', ['case_version_id' => $row['id']]);
                    }
                    $cases[$row['case_id']] = true;
                    $keys[$key] = true;
                }
                if ($invalid) {
                    throw new Problem(422, 'test_case_outside_plan_baseline', ['case_version_ids' => array_values($invalid)]);
                }
                $id = $this->store->insert('INSERT INTO test_plans
                    (tenant_id, project_id, baseline_id, artifact_id, environment_id, code, name, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$identity, $baseline, $artifact, $environment, $code, $name, $actor->accountId]);
                foreach ($versions as $version) {
                    $this->store->execute('INSERT INTO test_plan_cases (tenant_id, project_id, plan_id, case_version_id) VALUES (?, ?, ?, ?)',
                        [...$identity, $id, $version]);
                }
                $this->audit->record($actor, 'test_plan', $id, 'test_plan_created',
                    ['baseline_id' => $baseline, 'artifact_id' => $artifact, 'environment_id' => $environment, 'case_version_ids' => $versions]);
                $this->audit->record($actor, 'project', $project, 'test_plan_created', ['test_plan_id' => $id, 'artifact_id' => $artifact]);
                return $this->view($actor, $project, $id);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'test_plan_code_exists');
            }
            throw $error;
        }
    }

    public function listing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        $rows = $this->store->all('SELECT p.id, p.baseline_id, p.artifact_id, p.environment_id, p.code, p.name, p.created_at,
                a.sha256, a.build_id, e.code AS environment_code
            FROM test_plans p JOIN build_artifacts a ON a.id=p.artifact_id JOIN environments e ON e.id=p.environment_id
            WHERE p.tenant_id=? AND p.project_id=? AND p.id>? ORDER BY p.id LIMIT 51', [$actor->tenantId, $project, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function view(Actor $actor, int $project, int $id): array
    {
        $plan = $this->target($actor, $project, $id);
        return $plan + ['gate' => $this->gate($actor, $project, $id)];
    }

    public function assess(Actor $actor, int $project, int $id, array $expected): array
    {
        $plan = $this->target($actor, $project, $id);
        foreach (['baseline_id', 'artifact_id', 'environment_id'] as $key) {
            if ($plan[$key] !== Input::integer($expected, $key, 1)) {
                throw new Problem(409, 'test_plan_target_mismatch', ['field' => $key]);
            }
        }
        return $this->gate($actor, $project, $id);
    }

    public function target(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        return $this->store->one('SELECT p.id, p.code, p.name, p.baseline_id, p.artifact_id, p.environment_id, p.created_at, p.created_by,
                a.name AS artifact_name, a.sha256, a.build_id, e.code AS environment_code, e.stage
            FROM test_plans p JOIN build_artifacts a ON a.id=p.artifact_id JOIN environments e ON e.id=p.environment_id
            WHERE p.tenant_id=? AND p.project_id=? AND p.id=?', [$actor->tenantId, $project, $id])
            ?? throw new Problem(404, 'test_plan_not_found');
    }

    private function gate(Actor $actor, int $project, int $id): array
    {
        $items = $this->store->all("SELECT v.id AS case_version_id, v.case_id, v.version, v.criterion_revision_id, v.title,
                v.junit_class, v.junit_name, r.id AS result_id, r.report_id, r.summary,
                COALESCE(r.outcome, 'missing') AS outcome
            FROM test_plan_cases c JOIN test_case_versions v ON v.id=c.case_version_id
            LEFT JOIN test_results r ON r.id=(SELECT rr.id FROM test_results rr JOIN test_reports re ON re.id=rr.report_id
                WHERE (rr.tenant_id,rr.project_id,rr.plan_id,rr.case_version_id)=(c.tenant_id,c.project_id,c.plan_id,c.case_version_id)
                ORDER BY re.attempt DESC LIMIT 1)
            WHERE c.tenant_id=? AND c.project_id=? AND c.plan_id=? ORDER BY v.case_id", [$actor->tenantId, $project, $id]);
        $counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0, 'missing' => 0];
        foreach ($items as $item) {
            $counts[$item['outcome']]++;
        }
        return ['ready' => $items !== [] && $counts['passed'] === count($items), 'counts' => $counts, 'cases' => $items];
    }
}
