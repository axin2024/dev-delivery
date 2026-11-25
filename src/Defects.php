<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class Defects
{
    public function __construct(private Store $store, private Projects $projects, private BuildTargets $targets,
        private TestEvidence $evidence, private Audit $audit)
    {
    }

    public function open(Actor $actor, int $project, array $input): array
    {
        $failed = Input::integer($input, 'failed_result_id', 1);
        $severity = Input::choice($input, 'severity', ['critical', 'major', 'minor']);
        $title = Input::string($input, 'title', 160);
        $description = Input::string($input, 'description', 12_000);
        return $this->store->transaction(function () use ($actor, $project, $failed, $severity, $title, $description): array {
            $this->projects->lockForTesting($actor, $project);
            $evidence = $this->evidence->result($actor, $project, $failed);
            if ($evidence['outcome'] !== 'failed') {
                throw new Problem(422, 'failed_result_required');
            }
            $identity = [$actor->tenantId, $project, $failed];
            $existing = $this->store->one('SELECT id, severity, title, description FROM defects
                WHERE tenant_id=? AND project_id=? AND failed_result_id=?', $identity);
            if ($existing) {
                if ($existing['severity'] !== $severity || $existing['title'] !== $title || $existing['description'] !== $description) {
                    throw new Problem(409, 'defect_already_exists', ['defect_id' => $existing['id']]);
                }
                return $this->view($actor, $project, $existing['id']);
            }
            $id = $this->store->insert('INSERT INTO defects
                (tenant_id, project_id, failed_result_id, severity, title, description, opened_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [...$identity, $severity, $title, $description, $actor->accountId]);
            $this->record($actor, $project, $id, $evidence['case_id'], 'defect_opened', ['failed_result_id' => $failed]);
            return $this->view($actor, $project, $id);
        });
    }

    public function fix(Actor $actor, int $project, int $id, array $input): array
    {
        $artifactId = Input::integer($input, 'artifact_id', 1);
        $note = Input::string($input, 'note', 4000);
        return $this->store->transaction(function () use ($actor, $project, $id, $artifactId, $note): array {
            $this->projects->lockForEngineering($actor, $project);
            $defect = $this->view($actor, $project, $id);
            if ($defect['state'] === 'closed') {
                throw new Problem(409, 'defect_already_closed');
            }
            $artifact = $this->targets->artifact($actor, $project, $artifactId);
            $failure = $defect['failure'];
            if ($artifact['build_id'] === $failure['build_id'] || $artifact['sha256'] === $failure['sha256']) {
                throw new Problem(422, 'defect_fix_requires_new_build');
            }
            if ($defect['fix_artifact_id'] !== $artifactId || $defect['fixed_by'] !== $actor->accountId) {
                $this->store->execute("UPDATE defects SET state='fixed', fixed_by=?, fix_artifact_id=?, updated_at=UTC_TIMESTAMP(6)
                    WHERE tenant_id=? AND project_id=? AND id=?", [$actor->accountId, $artifactId, $actor->tenantId, $project, $id]);
                $this->record($actor, $project, $id, $failure['case_id'], 'defect_fixed', ['artifact_id' => $artifactId, 'note' => $note]);
            }
            return $this->view($actor, $project, $id);
        });
    }

    public function close(Actor $actor, int $project, int $id, array $input): array
    {
        $retest = Input::integer($input, 'retest_result_id', 1);
        $note = Input::string($input, 'note', 4000);
        return $this->store->transaction(function () use ($actor, $project, $id, $retest, $note): array {
            $this->projects->lockForTesting($actor, $project);
            $defect = $this->view($actor, $project, $id);
            if ($defect['state'] === 'closed' && $defect['retest_result_id'] === $retest && $defect['closed_by'] === $actor->accountId) {
                return $defect;
            }
            if ($defect['state'] !== 'fixed') {
                throw new Problem(409, 'defect_requires_fix');
            }
            if ($defect['fixed_by'] === $actor->accountId) {
                throw new Problem(403, 'separate_defect_reviewer_required');
            }
            $result = $this->evidence->result($actor, $project, $retest);
            $failure = $defect['failure'];
            if ($result['artifact_id'] !== $defect['fix_artifact_id'] || $result['environment_id'] !== $failure['environment_id']
                || $result['case_id'] !== $failure['case_id'] || $result['version'] < $failure['version']
                || $result['outcome'] !== 'passed' || !$result['latest']) {
                throw new Problem(422, 'defect_retest_evidence_mismatch');
            }
            $this->store->execute("UPDATE defects SET state='closed', closed_by=?, retest_result_id=?, updated_at=UTC_TIMESTAMP(6)
                WHERE tenant_id=? AND project_id=? AND id=?", [$actor->accountId, $retest, $actor->tenantId, $project, $id]);
            $this->record($actor, $project, $id, $failure['case_id'], 'defect_closed', ['retest_result_id' => $retest, 'note' => $note]);
            return $this->view($actor, $project, $id);
        });
    }

    public function listing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        $rows = $this->store->all('SELECT id, severity, title, state, failed_result_id, fix_artifact_id, retest_result_id, updated_at
            FROM defects WHERE tenant_id=? AND project_id=? AND id>? ORDER BY id LIMIT 51', [$actor->tenantId, $project, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function view(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        $defect = $this->store->one('SELECT id, failed_result_id, severity, title, description, state, fix_artifact_id, retest_result_id,
                opened_by, fixed_by, closed_by, created_at, updated_at FROM defects WHERE tenant_id=? AND project_id=? AND id=?',
            [$actor->tenantId, $project, $id]) ?? throw new Problem(404, 'defect_not_found');
        $defect['failure'] = $this->evidence->result($actor, $project, $defect['failed_result_id']);
        $defect['retest'] = $defect['retest_result_id'] ? $this->evidence->result($actor, $project, $defect['retest_result_id']) : null;
        return $defect;
    }

    public function timeline(Actor $actor, int $project, int $id, int $after): array
    {
        $this->view($actor, $project, $id);
        return $this->audit->timeline($actor, 'defect', $id, $after);
    }

    private function record(Actor $actor, int $project, int $id, int $case, string $action, array $details): void
    {
        $this->audit->record($actor, 'defect', $id, $action, ['project_id' => $project] + $details);
        $this->audit->record($actor, 'test_case', $case, $action, ['defect_id' => $id] + $details);
    }
}
