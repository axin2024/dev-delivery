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

    public function retry(Actor $actor, int $project, int $plan, array $input): array
    {
        $this->plans->target($actor, $project, $plan);
        if (!$actor->has('tester')) {
            throw new Problem(403, 'project_tester_required');
        }
        $source = Input::integer($input, 'source_report_id', 1);
        $selected = Input::list($input, 'case_version_ids', 1, 500);
        foreach ($selected as $id) {
            if (!is_int($id) || $id < 1) {
                throw new Problem(422, 'invalid_input', ['field' => 'case_version_ids']);
            }
        }
        if (count(array_unique($selected)) !== count($selected)) {
            throw new Problem(422, 'invalid_input', ['field' => 'case_version_ids']);
        }
        sort($selected, SORT_NUMERIC);
        $encoded = $input['xml_base64'] ?? null;
        $xml = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($xml === false) {
            throw new Problem(422, 'invalid_input', ['field' => 'xml_base64']);
        }
        return $this->record($actor, $project, $plan, $input, 'junit', hash('sha256', $xml), $xml, JUnit::parse($xml), $source, $selected);
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
        $report = $this->store->one('SELECT r.id, r.plan_id, r.source_key, r.attempt, r.format, r.sha256, r.matched_count, r.other_count,
                r.recorded_by, r.recorded_at, retry.source_report_id AS retry_of_report_id
            FROM test_reports r LEFT JOIN test_report_retries retry
                ON (retry.tenant_id,retry.project_id,retry.plan_id,retry.report_id)=(r.tenant_id,r.project_id,r.plan_id,r.id)
            WHERE r.tenant_id=? AND r.project_id=? AND r.id=?', $identity) ?? throw new Problem(404, 'test_report_not_found');
        $report['results'] = $this->store->all('SELECT r.id, r.case_version_id, v.case_id, v.version, v.title, v.junit_class, v.junit_name,
                r.outcome, r.duration_ms, r.summary
            FROM test_results r JOIN test_case_versions v ON v.id=r.case_version_id
            WHERE r.tenant_id=? AND r.project_id=? AND r.report_id=? ORDER BY r.id', $identity);
        $report['unreported_cases'] = $this->store->all('SELECT v.id AS case_version_id, v.case_id, v.version, v.title,
                v.junit_class, v.junit_name
            FROM test_reports re JOIN test_plan_cases c
                ON (c.tenant_id,c.project_id,c.plan_id)=(re.tenant_id,re.project_id,re.plan_id)
            JOIN test_case_versions v ON v.id=c.case_version_id
            LEFT JOIN test_results r ON (r.tenant_id,r.project_id,r.plan_id,r.case_version_id,r.report_id)
                =(c.tenant_id,c.project_id,c.plan_id,c.case_version_id,re.id)
            WHERE re.tenant_id=? AND re.project_id=? AND re.id=? AND r.id IS NULL ORDER BY v.case_id', $identity);
        if ($this->canReadOriginal($actor)) {
            $report['unmatched_cases'] = [];
            if ($report['other_count'] > 0) {
                $keys = [];
                foreach (array_merge($report['results'], $report['unreported_cases']) as $case) {
                    $keys[JUnit::key($case['junit_class'], $case['junit_name'])] = true;
                }
                foreach (JUnit::parse($this->original($actor, $project, $id)) as $index => $row) {
                    if (!isset($keys[$row['key']])) {
                        $report['unmatched_cases'][] = ['row' => $index + 1, 'junit_class' => $row['junit_class'],
                            'junit_name' => $row['junit_name'], 'outcome' => $row['outcome'], 'reason' => 'case_outside_plan'];
                    }
                }
            }
        }
        return $report;
    }

    public function original(Actor $actor, int $project, int $id): string
    {
        $this->projects->view($actor, $project);
        if (!$this->canReadOriginal($actor)) {
            throw new Problem(403, 'raw_report_access_required');
        }
        $report = $this->store->one('SELECT original_xml FROM test_reports WHERE tenant_id=? AND project_id=? AND id=?',
            [$actor->tenantId, $project, $id]);
        return $report['original_xml'] ?? throw new Problem(404, 'junit_original_not_found');
    }

    private function canReadOriginal(Actor $actor): bool
    {
        return $actor->has('tester') || $actor->has('release_manager') || $actor->has('admin');
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

    private function record(Actor $actor, int $project, int $plan, array $input, string $format, string $digest, ?string $xml, array $results,
        ?int $source = null, array $selected = []): array
    {
        $key = GitDelivery::externalId($input, 'source_key');
        $attempt = Input::integer($input, 'attempt', 1);
        $build = Input::integer($input, 'build_id', 1);
        $environment = Input::integer($input, 'environment_id', 1);
        $artifactSha = Input::sha256($input, 'artifact_sha256');
        return $this->store->transaction(function () use ($actor, $project, $plan, $key, $attempt, $build, $environment, $artifactSha,
            $format, $digest, $xml, $results, $source, $selected): array {
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
                $report = $this->report($actor, $project, $existing['id']);
                $recorded = array_column($report['results'], 'case_version_id');
                sort($recorded, SORT_NUMERIC);
                if ($source !== $report['retry_of_report_id'] || ($source !== null && $recorded !== $selected)) {
                    throw new Problem(409, 'test_report_content_conflict');
                }
                return $report;
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
            $unmatched = [];
            foreach ($results as $index => $result) {
                $version = $result['case_version_id'] ?? $byKey[$result['key']] ?? null;
                if (!isset($byVersion[$version])) {
                    if ($format === 'manual') {
                        throw new Problem(422, 'test_case_outside_plan');
                    }
                    $unmatched[] = ['row' => $index + 1, 'junit_class' => $result['junit_class'], 'junit_name' => $result['junit_name']];
                    continue;
                }
                if (isset($matched[$version])) {
                    throw new Problem(422, 'junit_duplicate_mapped_case', ['case_version_id' => $version]);
                }
                $matched[$version] = $result;
            }
            if ($source !== null) {
                $this->validateRetry($identity, $source, $attempt, $selected, $byVersion, array_keys($matched), $unmatched);
            }
            if (!$matched) {
                throw new Problem(422, 'junit_no_mapped_cases', ['unmatched_rows' => $unmatched]);
            }
            $report = $this->store->insert('INSERT INTO test_reports (tenant_id, project_id, plan_id, source_key, attempt, format,
                sha256, original_xml, matched_count, other_count, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$identity, $key, $attempt, $format, $digest, $xml, count($matched), count($results) - count($matched), $actor->accountId]);
            if ($source !== null) {
                $this->store->execute('INSERT INTO test_report_retries (tenant_id, project_id, plan_id, report_id, source_report_id)
                    VALUES (?, ?, ?, ?, ?)', [...$identity, $report, $source]);
            }
            foreach ($matched as $version => $result) {
                $id = $this->store->insert('INSERT INTO test_results
                    (tenant_id, project_id, plan_id, report_id, case_version_id, outcome, duration_ms, summary) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$identity, $report, $version, $result['outcome'], $result['duration_ms'], $result['summary']]);
                $this->audit->record($actor, 'test_case', $byVersion[$version], 'test_result_recorded',
                    ['plan_id' => $plan, 'report_id' => $report, 'result_id' => $id, 'case_version_id' => $version, 'outcome' => $result['outcome']]);
            }
            $details = ['report_id' => $report, 'attempt' => $attempt, 'format' => $format];
            if ($source !== null) {
                $details['retry_of_report_id'] = $source;
                $details['case_version_ids'] = $selected;
            }
            $this->audit->record($actor, 'test_plan', $plan, 'test_report_recorded', $details);
            return $this->report($actor, $project, $report);
        });
    }

    private function validateRetry(array $identity, int $source, int $attempt, array $selected, array $versions, array $matched, array $unmatched): void
    {
        $original = $this->store->one('SELECT attempt FROM test_reports
            WHERE tenant_id=? AND project_id=? AND plan_id=? AND id=?', [...$identity, $source])
            ?? throw new Problem(404, 'test_report_not_found');
        if ($attempt <= $original['attempt']) {
            throw new Problem(422, 'test_retry_attempt_not_newer');
        }
        $passed = array_column($this->store->all("SELECT case_version_id FROM test_results
            WHERE tenant_id=? AND project_id=? AND plan_id=? AND report_id=? AND outcome='passed'", [...$identity, $source]), 'case_version_id');
        $ineligible = array_values(array_diff($selected, array_diff(array_keys($versions), $passed)));
        if ($ineligible) {
            throw new Problem(422, 'test_retry_case_ineligible', ['case_version_ids' => $ineligible]);
        }
        sort($matched, SORT_NUMERIC);
        if ($matched !== $selected || $unmatched) {
            throw new Problem(422, 'test_retry_result_selection_mismatch', ['unmatched_rows' => $unmatched,
                'missing_case_version_ids' => array_values(array_diff($selected, $matched)),
                'extra_case_version_ids' => array_values(array_diff($matched, $selected))]);
        }
    }
}
