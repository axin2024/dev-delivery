<?php
declare(strict_types=1);

namespace DevDelivery;

use DateTimeImmutable;
use DateTimeZone;
use PDOException;

final readonly class Milestones
{
    public function __construct(private Store $store, private Projects $projects, private Audit $audit)
    {
    }

    public function create(Actor $actor, int $project, array $input): array
    {
        $code = Input::slug($input, 'code');
        $title = Input::string($input, 'title', 160);
        $baseline = Input::integer($input, 'baseline_id', 1);
        $candidate = isset($input['candidate_id']) ? Input::integer($input, 'candidate_id', 1) : null;
        $environment = Input::integer($input, 'environment_id', 1);
        $owner = Input::integer($input, 'owner_id', 1);
        $due = Input::date($input, 'due_on');
        try {
            return $this->store->transaction(function () use ($actor, $project, $code, $title, $baseline, $candidate, $environment, $owner, $due): array {
                $this->projects->lockForPlanning($actor, $project);
                $identity = [$actor->tenantId, $project];
                $this->projects->assertParticipant($actor, $project, $owner);
                if (!$this->store->one('SELECT id FROM baselines WHERE tenant_id=? AND project_id=? AND id=?', [...$identity, $baseline])) {
                    throw new Problem(404, 'baseline_not_found');
                }
                if (($this->store->one('SELECT stage FROM environments WHERE tenant_id=? AND project_id=? AND id=?',
                    [...$identity, $environment])['stage'] ?? null) !== 'production') {
                    throw new Problem(422, 'milestone_production_environment_required');
                }
                if ($candidate !== null) {
                    $this->verifyCandidate($identity, $candidate, $baseline, $environment);
                }
                $id = $this->store->insert('INSERT INTO milestones (tenant_id, project_id, code, title, baseline_id, candidate_id,
                    environment_id, owner_id, due_on, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$identity, $code, $title, $baseline, $candidate, $environment, $owner, $due, $actor->accountId]);
                $this->record($actor, $project, $id, 'milestone_created', ['due_on' => $due, 'owner_id' => $owner, 'candidate_id' => $candidate]);
                return $this->view($actor, $project, $id);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'milestone_code_exists');
            }
            throw $error;
        }
    }

    public function bind(Actor $actor, int $project, int $id, array $input): array
    {
        $version = Input::integer($input, 'version', 1);
        $candidateSpecified = array_key_exists('candidate_id', $input);
        $candidate = isset($input['candidate_id']) ? Input::integer($input, 'candidate_id', 1) : null;
        $baseline = isset($input['baseline_id']) ? Input::integer($input, 'baseline_id', 1) : null;
        $due = Input::date($input, 'due_on');
        return $this->store->transaction(function () use ($actor, $project, $id, $version, $candidateSpecified, $candidate, $baseline, $due): array {
            $this->projects->lockForPlanning($actor, $project);
            $item = $this->row($actor, $project, $id);
            if ($item['version'] !== $version) {
                throw new Problem(409, 'milestone_changed', ['version' => $item['version']]);
            }
            $baseline ??= $item['baseline_id'];
            if (!$candidateSpecified) {
                $candidate = $item['candidate_id'];
            }
            $targetsChanged = $item['candidate_id'] !== $candidate || $item['baseline_id'] !== $baseline;
            if (!$targetsChanged && $item['due_on'] === $due) {
                return $this->status($item);
            }
            if ($targetsChanged && ($item['production_id'] || in_array($item['deployment_status'], ['queued', 'running', 'unknown'], true))) {
                throw new Problem(409, 'milestone_release_started');
            }
            if ($item['signed_off_at']) {
                throw new Problem(409, 'milestone_already_signed_off');
            }
            $identity = [$actor->tenantId, $project];
            if ($targetsChanged && !$this->store->one('SELECT id FROM baselines WHERE tenant_id=? AND project_id=? AND id=?', [...$identity, $baseline])) {
                throw new Problem(404, 'baseline_not_found');
            }
            if ($targetsChanged && $candidate !== null) {
                $this->verifyCandidate($identity, $candidate, $baseline, $item['environment_id']);
            }
            $this->store->execute('UPDATE milestones SET baseline_id=?, candidate_id=?, due_on=?, version=version+1 WHERE tenant_id=? AND project_id=? AND id=?',
                [$baseline, $candidate, $due, ...$identity, $id]);
            $this->record($actor, $project, $id, 'milestone_replanned',
                ['previous_baseline_id' => $item['baseline_id'], 'baseline_id' => $baseline, 'previous_candidate_id' => $item['candidate_id'],
                    'candidate_id' => $candidate, 'previous_due_on' => $item['due_on'], 'due_on' => $due]);
            return $this->view($actor, $project, $id);
        });
    }

    public function listing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        $rows = $this->store->all($this->query() . ' AND m.id>? ORDER BY m.id LIMIT 51', [$actor->tenantId, $project, $after]);
        return ['items' => array_map($this->status(...), array_slice($rows, 0, 50)), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function view(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        $item = $this->status($this->row($actor, $project, $id));
        if ($item['phase'] === 'waiting_for_signoff') {
            $signoff = (new BusinessSignoff($this->store, $this->projects, $this->audit))->view($actor, $project, $item['production_id']);
            $item['signoff_blocking_criterion_ids'] = $signoff['blocking_criterion_ids'];
            $item['business_signer_id'] = $signoff['business_signer_id'];
        }
        if (in_array($item['phase'], ['testing', 'waiting_for_approval', 'waiting_for_production'], true)) {
            $targets = new BuildTargets($this->store, $this->projects, $this->audit);
            $plans = new TestPlans($this->store, $this->projects, $targets, $this->audit);
            $candidate = (new ReleaseCandidates($this->store, $this->projects, $targets, $plans, $this->audit))->view($actor, $project, $item['candidate_id']);
            $item['quality'] = $candidate['quality'];
            $item['approval_valid'] = $candidate['approval_valid'];
        }
        return $item;
    }

    public function timeline(Actor $actor, int $project, int $id, int $after): array
    {
        $this->view($actor, $project, $id);
        return $this->audit->timeline($actor, 'milestone', $id, $after);
    }

    public function capture(Actor $actor, int $project, int $id): array
    {
        return $this->store->transaction(function () use ($actor, $project, $id): array {
            $this->projects->lockForPlanning($actor, $project);
            $item = $this->view($actor, $project, $id);
            $snapshot = $this->store->insert('INSERT INTO milestone_metric_snapshots (tenant_id, project_id, milestone_id, window_start, window_end, data, captured_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)', [$actor->tenantId, $project, $id, $item['baseline_frozen_at'], $item['observed_at'],
                    SignoffTrace::encode($item), $actor->accountId]);
            $this->record($actor, $project, $id, 'milestone_metrics_captured', ['snapshot_id' => $snapshot, 'phase' => $item['phase']]);
            return ['id' => $snapshot, 'window_start' => $item['baseline_frozen_at'], 'window_end' => $item['observed_at'], 'data' => $item];
        });
    }

    public function snapshots(Actor $actor, int $project, int $id, int $after): array
    {
        $this->view($actor, $project, $id);
        $rows = $this->store->all('SELECT id, window_start, window_end, data, captured_by FROM milestone_metric_snapshots
            WHERE tenant_id=? AND project_id=? AND milestone_id=? AND id>? ORDER BY id LIMIT 51', [$actor->tenantId, $project, $id, $after]);
        $items = array_slice($rows, 0, 50);
        foreach ($items as &$item) {
            $item['data'] = json_decode($item['data'], true, 32, JSON_THROW_ON_ERROR);
        }
        unset($item);
        return ['items' => $items, 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    private function row(Actor $actor, int $project, int $id): array
    {
        return $this->store->one($this->query() . ' AND m.id=?', [$actor->tenantId, $project, $id])
            ?? throw new Problem(404, 'milestone_not_found');
    }

    private function query(): string
    {
        return "SELECT m.id, m.code, m.title, m.due_on, m.version, m.baseline_id, b.frozen_at AS baseline_frozen_at,
                m.owner_id, u.name AS owner_name, m.environment_id, e.code AS environment_code, m.candidate_id,
                c.code AS candidate_code, c.revision AS candidate_revision, c.state AS candidate_state, c.created_at AS candidate_created_at,
                a.id AS deployment_id, a.status AS deployment_status, p.id AS production_id, p.completed_at AS production_at,
                h.deployment_id AS current_head_id, r.archived_at AS signed_off_at,
                UTC_TIMESTAMP(6) AS observed_at,
                (SELECT n.completed_at FROM deployments n WHERE n.tenant_id=m.tenant_id AND n.project_id=m.project_id
                    AND n.environment_id=m.environment_id AND n.previous_head_id=p.id AND n.status='succeeded' ORDER BY n.id LIMIT 1) AS replaced_at,
                (SELECT t.occurred_at FROM audit_events t
                    WHERE t.tenant_id=m.tenant_id AND t.object_type='candidate' AND t.object_id=m.candidate_id AND t.action='candidate_testing'
                    ORDER BY t.id LIMIT 1) AS tests_started_at,
                (SELECT t.occurred_at FROM audit_events t
                    WHERE t.tenant_id=m.tenant_id AND t.object_type='candidate' AND t.object_id=m.candidate_id AND t.action='candidate_submitted'
                    ORDER BY t.id LIMIT 1) AS tests_completed_at,
                (SELECT x.approved_at FROM candidate_approvals x WHERE x.tenant_id=m.tenant_id AND x.project_id=m.project_id AND x.candidate_id=m.candidate_id
                    AND ((p.id IS NULL AND x.revoked_at IS NULL) OR (x.gate_digest=p.approved_gate_digest AND x.approved_at<=p.created_at
                        AND (x.revoked_at IS NULL OR x.revoked_at>p.created_at))) ORDER BY x.id DESC LIMIT 1) AS approved_at
            FROM milestones m JOIN baselines b ON (b.tenant_id,b.project_id,b.id)=(m.tenant_id,m.project_id,m.baseline_id)
            JOIN accounts u ON (u.tenant_id,u.id)=(m.tenant_id,m.owner_id)
            JOIN environments e ON (e.tenant_id,e.project_id,e.id)=(m.tenant_id,m.project_id,m.environment_id)
            LEFT JOIN release_candidates c ON (c.tenant_id,c.project_id,c.id)=(m.tenant_id,m.project_id,m.candidate_id)
            LEFT JOIN deployments a ON a.id=(SELECT da.id FROM deployments da WHERE da.tenant_id=m.tenant_id AND da.project_id=m.project_id
                AND da.candidate_id=m.candidate_id AND da.environment_id=m.environment_id AND da.kind='promote' ORDER BY da.id DESC LIMIT 1)
            LEFT JOIN deployments p ON p.id=(SELECT dp.id FROM deployments dp WHERE dp.tenant_id=m.tenant_id AND dp.project_id=m.project_id
                AND dp.candidate_id=m.candidate_id AND dp.environment_id=m.environment_id AND dp.kind='promote' AND dp.status='succeeded'
                ORDER BY dp.id DESC LIMIT 1)
            LEFT JOIN environment_heads h ON (h.tenant_id,h.project_id,h.environment_id)=(m.tenant_id,m.project_id,m.environment_id)
            LEFT JOIN delivery_reports r ON (r.tenant_id,r.project_id,r.deployment_id)=(m.tenant_id,m.project_id,p.id)
            WHERE m.tenant_id=? AND m.project_id=?";
    }

    private function status(array $row): array
    {
        $activeProduction = in_array($row['deployment_status'], ['queued', 'running', 'unknown'], true);
        $priorProduction = $row['production_id'] !== null && $row['production_id'] !== $row['current_head_id'];
        $phase = match (true) {
            $row['signed_off_at'] !== null => 'accepted',
            $activeProduction && $row['deployment_id'] !== $row['production_id'] => $row['deployment_status'] === 'unknown' ? 'deployment_unknown' : 'deploying',
            $priorProduction => 'previous_production',
            $row['production_id'] !== null => 'waiting_for_signoff',
            in_array($row['deployment_status'], ['failed', 'cancelled'], true) => 'deployment_blocked',
            $row['candidate_state'] === 'superseded' => 'candidate_replaced',
            in_array($row['candidate_state'], ['approved', 'deployed', 'accepted'], true) => 'waiting_for_production',
            $row['candidate_state'] === 'pending_approval' => 'waiting_for_approval',
            $row['tests_started_at'] !== null => 'testing',
            $row['candidate_id'] !== null => 'waiting_for_test',
            default => 'development',
        };
        $closeTime = $row['signed_off_at'] ?? $row['replaced_at'];
        $time = $row['observed_at'];
        $windows = [
            'development' => [$row['baseline_frozen_at'], $row['candidate_created_at']],
            'waiting_for_test' => [$row['candidate_created_at'], $row['tests_started_at']],
            'testing' => [$row['tests_started_at'], $row['tests_completed_at']],
            'waiting_for_approval' => [$row['tests_completed_at'], $row['approved_at']],
            'waiting_for_production' => [$row['approved_at'], $row['production_at']],
            'waiting_for_signoff' => [$row['production_at'], $closeTime],
        ];
        $calendar = new DateTimeZone('UTC');
        $elapsed = [];
        foreach ($windows as $key => [$start, $end]) {
            $milliseconds = $start ? (int) round(((float) (new DateTimeImmutable($end ?? $time, $calendar))->format('U.u')
                - (float) (new DateTimeImmutable($start, $calendar))->format('U.u')) * 1000) : 0;
            $elapsed[$key] = ['start_at' => $start, 'end_at' => $end, 'elapsed_ms' => max(0, $milliseconds)];
        }
        $pastDue = substr($row['signed_off_at'] ?? $time, 0, 10) > $row['due_on'];
        return $row + ['phase' => $phase, 'overdue' => $pastDue && !$row['signed_off_at'], 'signed_off_late' => $pastDue && $row['signed_off_at'] !== null,
            'elapsed' => $elapsed];
    }

    private function verifyCandidate(array $identity, int $id, int $baseline, int $environment): void
    {
        $item = $this->store->one('SELECT baseline_id, state, production_environment_id FROM release_candidates
            WHERE tenant_id=? AND project_id=? AND id=?', [...$identity, $id]);
        if (!$item || $item['baseline_id'] !== $baseline || $item['state'] === 'superseded'
            || ($item['production_environment_id'] !== null && $item['production_environment_id'] !== $environment)) {
            throw new Problem(422, 'milestone_candidate_target_mismatch');
        }
    }

    private function record(Actor $actor, int $project, int $id, string $action, array $details): void
    {
        $this->audit->record($actor, 'milestone', $id, $action, ['project_id' => $project] + $details);
        $this->audit->record($actor, 'project', $project, $action, ['milestone_id' => $id] + $details);
    }
}
