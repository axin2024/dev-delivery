<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class TestEvidence
{
    public function __construct(private Store $store, private Projects $projects, private TestPlans $plans, private Audit $audit)
    {
    }

    public function import(Actor $actor, int $project, int $plan, array $identity, string $xml): array
    {
        $this->plans->target($actor, $project, $plan);
        if (!$actor->has('tester')) {
            throw new Problem(403, 'project_tester_required');
        }
        $rows = JUnit::parse($xml);
        return $this->record($actor, $project, $plan, $identity, 'junit', hash('sha256', $xml), $xml, $rows);
    }

    public function manual(Actor $actor, int $project, int $plan, array $input): array
    {
        $result = ['case_version_id' => Input::integer($input, 'case_version_id', 1),
            'outcome' => Input::choice($input, 'outcome', ['passed', 'failed', 'skipped']),
            'duration_ms' => isset($input['duration_ms']) ? Input::integer($input, 'duration_ms', 0, 604_800_000) : null,
            'summary' => Input::string($input, 'summary', 800)];
        return $this->record($actor, $project, $plan, $input, 'manual', hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)), null, [$result]);
    }

    public function reports(Actor $actor, int $project, int $plan, int $after): array
    {
        $this->plans->target($actor, $project, $plan);
        $rows = $this->store->all('SELECT id, source_key, attempt, format, sha256, matched_count, other_count, recorded_by, recorded_at
            FROM test_reports WHERE tenant_id=? AND project_id=? AND plan_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $project, $plan, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function report(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        $identity = [$actor->tenantId, $project, $id];
        $report = $this->store->one('SELECT id, plan_id, source_key, attempt, format, sha256, matched_count, other_count, recorded_by, recorded_at
            FROM test_reports WHERE tenant_id=? AND project_id=? AND id=?', $identity) ?? throw new Problem(404, 'test_report_not_found');
        $report['results'] = $this->store->all('SELECT r.id, r.case_version_id, v.case_id, v.version, v.title, r.outcome, r.duration_ms, r.summary
            FROM test_results r JOIN test_case_versions v ON v.id=r.case_version_id
            WHERE r.tenant_id=? AND r.project_id=? AND r.report_id=? ORDER BY r.id', $identity);
        return $report;
    }

    public function original(Actor $actor, int $project, int $id): string
    {
        $this->projects->view($actor, $project);
        if (!$actor->has('tester') && !$actor->has('release_manager') && !$actor->has('admin')) {
            throw new Problem(403, 'raw_report_access_required');
        }
        $report = $this->store->one('SELECT original_xml FROM test_reports WHERE tenant_id=? AND project_id=? AND id=?',
            [$actor->tenantId, $project, $id]);
        return $report['original_xml'] ?? throw new Problem(404, 'junit_original_not_found');
    }

    public function result(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        return $this->store->one('SELECT r.id, r.report_id, r.plan_id, r.case_version_id, v.case_id, v.version,
                r.outcome, r.duration_ms, r.summary, re.attempt, p.environment_id, p.artifact_id, a.build_id, a.sha256,
                NOT EXISTS (SELECT 1 FROM test_results nr JOIN test_reports nre ON nre.id=nr.report_id
                    WHERE (nr.tenant_id,nr.project_id,nr.plan_id,nr.case_version_id)=(r.tenant_id,r.project_id,r.plan_id,r.case_version_id)
                    AND nre.attempt>re.attempt) AS latest
            FROM test_results r JOIN test_case_versions v ON v.id=r.case_version_id JOIN test_reports re ON re.id=r.report_id
            JOIN test_plans p ON p.id=r.plan_id JOIN build_artifacts a ON a.id=p.artifact_id
            WHERE r.tenant_id=? AND r.project_id=? AND r.id=?', [$actor->tenantId, $project, $id])
            ?? throw new Problem(404, 'test_result_not_found');
    }

    private function record(Actor $actor, int $project, int $plan, array $input, string $format, string $digest, ?string $xml, array $results): array
    {
        $key = GitDelivery::externalId($input, 'source_key');
        $attempt = Input::integer($input, 'attempt', 1);
        $build = Input::integer($input, 'build_id', 1);
        $environment = Input::integer($input, 'environment_id', 1);
        $artifactSha = Input::sha256($input, 'artifact_sha256');
        return $this->store->transaction(function () use ($actor, $project, $plan, $key, $attempt, $build, $environment, $artifactSha, $format, $digest, $xml, $results): array {
            $this->projects->lockForTesting($actor, $project);
            $target = $this->plans->target($actor, $project, $plan);
            if ($target['build_id'] !== $build || $target['environment_id'] !== $environment || $target['sha256'] !== $artifactSha) {
                throw new Problem(409, 'test_execution_target_mismatch');
            }
            $identity = [$actor->tenantId, $project, $plan];
            $existing = $this->store->one('SELECT id, attempt, format, sha256 FROM test_reports
                WHERE tenant_id=? AND project_id=? AND plan_id=? AND source_key=?', [...$identity, $key]);
            if ($existing) {
                if ($existing['attempt'] !== $attempt || $existing['format'] !== $format || $existing['sha256'] !== $digest) {
                    throw new Problem(409, 'test_report_content_conflict');
                }
                return $this->report($actor, $project, $existing['id']);
            }
            if ($this->store->one('SELECT id FROM test_reports WHERE tenant_id=? AND project_id=? AND plan_id=? AND attempt=?', [...$identity, $attempt])) {
                throw new Problem(409, 'test_attempt_already_recorded');
            }
            (new ProductionFence($this->store))->checkTestPlan($actor, $project, $plan);
            $versions = $this->store->all('SELECT v.id, v.case_id, v.junit_class, v.junit_name FROM test_plan_cases c
                JOIN test_case_versions v ON v.id=c.case_version_id
                WHERE c.tenant_id=? AND c.project_id=? AND c.plan_id=?', $identity);
            $byVersion = array_column($versions, 'case_id', 'id');
            $byKey = [];
            foreach ($versions as $version) {
                $byKey[JUnit::key($version['junit_class'], $version['junit_name'])] = $version['id'];
            }
            $matched = [];
            foreach ($results as $result) {
                $version = $result['case_version_id'] ?? $byKey[$result['key']] ?? null;
                if (!isset($byVersion[$version])) {
                    if ($format === 'manual') {
                        throw new Problem(422, 'test_case_outside_plan');
                    }
                    continue;
                }
                if (isset($matched[$version])) {
                    throw new Problem(422, 'junit_duplicate_mapped_case', ['case_version_id' => $version]);
                }
                $matched[$version] = $result;
            }
            if (!$matched) {
                throw new Problem(422, 'junit_no_mapped_cases');
            }
            $report = $this->store->insert('INSERT INTO test_reports (tenant_id, project_id, plan_id, source_key, attempt, format,
                sha256, original_xml, matched_count, other_count, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$identity, $key, $attempt, $format, $digest, $xml, count($matched), count($results) - count($matched), $actor->accountId]);
            foreach ($matched as $version => $result) {
                $id = $this->store->insert('INSERT INTO test_results
                    (tenant_id, project_id, plan_id, report_id, case_version_id, outcome, duration_ms, summary) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$identity, $report, $version, $result['outcome'], $result['duration_ms'], $result['summary']]);
                $this->audit->record($actor, 'test_case', $byVersion[$version], 'test_result_recorded',
                    ['plan_id' => $plan, 'report_id' => $report, 'result_id' => $id, 'case_version_id' => $version, 'outcome' => $result['outcome']]);
            }
            $this->audit->record($actor, 'test_plan', $plan, 'test_report_recorded', ['report_id' => $report, 'attempt' => $attempt, 'format' => $format]);
            return $this->report($actor, $project, $report);
        });
    }
}
