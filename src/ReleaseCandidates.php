<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class ReleaseCandidates
{
    public function __construct(private Store $store, private Projects $projects, private BuildTargets $targets,
        private TestPlans $plans, private Audit $audit)
    {
    }

    public function create(Actor $actor, int $project, array $input, ?int $previous = null): array
    {
        $title = Input::string($input, 'title', 160);
        $code = $previous === null ? Input::slug($input, 'code') : null;
        $baselineId = Input::integer($input, 'baseline_id', 1);
        $artifactId = Input::integer($input, 'artifact_id', 1);
        $planId = Input::integer($input, 'test_plan_id', 1);
        $track = $previous === null ? Input::choice($input + ['track' => 'release'], 'track', ['release', 'hotfix']) : null;
        $production = $track === 'hotfix' ? Input::integer($input, 'production_environment_id', 1) : null;
        $base = $track === 'hotfix' ? Input::integer($input, 'production_base_id', 1) : null;
        return $this->store->transaction(function () use (
            $actor, $project, $previous, $code, $title, $baselineId, $artifactId, $planId, $track, $production, $base,
        ): array {
            $this->projects->lockForRelease($actor, $project);
            $identity = [$actor->tenantId, $project];
            $prior = $previous === null ? null : $this->row($actor, $project, $previous);
            $code ??= $prior['code'];
            if ($prior) {
                $track = $prior['track'];
                $production = $prior['production_environment_id'];
                $base = $prior['production_base_id'];
            }
            $revision = $prior === null ? 1 : $prior['revision'] + 1;
            $existing = $this->store->one('SELECT id, title, baseline_id, artifact_id, test_plan_id, track, production_environment_id, production_base_id FROM release_candidates
                WHERE tenant_id=? AND project_id=? AND code=? AND revision=?', [...$identity, $code, $revision]);
            if ($existing) {
                $existingFields = [$existing['title'], $existing['baseline_id'], $existing['artifact_id'], $existing['test_plan_id'],
                    $existing['track'], $existing['production_environment_id'], $existing['production_base_id']];
                if ($existingFields !== [$title, $baselineId, $artifactId, $planId, $track, $production, $base]) {
                    throw new Problem(409, 'candidate_revision_exists', ['candidate_id' => $existing['id']]);
                }
                return $this->view($actor, $project, $existing['id']);
            }
            if ($prior && in_array($prior['state'], ['superseded', 'deployed', 'accepted'], true)) {
                throw new Problem(409, 'candidate_cannot_be_replaced');
            }
            if ($prior && ($active = $this->store->one('SELECT id FROM deployments
                WHERE tenant_id=? AND project_id=? AND candidate_id=? AND active_slot=1 ORDER BY id LIMIT 1', [...$identity, $previous]))) {
                throw new Problem(409, 'candidate_deployment_active', ['deployment_id' => $active['id']]);
            }
            if ($prior && [$prior['baseline_id'], $prior['artifact_id'], $prior['test_plan_id']] === [$baselineId, $artifactId, $planId]) {
                throw new Problem(422, 'candidate_targets_unchanged');
            }

            $artifact = $this->targets->artifact($actor, $project, $artifactId);
            $productionBase = $track === 'hotfix'
                ? (new HotfixBase($this->store))->fromProduction($actor, $project, $production, $base, $artifact) : null;
            $plan = $this->plans->target($actor, $project, $planId);
            if ($plan['baseline_id'] !== $baselineId || $plan['artifact_id'] !== $artifactId) {
                throw new Problem(409, 'candidate_test_plan_target_mismatch');
            }
            if ($plan['stage'] !== 'staging') {
                throw new Problem(422, 'candidate_requires_staging_plan');
            }
            $baseline = $this->store->one('SELECT manifest_digest FROM baselines WHERE tenant_id=? AND project_id=? AND id=?', [...$identity, $baselineId]);
            $scope = $this->store->all('SELECT criterion_id, revision_id FROM baseline_criteria
                WHERE tenant_id=? AND project_id=? AND baseline_id=? ORDER BY criterion_id', [...$identity, $baselineId]);
            $cases = $this->store->all('SELECT v.case_id, v.id AS case_version_id, v.criterion_id, v.criterion_revision_id
                FROM test_plan_cases p JOIN test_case_versions v ON (v.tenant_id,v.project_id,v.id)=(p.tenant_id,p.project_id,p.case_version_id)
                WHERE p.tenant_id=? AND p.project_id=? AND p.plan_id=? ORDER BY v.case_id', [...$identity, $planId]);
            $missing = array_values(array_diff(array_column($scope, 'revision_id'), array_column($cases, 'criterion_revision_id')));
            if ($missing) {
                throw new Problem(422, 'candidate_coverage_missing', ['criterion_revision_ids' => $missing]);
            }
            $manifest = self::encode([
                'version' => 2, 'code' => $code, 'revision' => $revision, 'previous_id' => $previous, 'track' => $track,
                'production_base' => $productionBase,
                'baseline_id' => $baselineId, 'baseline_digest' => $baseline['manifest_digest'], 'scope' => $scope,
                'artifact_id' => $artifactId, 'artifact_sha256' => $artifact['sha256'], 'build_id' => $artifact['build_id'],
                'provider_run_id' => $artifact['provider_run_id'], 'repository_id' => $artifact['repository_id'],
                'branch' => $artifact['branch'], 'snapshot_id' => $artifact['snapshot_id'], 'commit_sha' => $artifact['commit_sha'],
                'test_environment_id' => $plan['environment_id'], 'test_plan_id' => $planId, 'required_cases' => $cases,
            ]);
            $digest = hash('sha256', $manifest);
            $id = $this->store->insert('INSERT INTO release_candidates (tenant_id, project_id, code, revision, previous_id, title,
                baseline_id, artifact_id, test_plan_id, manifest, manifest_digest, created_by, track, production_environment_id, production_base_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$identity, $code, $revision, $previous, $title, $baselineId, $artifactId, $planId, $manifest, $digest,
                    $actor->accountId, $track, $production, $base]);
            foreach ($scope as $criterion) {
                $this->store->execute('INSERT INTO candidate_scope (tenant_id, project_id, candidate_id, criterion_id, revision_id)
                    VALUES (?, ?, ?, ?, ?)', [...$identity, $id, $criterion['criterion_id'], $criterion['revision_id']]);
            }
            $this->record($actor, $project, $id, 'candidate_drafted', ['manifest_digest' => $digest, 'previous_id' => $previous]);
            if ($prior) {
                $this->revoke($actor, $project, $previous, 'candidate_replaced');
                $this->changeState($actor, $project, $previous, 'superseded', $prior['gate_digest']);
                $this->record($actor, $project, $previous, 'candidate_superseded', ['successor_id' => $id]);
            }
            return $this->view($actor, $project, $id);
        });
    }

    public function advance(Actor $actor, int $project, int $id, string $action, array $input): array
    {
        $expected = Input::sha256($input, 'manifest_digest');
        return $this->store->transaction(function () use ($actor, $project, $id, $action, $expected): array {
            $this->projects->lockForRelease($actor, $project);
            $candidate = $this->row($actor, $project, $id);
            $this->checkManifest($candidate, $expected);
            $state = $candidate['state'];
            if ($action === 'submit') {
                if (!in_array($state, ['testing', 'pending_approval', 'approved'], true)) {
                    throw new Problem(409, 'candidate_state_conflict');
                }
                $gate = $this->requireReady($this->quality($actor, $project, $candidate));
                if ($candidate['gate_digest'] !== $gate['digest']) {
                    $this->revoke($actor, $project, $id, 'candidate_evidence_changed');
                    $this->changeState($actor, $project, $id, 'pending_approval', $gate['digest']);
                    $this->record($actor, $project, $id, 'candidate_submitted', ['gate_digest' => $gate['digest'], 'result_ids' => $gate['result_ids']]);
                }
                return $this->view($actor, $project, $id);
            }
            [$from, $to] = $action === 'freeze' ? ['draft', 'frozen'] : ['frozen', 'testing'];
            if ($state !== $from && $state !== $to) {
                throw new Problem(409, 'candidate_state_conflict');
            }
            $quality = $this->quality($actor, $project, $candidate);
            if (!$quality['artifact_verified'] || !$quality['production_base_current'] || $quality['stale_criteria']) {
                throw new Problem(409, 'candidate_target_not_ready', ['stale_criteria' => $quality['stale_criteria'],
                    'artifact_verified' => $quality['artifact_verified'], 'production_base_current' => $quality['production_base_current']]);
            }
            if ($state !== $to) {
                $this->changeState($actor, $project, $id, $to);
                $this->record($actor, $project, $id, 'candidate_' . $to, ['manifest_digest' => $expected]);
            }
            return $this->view($actor, $project, $id);
        });
    }

    public function approve(Actor $actor, int $project, int $id, array $input): array
    {
        $manifest = Input::sha256($input, 'manifest_digest');
        $expectedGate = Input::sha256($input, 'gate_digest');
        $note = Input::string($input, 'note', 4000);
        $delegationId = isset($input['delegation_id']) ? Input::integer($input, 'delegation_id', 1) : null;
        return $this->store->transaction(function () use ($actor, $project, $id, $manifest, $expectedGate, $note, $delegationId): array {
            $authority = $this->projects->lockForApproval($actor, $project);
            $candidate = $this->row($actor, $project, $id);
            $delegation = $this->approvalDelegation($actor, $project, $id);
            if ($delegationId !== null) {
                $this->requireDelegatedApproval($actor, $authority, $candidate, $delegation, $delegationId, $manifest, $expectedGate);
            } else {
                if (!$actor->has('admin') && $authority['product_owner_id'] !== $actor->accountId) {
                    throw new Problem(403, 'project_product_owner_required');
                }
                if ($authority['product_owner_id'] !== $actor->accountId || $candidate['created_by'] === $actor->accountId) {
                    throw new Problem(403, 'separate_candidate_approver_required');
                }
            }
            $this->checkManifest($candidate, $manifest);
            if (!in_array($candidate['state'], ['pending_approval', 'approved'], true)) {
                throw new Problem(409, 'candidate_state_conflict');
            }
            $gate = $this->requireReady($this->quality($actor, $project, $candidate));
            if ($gate['digest'] !== $expectedGate || $candidate['gate_digest'] !== $expectedGate) {
                throw new Problem(409, 'candidate_gate_changed');
            }
            if ($candidate['state'] === 'pending_approval') {
                $approval = $this->store->insert('INSERT INTO candidate_approvals (tenant_id, project_id, candidate_id, gate_digest, result_ids, approved_by, note)
                    VALUES (?, ?, ?, ?, ?, ?, ?)', [$actor->tenantId, $project, $id, $expectedGate, self::encode($gate['result_ids']), $actor->accountId, $note]);
                $this->changeState($actor, $project, $id, 'approved', $expectedGate);
                $details = ['approval_id' => $approval, 'gate_digest' => $expectedGate];
                if ($delegation !== null && $delegation['closed_at'] === null) {
                    if ($delegationId === null) {
                        $this->closeApprovalDelegation($actor, $project, $id, $delegation['id'], 'approved_directly');
                    } else {
                        $this->closeApprovalDelegation($actor, $project, $id, $delegationId, 'approved', $approval);
                        $details += ['delegation_id' => $delegationId, 'delegated_by' => $delegation['delegated_by']];
                    }
                }
                $this->record($actor, $project, $id, 'candidate_approved', $details);
            }
            return $this->view($actor, $project, $id);
        });
    }

    public function setApprovalDelegation(Actor $actor, int $project, int $id, array $input): array
    {
        if (!array_key_exists('delegate_id', $input) || !array_key_exists('expected_delegation_id', $input)) {
            throw new Problem(422, 'invalid_candidate_delegation');
        }
        $delegate = $input['delegate_id'] === null ? null : Input::integer($input, 'delegate_id', 1);
        $expected = $input['expected_delegation_id'] === null ? null : Input::integer($input, 'expected_delegation_id', 1);
        $manifest = Input::sha256($input, 'manifest_digest');
        $gate = Input::sha256($input, 'gate_digest');
        $reason = Input::string($input, 'reason', 1000);
        return $this->store->transaction(function () use ($actor, $project, $id, $delegate, $expected, $manifest, $gate, $reason): array {
            $authority = $this->projects->lockForPlanning($actor, $project);
            $candidate = $this->row($actor, $project, $id);
            if ($authority['product_owner_id'] !== $actor->accountId || $candidate['created_by'] === $actor->accountId) {
                throw new Problem(403, 'separate_candidate_approver_required');
            }
            $this->checkManifest($candidate, $manifest);
            if ($candidate['state'] !== 'pending_approval') {
                throw new Problem(409, 'candidate_state_conflict');
            }
            $delegation = $this->approvalDelegation($actor, $project, $id);
            if (($delegation['id'] ?? null) !== $expected) {
                throw new Problem(409, 'candidate_delegation_changed');
            }
            $active = $delegation !== null && $delegation['closed_at'] === null;
            if ($delegate === null) {
                if (!$active || $delegation['gate_digest'] !== $gate) {
                    throw new Problem(409, 'candidate_delegation_changed');
                }
                $this->closeApprovalDelegation($actor, $project, $id, $delegation['id'], 'revoked', null, $reason);
                return $this->view($actor, $project, $id);
            }
            $current = $this->requireReady($this->quality($actor, $project, $candidate));
            if ($candidate['gate_digest'] !== $gate || $current['digest'] !== $gate) {
                throw new Problem(409, 'candidate_gate_changed');
            }
            if (in_array($delegate, [$actor->accountId, $candidate['created_by'], $authority['business_signer_id']], true)
                || !$this->projects->participant($actor, $project, $delegate)->has('product')) {
                throw new Problem(422, 'candidate_delegate_ineligible');
            }
            if ($active && $delegation['delegated_to'] === $delegate && $delegation['reason'] === $reason) {
                return $this->view($actor, $project, $id);
            }
            if ($active) {
                $this->closeApprovalDelegation($actor, $project, $id, $delegation['id'], 'replaced');
            }
            $new = $this->store->insert('INSERT INTO candidate_approval_delegations
                (tenant_id, project_id, candidate_id, manifest_digest, gate_digest, delegated_by, delegated_to, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$actor->tenantId, $project, $id, $manifest, $gate, $actor->accountId, $delegate, $reason]);
            $this->record($actor, $project, $id, 'candidate_approval_delegated', ['delegation_id' => $new, 'delegated_to' => $delegate, 'gate_digest' => $gate]);
            return $this->view($actor, $project, $id);
        });
    }

    public function listing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        $rows = $this->store->all('SELECT id, code, revision, title, state, track, manifest_digest, baseline_id, artifact_id, updated_at
            FROM release_candidates WHERE tenant_id=? AND project_id=? AND id>? ORDER BY id LIMIT 51', [$actor->tenantId, $project, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function view(Actor $actor, int $project, int $id): array
    {
        $authority = $this->projects->view($actor, $project);
        $candidate = $this->row($actor, $project, $id);
        $candidate['quality'] = $this->quality($actor, $project, $candidate);
        $candidate['approval_delegation'] = $this->approvalDelegation($actor, $project, $id);
        $candidate['can_delegate_approval'] = $candidate['state'] === 'pending_approval'
            && $authority['product_owner_id'] === $actor->accountId && $candidate['created_by'] !== $actor->accountId;
        $candidate['approval_valid'] = in_array($candidate['state'], ['approved', 'deployed', 'accepted'], true) && $candidate['quality']['ready']
            && $candidate['gate_digest'] === $candidate['quality']['digest'];
        $candidate['successor_id'] = $this->store->one('SELECT id FROM release_candidates WHERE tenant_id=? AND project_id=? AND previous_id=?',
            [$actor->tenantId, $project, $id])['id'] ?? null;
        return $candidate;
    }

    public function approvals(Actor $actor, int $project, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        $this->row($actor, $project, $id);
        $rows = $this->store->all('SELECT a.id, a.gate_digest, a.result_ids, a.approved_by, a.note, a.approved_at, a.revoked_at,
                d.id AS delegation_id, d.delegated_by
            FROM candidate_approvals a LEFT JOIN candidate_approval_delegations d
                ON (d.tenant_id,d.project_id,d.candidate_id,d.approval_id)=(a.tenant_id,a.project_id,a.candidate_id,a.id)
            WHERE a.tenant_id=? AND a.project_id=? AND a.candidate_id=? AND a.id>? ORDER BY a.id LIMIT 51', [$actor->tenantId, $project, $id, $after]);
        foreach ($rows as &$row) {
            $row['result_ids'] = json_decode($row['result_ids'], true, 32, JSON_THROW_ON_ERROR);
        }
        unset($row);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function timeline(Actor $actor, int $project, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        $this->row($actor, $project, $id);
        return $this->audit->timeline($actor, 'candidate', $id, $after);
    }

    private function row(Actor $actor, int $project, int $id): array
    {
        $row = $this->store->one('SELECT id, code, revision, previous_id, title, baseline_id, artifact_id, test_plan_id, manifest,
                manifest_digest, gate_digest, state, created_by, created_at, updated_at, track, production_environment_id, production_base_id
            FROM release_candidates WHERE tenant_id=? AND project_id=? AND id=?', [$actor->tenantId, $project, $id])
            ?? throw new Problem(404, 'candidate_not_found');
        $row['manifest'] = json_decode($row['manifest'], true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }

    private function quality(Actor $actor, int $project, array $candidate): array
    {
        $stale = $this->store->all('SELECT s.criterion_id, s.revision_id FROM candidate_scope s
            WHERE s.tenant_id=? AND s.project_id=? AND s.candidate_id=? AND EXISTS (
                SELECT 1 FROM criterion_revisions r WHERE (r.tenant_id,r.project_id,r.criterion_id)=(s.tenant_id,s.project_id,s.criterion_id)
                AND r.id>s.revision_id) ORDER BY s.criterion_id', [$actor->tenantId, $project, $candidate['id']]);
        $artifact = $this->targets->artifact($actor, $project, $candidate['artifact_id']);
        $manifest = $candidate['manifest'];
        $checks = $this->plans->assess($actor, $project, $candidate['test_plan_id'], [
            'baseline_id' => $candidate['baseline_id'], 'artifact_id' => $candidate['artifact_id'], 'environment_id' => $manifest['test_environment_id'],
        ]);
        $currentBase = $candidate['track'] === 'release' || (new HotfixBase($this->store))->current(
            $actor, $project, $candidate['production_environment_id'], $candidate['production_base_id']);
        $ready = $artifact['byte_length'] !== null && $currentBase && !$stale && $checks['ready'];
        $resultIds = array_column($checks['cases'], 'result_id');
        $digest = $ready ? hash('sha256', self::encode([$candidate['manifest_digest'], $resultIds])) : null;
        return ['ready' => $ready, 'artifact_verified' => $artifact['byte_length'] !== null, 'stale_criteria' => $stale,
            'production_base_current' => $currentBase, 'counts' => $checks['counts'], 'result_ids' => $resultIds, 'digest' => $digest];
    }

    private function requireReady(array $quality): array
    {
        if (!$quality['ready']) {
            throw new Problem(409, 'candidate_gate_blocked', $quality);
        }
        return $quality;
    }

    private function checkManifest(array $candidate, string $expected): void
    {
        if ($candidate['manifest_digest'] !== $expected) {
            throw new Problem(409, 'candidate_manifest_changed');
        }
    }

    private function requireDelegatedApproval(Actor $actor, array $authority, array $candidate, ?array $delegation,
        int $requested, string $manifest, string $gate): void
    {
        if ($delegation === null || $delegation['id'] !== $requested
            || $delegation['manifest_digest'] !== $manifest || $delegation['gate_digest'] !== $gate
            || ($delegation['closed_at'] !== null && $delegation['closed_reason'] !== 'approved')) {
            throw new Problem(409, 'candidate_delegation_changed');
        }
        if ($delegation['delegated_to'] !== $actor->accountId || $delegation['delegated_by'] !== $authority['product_owner_id']
            || $candidate['created_by'] === $actor->accountId || $authority['business_signer_id'] === $actor->accountId || !$actor->has('product')) {
            throw new Problem(403, 'candidate_delegated_approver_required');
        }
    }

    private function approvalDelegation(Actor $actor, int $project, int $id): ?array
    {
        return $this->store->one('SELECT d.id, d.manifest_digest, d.gate_digest, d.delegated_by, d.delegated_to, d.reason,
                d.created_at, d.closed_at, d.closed_reason, d.approval_id, p.name AS delegated_by_name, q.name AS delegated_to_name
            FROM candidate_approval_delegations d
            JOIN accounts p ON (p.tenant_id,p.id)=(d.tenant_id,d.delegated_by)
            JOIN accounts q ON (q.tenant_id,q.id)=(d.tenant_id,d.delegated_to)
            WHERE d.tenant_id=? AND d.project_id=? AND d.candidate_id=? ORDER BY d.id DESC LIMIT 1',
            [$actor->tenantId, $project, $id]);
    }

    private function closeApprovalDelegation(Actor $actor, int $project, int $candidate, int $id, string $outcome,
        ?int $approval = null, ?string $reason = null): void
    {
        $this->store->execute('UPDATE candidate_approval_delegations SET closed_at=UTC_TIMESTAMP(6), closed_reason=?, approval_id=?
            WHERE tenant_id=? AND project_id=? AND candidate_id=? AND id=? AND closed_at IS NULL',
            [$outcome, $approval, $actor->tenantId, $project, $candidate, $id]);
        $this->record($actor, $project, $candidate, 'candidate_approval_delegation_closed', ['delegation_id' => $id, 'outcome' => $outcome, 'reason' => $reason]);
    }

    private function changeState(Actor $actor, int $project, int $id, string $state, ?string $gate = null): void
    {
        $this->store->execute('UPDATE release_candidates SET state=?, gate_digest=?, updated_at=UTC_TIMESTAMP(6)
            WHERE tenant_id=? AND project_id=? AND id=?', [$state, $gate, $actor->tenantId, $project, $id]);
    }

    private function revoke(Actor $actor, int $project, int $id, string $reason): void
    {
        $delegation = $this->approvalDelegation($actor, $project, $id);
        if ($delegation !== null && $delegation['closed_at'] === null) {
            $this->closeApprovalDelegation($actor, $project, $id, $delegation['id'], $reason);
        }
        $count = $this->store->execute('UPDATE candidate_approvals SET revoked_at=UTC_TIMESTAMP(6)
            WHERE tenant_id=? AND project_id=? AND candidate_id=? AND revoked_at IS NULL', [$actor->tenantId, $project, $id]);
        if ($count) {
            $this->record($actor, $project, $id, 'candidate_approval_revoked', ['reason' => $reason]);
        }
    }

    private function record(Actor $actor, int $project, int $id, string $action, array $details): void
    {
        $this->audit->record($actor, 'candidate', $id, $action, ['project_id' => $project] + $details);
        $this->audit->record($actor, 'project', $project, $action, ['candidate_id' => $id] + $details);
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
