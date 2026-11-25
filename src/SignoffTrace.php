<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class SignoffTrace
{
    public function __construct(private Store $store)
    {
    }

    public function build(Actor $actor, int $project, array $target, array $criteria): array
    {
        $identity = [$actor->tenantId, $project];
        $manifest = json_decode($target['manifest'], true, 32, JSON_THROW_ON_ERROR);
        if (hash('sha256', $target['manifest']) !== $target['manifest_digest']) {
            throw new Problem(409, 'signoff_evidence_mismatch');
        }
        $approval = $this->store->one('SELECT a.id, a.gate_digest, a.result_ids, a.approved_by, u.name AS approver_name, a.note, a.approved_at
            FROM candidate_approvals a JOIN accounts u ON (u.tenant_id,u.id)=(a.tenant_id,a.approved_by)
            WHERE a.tenant_id=? AND a.project_id=? AND a.candidate_id=? AND a.gate_digest=? AND a.approved_at<=?
                AND (a.revoked_at IS NULL OR a.revoked_at>?) ORDER BY a.id DESC LIMIT 1',
            [...$identity, $target['candidate_id'], $target['approved_gate_digest'], $target['created_at'], $target['created_at']])
            ?? throw new Problem(409, 'signoff_evidence_mismatch');
        $approval['result_ids'] = json_decode($approval['result_ids'], true, 32, JSON_THROW_ON_ERROR);
        $gateProof = self::encode([$target['manifest_digest'], $approval['result_ids']]);
        $scope = array_map(static fn(array $item): array => ['criterion_id' => $item['criterion_id'], 'revision_id' => $item['revision_id']], $criteria);
        if (hash('sha256', $gateProof) !== $approval['gate_digest'] || $manifest['artifact_id'] !== $target['artifact_id']
            || $manifest['artifact_sha256'] !== $target['artifact_sha256'] || $manifest['baseline_digest'] !== $target['baseline_digest']
            || $manifest['scope'] !== $scope) {
            throw new Problem(409, 'signoff_evidence_mismatch');
        }
        $results = $this->results($identity, $manifest, $approval['result_ids']);
        $work = $this->work($identity, $target['candidate_id'], $manifest);
        $defects = $this->defects($identity, $manifest, $approval['result_ids']);
        foreach ($criteria as &$item) {
            $id = $item['criterion_id'];
            $item['work_items'] = $work[$id] ?? [];
            $item['test_results'] = $results[$id] ?? throw new Problem(409, 'signoff_evidence_mismatch');
            $item['defect_retests'] = $defects[$id] ?? [];
        }
        unset($item);

        return [
            'version' => 1,
            'project_id' => $project,
            'candidate' => ['id' => $target['candidate_id'], 'code' => $target['candidate_code'], 'revision' => $target['candidate_revision'],
                'title' => $target['candidate_title'], 'manifest_digest' => $target['manifest_digest'], 'manifest' => $manifest],
            'baseline' => ['id' => $target['baseline_id'], 'title' => $target['baseline_title'], 'digest' => $target['baseline_digest']],
            'approval' => $approval,
            'deployment' => $this->deploymentPath($identity, $target),
            'criteria' => $criteria,
        ];
    }

    public static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function results(array $identity, array $manifest, array $ids): array
    {
        $rows = $this->store->all("SELECT r.id, r.case_version_id, r.outcome, r.duration_ms, r.summary, v.case_id,
                v.criterion_id, v.criterion_revision_id, v.version, v.title, p.environment_id, p.artifact_id,
                r.plan_id, e.id AS report_id, e.sha256 AS report_sha256, e.attempt, e.format, e.recorded_at
            FROM JSON_TABLE(?, '$[*]' COLUMNS(result_id BIGINT UNSIGNED PATH '$')) ids
            JOIN test_results r ON r.id=ids.result_id
            JOIN test_case_versions v ON (v.tenant_id,v.project_id,v.id)=(r.tenant_id,r.project_id,r.case_version_id)
            JOIN test_reports e ON (e.tenant_id,e.project_id,e.plan_id,e.id)=(r.tenant_id,r.project_id,r.plan_id,r.report_id)
            JOIN test_plans p ON (p.tenant_id,p.project_id,p.id)=(r.tenant_id,r.project_id,r.plan_id)
            WHERE r.tenant_id=? AND r.project_id=? AND r.plan_id=? ORDER BY r.case_version_id",
            [self::encode($ids), ...$identity, $manifest['test_plan_id']]);
        $versions = array_column($manifest['required_cases'], 'criterion_revision_id', 'case_version_id');
        if (count($rows) !== count($versions) || count(array_unique($ids)) !== count($versions)) {
            throw new Problem(409, 'signoff_evidence_mismatch');
        }
        $grouped = [];
        foreach ($rows as $row) {
            if (($versions[$row['case_version_id']] ?? null) !== $row['criterion_revision_id'] || $row['outcome'] !== 'passed'
                || $row['artifact_id'] !== $manifest['artifact_id'] || $row['environment_id'] !== $manifest['test_environment_id']) {
                throw new Problem(409, 'signoff_evidence_mismatch');
            }
            unset($versions[$row['case_version_id']]);
            $grouped[$row['criterion_id']][] = $row;
        }
        return $grouped;
    }

    private function work(array $identity, int $candidate, array $manifest): array
    {
        $rows = $this->store->all('SELECT w.id, w.criterion_id, w.code, w.title, w.team, w.owner_id, w.claimed_by, w.sprint_id
            FROM candidate_scope s JOIN work_items w ON (w.tenant_id,w.project_id,w.criterion_id,w.revision_id)=
                (s.tenant_id,s.project_id,s.criterion_id,s.revision_id)
            WHERE s.tenant_id=? AND s.project_id=? AND s.candidate_id=? ORDER BY w.id LIMIT 12001', [...$identity, $candidate]);
        $links = $this->store->all('SELECT l.id, l.work_item_id, l.repository_id, l.snapshot_id, g.branch, g.commit_sha, l.linked_at
            FROM candidate_scope s JOIN work_items w ON (w.tenant_id,w.project_id,w.criterion_id,w.revision_id)=
                (s.tenant_id,s.project_id,s.criterion_id,s.revision_id)
            JOIN work_git_links l ON (l.tenant_id,l.project_id,l.work_item_id)=(w.tenant_id,w.project_id,w.id)
            JOIN git_snapshots g ON (g.tenant_id,g.project_id,g.repository_id,g.id)=(l.tenant_id,l.project_id,l.repository_id,l.snapshot_id)
            WHERE s.tenant_id=? AND s.project_id=? AND s.candidate_id=? ORDER BY l.id LIMIT 12001', [...$identity, $candidate]);
        if (count($rows) > 12000 || count($links) > 12000) {
            throw new Problem(422, 'delivery_report_too_large');
        }
        $byWork = [];
        foreach ($links as $link) {
            $link['matches_build_commit'] = $link['repository_id'] === $manifest['repository_id'] && $link['commit_sha'] === $manifest['commit_sha'];
            $byWork[$link['work_item_id']][] = $link;
        }
        $byCriterion = [];
        foreach ($rows as $row) {
            $row['git_links'] = $byWork[$row['id']] ?? [];
            $byCriterion[$row['criterion_id']][] = $row;
        }
        return $byCriterion;
    }

    private function defects(array $identity, array $manifest, array $ids): array
    {
        $rows = $this->store->all("SELECT d.id, d.title, d.severity, d.failed_result_id, d.fix_artifact_id, d.retest_result_id, d.closed_by,
                d.updated_at AS closed_at, fv.criterion_revision_id AS original_revision_id, rv.criterion_id,
                f.outcome AS original_outcome, f.plan_id AS original_plan_id, f.report_id AS original_report_id,
                h.sha256 AS original_report_sha256, fp.artifact_id AS original_artifact_id, fp.environment_id AS original_environment_id,
                r.report_id AS retest_report_id, r.plan_id AS retest_plan_id, ids.result_id IS NOT NULL AS used_for_gate
            FROM defects d JOIN test_results r ON (r.tenant_id,r.project_id,r.id)=(d.tenant_id,d.project_id,d.retest_result_id)
            LEFT JOIN JSON_TABLE(?, '$[*]' COLUMNS(result_id BIGINT UNSIGNED PATH '$')) ids ON ids.result_id=r.id
            JOIN test_plans p ON p.id=r.plan_id
            JOIN test_plan_cases scope ON (scope.tenant_id,scope.project_id,scope.case_version_id)=(r.tenant_id,r.project_id,r.case_version_id)
            JOIN test_case_versions rv ON rv.id=r.case_version_id
            JOIN test_results f ON f.id=d.failed_result_id
            JOIN test_case_versions fv ON fv.id=f.case_version_id
            JOIN test_plans fp ON fp.id=f.plan_id
            JOIN test_reports h ON h.id=f.report_id
            WHERE d.tenant_id=? AND d.project_id=? AND d.fix_artifact_id=? AND d.state='closed'
                AND p.environment_id=? AND scope.plan_id=? ORDER BY d.id LIMIT 12001",
            [self::encode($ids), ...$identity, $manifest['artifact_id'], $manifest['test_environment_id'], $manifest['test_plan_id']]);
        if (count($rows) > 12000) {
            throw new Problem(422, 'delivery_report_too_large');
        }
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['criterion_id']][] = $row;
        }
        return $grouped;
    }

    private function deploymentPath(array $identity, array $target): array
    {
        $path = [];
        $id = $target['id'];
        foreach (['production', 'staging', 'development'] as $stage) {
            $leg = $this->store->one('SELECT d.id, d.environment_id, e.code AS environment_code, e.name AS environment_name, e.stage,
                    d.request_key, d.external_run_id, d.promotion_source_id, d.completed_at, r.event_id AS completion_event_id
                FROM deployments d JOIN environments e ON (e.tenant_id,e.project_id,e.id)=(d.tenant_id,d.project_id,d.environment_id)
                JOIN deployment_receipts r ON (r.tenant_id,r.project_id,r.deployment_id,r.external_sequence)=
                    (d.tenant_id,d.project_id,d.id,d.external_sequence)
                WHERE d.tenant_id=? AND d.project_id=? AND d.id=? AND d.candidate_id=? AND d.artifact_id=?
                    AND d.kind=\'promote\' AND d.status=\'succeeded\' AND r.reported_status=\'succeeded\' AND e.stage=?',
                [...$identity, $id, $target['candidate_id'], $target['artifact_id'], $stage])
                ?? throw new Problem(409, 'signoff_evidence_mismatch');
            $path[] = $leg;
            $id = $leg['promotion_source_id'];
        }
        return $path;
    }
}
