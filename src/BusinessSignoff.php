<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class BusinessSignoff
{
    private const MAX_REPORT_BYTES = 8_388_608;
    private const DECISION_FIELDS = 's.id, s.criterion_id, s.revision_id, s.decision, s.note, s.signer_id, s.signer_name,
        s.responsible_id, s.responsible_name, s.retest_due_on, s.decided_at';

    private SignoffTrace $trace;

    public function __construct(private Store $store, private Projects $projects, private Audit $audit)
    {
        $this->trace = new SignoffTrace($store);
    }

    public function listing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        $rows = $this->store->all("SELECT d.id, d.candidate_id, c.code AS candidate_code, c.revision AS candidate_revision,
                e.code AS environment_code, d.completed_at, h.deployment_id AS current_head_id, r.archived_at
            FROM deployments d JOIN environments e ON (e.tenant_id,e.project_id,e.id)=(d.tenant_id,d.project_id,d.environment_id)
            JOIN release_candidates c ON (c.tenant_id,c.project_id,c.id)=(d.tenant_id,d.project_id,d.candidate_id)
            LEFT JOIN environment_heads h ON (h.tenant_id,h.project_id,h.environment_id)=(d.tenant_id,d.project_id,d.environment_id)
            LEFT JOIN delivery_reports r ON (r.tenant_id,r.project_id,r.deployment_id)=(d.tenant_id,d.project_id,d.id)
            WHERE d.tenant_id=? AND d.project_id=? AND d.id>? AND d.kind='promote' AND d.status='succeeded' AND e.stage='production'
            ORDER BY d.id LIMIT 51", [$actor->tenantId, $project, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function view(Actor $actor, int $project, int $deployment): array
    {
        $authority = $this->projects->view($actor, $project);
        $target = $this->target($actor, $project, $deployment);
        return $this->details($actor, $project, $authority, $target);
    }

    public function decide(Actor $actor, int $project, int $deployment, int $criterion, array $input): array
    {
        $revision = Input::integer($input, 'revision_id', 1);
        $expected = isset($input['expected_decision_id']) ? Input::integer($input, 'expected_decision_id', 1) : null;
        $decision = Input::choice($input, 'decision', ['accepted', 'blocked', 'waived']);
        $note = Input::string($input, 'note', 2000);
        $responsible = $decision === 'waived' ? Input::integer($input, 'responsible_id', 1) : null;
        $due = $decision === 'waived' ? Input::date($input, 'retest_due_on') : null;

        return $this->store->transaction(function () use (
            $actor, $project, $deployment, $criterion, $revision, $expected, $decision, $note, $responsible, $due,
        ): array {
            $this->projects->lockForReview($actor, $project);
            $target = $this->target($actor, $project, $deployment);
            $this->requireOpen($target);
            $identity = [$actor->tenantId, $project];
            $scope = $this->store->one('SELECT revision_id FROM candidate_scope WHERE tenant_id=? AND project_id=? AND candidate_id=? AND criterion_id=?',
                [...$identity, $target['candidate_id'], $criterion]);
            if (!$scope || $scope['revision_id'] !== $revision) {
                throw new Problem(422, 'signoff_criterion_outside_release');
            }
            $current = $this->store->one('SELECT id, decision FROM business_signoff_decisions
                WHERE tenant_id=? AND project_id=? AND deployment_id=? AND criterion_id=? ORDER BY id DESC LIMIT 1',
                [...$identity, $deployment, $criterion]);
            if (($current['id'] ?? null) !== $expected) {
                throw new Problem(409, 'signoff_decision_changed', ['decision_id' => $current['id'] ?? null]);
            }
            $responsibleName = null;
            if ($decision === 'waived') {
                if (!in_array($current['decision'] ?? null, ['blocked', 'waived'], true) || $due < $target['today']) {
                    throw new Problem(422, 'signoff_waiver_requires_block_and_due_date');
                }
                $responsibleName = $this->projects->participant($actor, $project, $responsible)->name;
            }
            $id = $this->store->insert('INSERT INTO business_signoff_decisions (tenant_id, project_id, deployment_id, criterion_id,
                revision_id, decision, note, signer_id, signer_name, responsible_id, responsible_name, retest_due_on) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$identity, $deployment, $criterion, $revision, $decision, $note, $actor->accountId, $actor->name, $responsible, $responsibleName, $due]);
            $this->record($actor, $project, $target, 'signoff_' . $decision, ['decision_id' => $id, 'criterion_id' => $criterion,
                'revision_id' => $revision, 'responsible_id' => $responsible, 'retest_due_on' => $due]);
            return $this->store->one('SELECT ' . self::DECISION_FIELDS . ' FROM business_signoff_decisions s WHERE s.id=?', [$id]);
        });
    }

    public function history(Actor $actor, int $project, int $deployment, int $criterion, int $after): array
    {
        $this->projects->view($actor, $project);
        $this->target($actor, $project, $deployment);
        $rows = $this->store->all('SELECT ' . self::DECISION_FIELDS . ' FROM business_signoff_decisions s
            WHERE s.tenant_id=? AND s.project_id=? AND s.deployment_id=? AND s.criterion_id=? AND s.id>? ORDER BY s.id LIMIT 51',
            [$actor->tenantId, $project, $deployment, $criterion, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function timeline(Actor $actor, int $project, int $deployment, int $after): array
    {
        $this->projects->view($actor, $project);
        $this->target($actor, $project, $deployment);
        return $this->audit->timeline($actor, 'signoff', $deployment, $after);
    }

    public function preview(Actor $actor, int $project, int $deployment): array
    {
        $authority = $this->projects->view($actor, $project);
        $target = $this->target($actor, $project, $deployment);
        $details = $this->details($actor, $project, $authority, $target);
        if ($details['report']) {
            return ['digest' => $details['report']['digest'], 'archived' => true, 'content' =>
                json_decode($this->download($actor, $project, $deployment), true, 32, JSON_THROW_ON_ERROR)];
        }
        $content = $this->trace->build($actor, $project, $target, $details['criteria']);
        return ['digest' => hash('sha256', $this->encodeReport($content)), 'archived' => false, 'content' => $content];
    }

    public function archive(Actor $actor, int $project, int $deployment, array $input): array
    {
        $digest = Input::sha256($input, 'digest');
        return $this->store->transaction(function () use ($actor, $project, $deployment, $digest): array {
            $authority = $this->projects->lockForReview($actor, $project);
            $target = $this->target($actor, $project, $deployment);
            $report = $this->report($target);
            if ($report) {
                if (!hash_equals($report['digest'], $digest)) {
                    throw new Problem(409, 'signoff_report_archived');
                }
                return $report;
            }
            $this->requireOpen($target);
            $details = $this->details($actor, $project, $authority, $target);
            if ($details['blocking_criterion_ids']) {
                throw new Problem(409, 'signoff_requires_resolution', ['criterion_ids' => $details['blocking_criterion_ids']]);
            }
            $content = $this->encodeReport($this->trace->build($actor, $project, $target, $details['criteria']));
            $currentDigest = hash('sha256', $content);
            if (!hash_equals($digest, $currentDigest)) {
                throw new Problem(409, 'signoff_preview_changed', ['digest' => $currentDigest]);
            }
            $identity = [$actor->tenantId, $project];
            $this->store->execute('INSERT INTO delivery_reports (tenant_id, project_id, deployment_id, digest, content, archived_by)
                VALUES (?, ?, ?, ?, ?, ?)', [...$identity, $deployment, $currentDigest, $content, $actor->accountId]);
            $this->store->execute("UPDATE release_candidates SET state='accepted', updated_at=UTC_TIMESTAMP(6)
                WHERE tenant_id=? AND project_id=? AND id=? AND state='deployed'", [...$identity, $target['candidate_id']]);
            $this->record($actor, $project, $target, 'delivery_report_archived', ['digest' => $currentDigest]);
            return $this->report($this->target($actor, $project, $deployment));
        });
    }

    public function download(Actor $actor, int $project, int $deployment): string
    {
        $this->projects->view($actor, $project);
        $row = $this->store->one('SELECT digest, content FROM delivery_reports WHERE tenant_id=? AND project_id=? AND deployment_id=?',
            [$actor->tenantId, $project, $deployment]) ?? throw new Problem(404, 'delivery_report_not_found');
        if (!hash_equals($row['digest'], hash('sha256', $row['content']))) {
            throw new Problem(409, 'signoff_evidence_mismatch');
        }
        return $row['content'];
    }

    private function target(Actor $actor, int $project, int $deployment): array
    {
        return $this->store->one("SELECT d.id, d.candidate_id, d.artifact_id, a.sha256 AS artifact_sha256, d.approved_gate_digest,
                d.created_at, d.completed_at, c.code AS candidate_code, c.revision AS candidate_revision, c.title AS candidate_title,
                c.manifest, c.manifest_digest, b.id AS baseline_id, b.title AS baseline_title, b.manifest_digest AS baseline_digest,
                e.code AS environment_code, h.deployment_id AS current_head_id, UTC_DATE() AS today,
                r.digest AS report_digest, r.archived_at, r.archived_by
            FROM deployments d JOIN environments e ON (e.tenant_id,e.project_id,e.id)=(d.tenant_id,d.project_id,d.environment_id)
            JOIN release_candidates c ON (c.tenant_id,c.project_id,c.id)=(d.tenant_id,d.project_id,d.candidate_id)
            JOIN build_artifacts a ON (a.tenant_id,a.project_id,a.id)=(d.tenant_id,d.project_id,d.artifact_id)
            JOIN baselines b ON (b.tenant_id,b.project_id,b.id)=(c.tenant_id,c.project_id,c.baseline_id)
            JOIN environment_heads h ON (h.tenant_id,h.project_id,h.environment_id)=(d.tenant_id,d.project_id,d.environment_id)
            LEFT JOIN delivery_reports r ON (r.tenant_id,r.project_id,r.deployment_id)=(d.tenant_id,d.project_id,d.id)
            WHERE d.tenant_id=? AND d.project_id=? AND d.id=? AND d.kind='promote' AND d.status='succeeded' AND e.stage='production'",
            [$actor->tenantId, $project, $deployment]) ?? throw new Problem(404, 'signoff_deployment_not_found');
    }

    private function details(Actor $actor, int $project, array $authority, array $target): array
    {
        $identity = [$actor->tenantId, $project];
        $rows = $this->store->all('SELECT s.criterion_id, s.revision_id, c.code, v.version, v.title, v.statement, v.digest, b.requirement_snapshot
            FROM candidate_scope s JOIN baseline_criteria b ON (b.tenant_id,b.project_id,b.baseline_id,b.criterion_id,b.revision_id)=
                (s.tenant_id,s.project_id,?,s.criterion_id,s.revision_id)
            JOIN criteria c ON (c.tenant_id,c.project_id,c.id)=(s.tenant_id,s.project_id,s.criterion_id)
            JOIN criterion_revisions v ON (v.tenant_id,v.project_id,v.criterion_id,v.id)=(s.tenant_id,s.project_id,s.criterion_id,s.revision_id)
            WHERE s.tenant_id=? AND s.project_id=? AND s.candidate_id=? ORDER BY s.criterion_id',
            [$target['baseline_id'], ...$identity, $target['candidate_id']]);
        if (!$rows) {
            throw new Problem(409, 'signoff_evidence_mismatch');
        }
        $decisions = array_column($this->store->all('SELECT ' . self::DECISION_FIELDS . ' FROM business_signoff_decisions s
            JOIN (SELECT MAX(id) AS id FROM business_signoff_decisions WHERE tenant_id=? AND project_id=? AND deployment_id=? GROUP BY criterion_id) latest
                ON latest.id=s.id', [...$identity, $target['id']]), null, 'criterion_id');
        $blocking = [];
        foreach ($rows as &$row) {
            $id = $row['criterion_id'];
            $decision = $decisions[$id] ?? null;
            $status = $decision['decision'] ?? 'pending';
            if ($status === 'waived' && $decision['retest_due_on'] < $target['today']) {
                $status = 'waiver_expired';
            }
            if (!in_array($status, ['accepted', 'waived'], true)) {
                $blocking[] = $id;
            }
            $row['requirement'] = json_decode($row['requirement_snapshot'], true, 32, JSON_THROW_ON_ERROR);
            unset($row['requirement_snapshot']);
            $row['status'] = $status;
            $row['decision'] = $decision;
        }
        unset($row);
        $report = $this->report($target);
        $current = $target['id'] === $target['current_head_id'];
        return ['deployment_id' => $target['id'], 'candidate_id' => $target['candidate_id'], 'candidate_code' => $target['candidate_code'],
            'candidate_revision' => $target['candidate_revision'], 'environment_code' => $target['environment_code'],
            'completed_at' => $target['completed_at'], 'current_head_id' => $target['current_head_id'], 'business_signer_id' => $authority['business_signer_id'],
            'can_decide' => !$report && $current && $actor->accountId === $authority['business_signer_id'],
            'blocking_criterion_ids' => $report ? [] : $blocking, 'report' => $report, 'criteria' => $rows];
    }

    private function encodeReport(array $content): string
    {
        $bytes = SignoffTrace::encode($content);
        if (strlen($bytes) > self::MAX_REPORT_BYTES) {
            throw new Problem(422, 'delivery_report_too_large');
        }
        return $bytes;
    }

    private function report(array $target): ?array
    {
        return $target['report_digest'] ? ['deployment_id' => $target['id'], 'digest' => $target['report_digest'],
            'archived_at' => $target['archived_at'], 'archived_by' => $target['archived_by']] : null;
    }

    private function requireOpen(array $target): void
    {
        if ($target['report_digest']) {
            throw new Problem(409, 'signoff_report_archived');
        }
        if ($target['id'] !== $target['current_head_id']) {
            throw new Problem(409, 'signoff_deployment_no_longer_current', ['current_head_id' => $target['current_head_id']]);
        }
    }

    private function record(Actor $actor, int $project, array $target, string $action, array $details): void
    {
        $context = ['deployment_id' => $target['id'], 'candidate_id' => $target['candidate_id']];
        $this->audit->record($actor, 'signoff', $target['id'], $action, ['project_id' => $project] + $context + $details);
        $this->audit->record($actor, 'project', $project, $action, $context + $details);
        $this->audit->record($actor, 'candidate', $target['candidate_id'], $action, ['project_id' => $project] + $context + $details);
    }
}
