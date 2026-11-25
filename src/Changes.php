<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class Changes
{
    public function __construct(
        private Store $store,
        private Projects $projects,
        private Baselines $baselines,
        private Audit $audit,
    ) {
    }

    public function create(Actor $actor, int $project, array $input): array
    {
        $baseline = Input::integer($input, 'baseline_id', 1);
        $reason = Input::string($input, 'reason', 4_000);
        $proposals = [];
        foreach (Input::list($input, 'criteria', 1, 30) as $item) {
            if (!is_array($item)) {
                throw new Problem(422, 'invalid_input', ['field' => 'criteria']);
            }
            $criterion = Input::integer($item, 'criterion_id', 1);
            if (isset($proposals[$criterion])) {
                throw new Problem(422, 'change_duplicate_criterion', ['criterion_id' => $criterion]);
            }
            $proposals[$criterion] = [
                Input::string($item, 'title', 160),
                Input::string($item, 'statement', 12_000),
            ];
        }

        return $this->store->transaction(function () use ($actor, $project, $baseline, $reason, $proposals): array {
            $this->projects->lockForPlanning($actor, $project);
            $scope = $this->assertCurrentBaseline($actor, $project, $baseline);
            $id = $this->store->insert('INSERT INTO change_requests (tenant_id, project_id, baseline_id, proposed_by, reason)
                VALUES (?, ?, ?, ?, ?)', [$actor->tenantId, $project, $baseline, $actor->accountId, $reason]);
            foreach ($proposals as $criterion => [$title, $statement]) {
                $before = $scope[$criterion] ?? throw new Problem(422, 'change_criterion_outside_baseline', ['criterion_id' => $criterion]);
                if ($before['revision_id'] !== $before['current_revision_id']) {
                    throw new Problem(409, 'change_revision_stale', ['revision_id' => $before['current_revision_id']]);
                }
                if ($before['title'] === $title && $before['statement'] === $statement) {
                    throw new Problem(422, 'criterion_revision_unchanged', ['criterion_id' => $criterion]);
                }
                $this->store->execute('INSERT INTO change_criteria
                    (tenant_id, project_id, change_id, baseline_id, criterion_id, prior_revision_id, title, statement)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$actor->tenantId, $project, $id, $baseline, $criterion, $before['revision_id'], $title, $statement]);
            }
            $this->record($actor, $project, $id, 'change_proposed', ['baseline_id' => $baseline, 'criteria' => array_keys($proposals)]);
            return $this->view($actor, $project, $id);
        });
    }

    public function decide(Actor $actor, int $project, int $id, array $input): array
    {
        $decision = Input::choice($input, 'decision', ['approved', 'rejected']);
        $note = Input::string($input, 'note', 4_000);
        return $this->store->transaction(function () use ($actor, $project, $id, $decision, $note): array {
            $this->projects->lockForReview($actor, $project);
            $change = $this->view($actor, $project, $id);
            if ($change['status'] !== 'pending') {
                if ($change['status'] === $decision && $change['reviewed_by'] === $actor->accountId && $change['decision_note'] === $note) {
                    return $change;
                }
                throw new Problem(409, 'change_already_reviewed', ['status' => $change['status']]);
            }
            if ($change['proposed_by'] === $actor->accountId) {
                throw new Problem(403, 'change_self_review');
            }
            if ($decision === 'approved') {
                $this->assertCurrentBaseline($actor, $project, $change['baseline_id']);
                (new ProductionFence($this->store))->checkCriteria($actor, $project, array_column($change['criteria'], 'criterion_id'));
                foreach ($change['criteria'] as $item) {
                    $revision = $this->baselines->appendReviewedRevision($actor, $project, $item);
                    $this->store->execute('UPDATE change_criteria SET approved_revision_id=?
                        WHERE tenant_id=? AND project_id=? AND change_id=? AND criterion_id=?',
                        [$revision['id'], $actor->tenantId, $project, $id, $item['criterion_id']]);
                }
                foreach (['work_items', 'test_cases'] as $table) {
                    $this->store->execute("UPDATE {$table} i JOIN change_criteria c
                        ON (i.tenant_id,i.project_id,i.criterion_id,i.revision_id)=(c.tenant_id,c.project_id,c.criterion_id,c.prior_revision_id)
                        SET i.review_change_id=? WHERE c.tenant_id=? AND c.project_id=? AND c.change_id=?",
                        [$id, $actor->tenantId, $project, $id]);
                }
            }
            $this->store->execute('UPDATE change_requests SET status=?, reviewed_by=?, reviewed_at=UTC_TIMESTAMP(6),
                decision_note=?, impact_snapshot=? WHERE tenant_id=? AND project_id=? AND id=?',
                [$decision, $actor->accountId, $note, json_encode($change['impact'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    $actor->tenantId, $project, $id]);
            $this->record($actor, $project, $id, 'change_' . $decision, ['baseline_id' => $change['baseline_id']]);
            return $this->view($actor, $project, $id);
        });
    }

    public function listing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        $rows = $this->store->all('SELECT id, baseline_id, status, reason, proposed_by, reviewed_by, created_at
            FROM change_requests WHERE tenant_id=? AND project_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $project, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function timeline(Actor $actor, int $project, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        if (!$this->store->one('SELECT id FROM change_requests WHERE tenant_id=? AND project_id=? AND id=?',
                [$actor->tenantId, $project, $id])) {
            throw new Problem(404, 'change_not_found');
        }
        return $this->audit->timeline($actor, 'change_request', $id, $after);
    }

    public function view(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        $identity = [$actor->tenantId, $project, $id];
        $change = $this->store->one('SELECT id, baseline_id, status, reason, proposed_by, reviewed_by, created_at,
            reviewed_at, decision_note, impact_snapshot FROM change_requests WHERE tenant_id=? AND project_id=? AND id=?', $identity);
        if (!$change) {
            throw new Problem(404, 'change_not_found');
        }
        $change['criteria'] = $this->store->all('SELECT c.criterion_id, c.prior_revision_id, c.approved_revision_id, c.title, c.statement,
                r.title AS previous_title, r.statement AS previous_statement
            FROM change_criteria c JOIN criterion_revisions r ON r.id=c.prior_revision_id
            WHERE c.tenant_id=? AND c.project_id=? AND c.change_id=? ORDER BY c.criterion_id', $identity);
        $change['impact'] = $change['impact_snapshot'] === null ? $this->impact($identity, $change['criteria'])
            : json_decode($change['impact_snapshot'], true, 32, JSON_THROW_ON_ERROR);
        unset($change['impact_snapshot']);
        return $change;
    }

    private function impact(array $identity, array $criteria): array
    {
        $impact = [];
        foreach ($criteria as $item) {
            $impact[$item['criterion_id']] = ['criterion_id' => $item['criterion_id'], 'revision_id' => $item['prior_revision_id'],
                'work_items' => [], 'test_cases' => [], 'signoff_items' => []];
        }
        foreach (['work_items', 'test_cases'] as $table) {
            $rows = $this->store->all("SELECT i.id, i.criterion_id, i.code, i.title FROM change_criteria c JOIN {$table} i
                ON (i.tenant_id,i.project_id,i.criterion_id,i.revision_id)=(c.tenant_id,c.project_id,c.criterion_id,c.prior_revision_id)
                WHERE c.tenant_id=? AND c.project_id=? AND c.change_id=? ORDER BY i.id", $identity);
            foreach ($rows as $row) {
                $impact[$row['criterion_id']][$table][] = $row;
            }
        }
        $signoffs = $this->store->all('SELECT s.id, s.criterion_id, s.deployment_id, r.code, r.title, s.decision
            FROM change_criteria c JOIN business_signoff_decisions s
                ON (s.tenant_id,s.project_id,s.criterion_id,s.revision_id)=(c.tenant_id,c.project_id,c.criterion_id,c.prior_revision_id)
            JOIN deployments d ON (d.tenant_id,d.project_id,d.id)=(s.tenant_id,s.project_id,s.deployment_id)
            JOIN release_candidates r ON (r.tenant_id,r.project_id,r.id)=(d.tenant_id,d.project_id,d.candidate_id)
            WHERE c.tenant_id=? AND c.project_id=? AND c.change_id=? AND s.id=(
                SELECT i.id FROM business_signoff_decisions i WHERE (i.tenant_id,i.project_id,i.deployment_id,i.criterion_id)=
                    (s.tenant_id,s.project_id,s.deployment_id,s.criterion_id) ORDER BY i.id DESC LIMIT 1)
            ORDER BY s.deployment_id, s.id', $identity);
        foreach ($signoffs as $row) {
            $impact[$row['criterion_id']]['signoff_items'][] = $row;
        }
        foreach ($impact as &$item) {
            $item['missing_links'] = array_keys(array_filter(
                array_intersect_key($item, array_flip(['work_items', 'test_cases', 'signoff_items'])),
                static fn(array $links): bool => !$links
            ));
        }
        unset($item);
        return array_values($impact);
    }

    private function assertCurrentBaseline(Actor $actor, int $project, int $baseline): array
    {
        $latest = $this->store->one('SELECT id FROM baselines WHERE tenant_id=? AND project_id=? ORDER BY sequence DESC LIMIT 1',
            [$actor->tenantId, $project]);
        if (($latest['id'] ?? null) !== $baseline) {
            throw new Problem(409, 'change_baseline_stale', ['baseline_id' => $latest['id'] ?? null]);
        }
        $rows = $this->store->all('SELECT b.criterion_id, b.revision_id, r.title, r.statement,
                (SELECT cr.id FROM criterion_revisions cr
                 WHERE (cr.tenant_id,cr.project_id,cr.criterion_id)=(b.tenant_id,b.project_id,b.criterion_id)
                 ORDER BY cr.version DESC LIMIT 1) AS current_revision_id
            FROM baseline_criteria b JOIN criterion_revisions r ON r.id=b.revision_id
            WHERE b.tenant_id=? AND b.project_id=? AND b.baseline_id=?', [$actor->tenantId, $project, $baseline]);
        return array_column($rows, null, 'criterion_id');
    }

    private function record(Actor $actor, int $project, int $id, string $action, array $details): void
    {
        $this->audit->record($actor, 'change_request', $id, $action, ['project_id' => $project] + $details);
        $this->audit->record($actor, 'project', $project, $action, ['change_id' => $id] + $details);
    }
}
