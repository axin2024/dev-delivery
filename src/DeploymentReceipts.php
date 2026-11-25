<?php
declare(strict_types=1);

namespace DevDelivery;

use JsonException;

final readonly class DeploymentReceipts
{
    public function __construct(private Store $store, private Projects $projects, private BuildTargets $targets,
        private DeploymentQueue $queue, private Audit $audit)
    {
    }

    public function credentials(Actor $actor, int $project, int $environment): array
    {
        return $this->store->transaction(function () use ($actor, $project, $environment): array {
            $this->projects->lockForRelease($actor, $project);
            $this->targets->environment($actor, $project, $environment);
            $identity = [$actor->tenantId, $project, $environment];
            $id = $this->store->one('SELECT connector_id FROM deployment_connectors WHERE tenant_id=? AND project_id=? AND environment_id=?', $identity)['connector_id'] ?? null;
            if ($id === null) {
                $id = bin2hex(random_bytes(16));
                $this->store->execute('INSERT INTO deployment_connectors (tenant_id, project_id, environment_id, connector_id, created_by)
                    VALUES (?, ?, ?, ?, ?)', [...$identity, $id, $actor->accountId]);
                $this->audit->record($actor, 'project', $project, 'deployment_connector_created', ['environment_id' => $environment]);
            }
            return ['path' => '/api/connectors/deployments/' . $id, 'signing_secret' => $this->secret($id)];
        });
    }

    public function receive(string $connector, string $timestamp, string $signature, string $body): array
    {
        if (!preg_match('/\A[0-9]{10}\z/', $timestamp) || abs(time() - (int) $timestamp) > 300
            || !preg_match('/\Asha256=[0-9a-f]{64}\z/', $signature)
            || !hash_equals('sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret($connector)), $signature)) {
            throw new Problem(401, 'connector_signature_invalid');
        }
        try {
            $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Problem(400, 'invalid_json');
        }
        if (!is_array($input) || array_is_list($input)) {
            throw new Problem(400, 'json_object_required');
        }
        $deployment = Input::integer($input, 'deployment_id', 1);
        return $this->store->transaction(function () use ($connector, $deployment, $input, $body): array {
            $target = $this->store->one('SELECT tenant_id, project_id, environment_id FROM deployment_connectors WHERE connector_id=?', [$connector]);
            if (!$target) {
                throw new Problem(401, 'connector_signature_invalid');
            }
            $this->store->one('SELECT id FROM projects WHERE tenant_id=? AND id=? FOR UPDATE', [$target['tenant_id'], $target['project_id']]);
            return $this->process($target['tenant_id'], $target['project_id'], $deployment, $input, hash('sha256', $body), null, $target['environment_id']);
        });
    }

    public function imported(Actor $actor, int $project, int $deployment, array $input): array
    {
        if (Input::integer($input, 'deployment_id', 1) !== $deployment) {
            throw new Problem(409, 'deployment_receipt_target_mismatch');
        }
        return $this->store->transaction(function () use ($actor, $project, $deployment, $input): array {
            $this->projects->lockForRelease($actor, $project);
            return $this->process($actor->tenantId, $project, $deployment, $input,
                hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)), $actor->accountId);
        });
    }

    public function history(Actor $actor, int $project, int $deployment, int $after): array
    {
        $this->queue->view($actor, $project, $deployment);
        $rows = $this->store->all('SELECT id, event_id, payload_digest, external_sequence, reported_status,
                external_run_id, imported_by, response, received_at FROM deployment_receipts
            WHERE tenant_id=? AND project_id=? AND deployment_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $project, $deployment, $after]);
        foreach ($rows as &$row) {
            $row['response'] = json_decode($row['response'], true, 32, JSON_THROW_ON_ERROR);
        }
        unset($row);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    private function process(int $tenant, int $project, int $id, array $input, string $digest, ?int $author, ?int $connectorEnvironment = null): array
    {
        Input::integer($input, 'version', 1, 1);
        $event = GitDelivery::externalId($input, 'event_id');
        $key = GitDelivery::externalId($input, 'request_key');
        $external = GitDelivery::externalId($input, 'external_run_id');
        $sequence = Input::integer($input, 'sequence', 1);
        $environment = Input::integer($input, 'environment_id', 1);
        $sha = Input::sha256($input, 'artifact_sha256');
        $status = Input::choice($input, 'status', ['running', 'succeeded', 'failed', 'cancelled']);
        if ($connectorEnvironment !== null && $connectorEnvironment !== $environment) {
            throw new Problem(403, 'deployment_connector_environment_mismatch');
        }
        $row = $this->queue->row($tenant, $project, $id);
        if ($row['environment_id'] !== $environment || $row['request_key'] !== $key || $row['artifact_sha256'] !== $sha) {
            throw new Problem(409, 'deployment_receipt_target_mismatch');
        }
        $identity = [$tenant, $project, $id];
        $existing = $this->store->one('SELECT payload_digest, response FROM deployment_receipts
            WHERE tenant_id=? AND project_id=? AND deployment_id=? AND event_id=?', [...$identity, $event]);
        if ($existing) {
            if ($existing['payload_digest'] !== $digest) {
                throw new Problem(409, 'deployment_receipt_conflict');
            }
            return json_decode($existing['response'], true, 32, JSON_THROW_ON_ERROR);
        }
        if ($this->store->one('SELECT id FROM deployment_receipts WHERE tenant_id=? AND project_id=? AND deployment_id=? AND external_sequence=?', [...$identity, $sequence])) {
            throw new Problem(409, 'deployment_receipt_sequence_exists');
        }
        if (($row['external_run_id'] !== null && $row['external_run_id'] !== $external) || $this->store->one(
            'SELECT id FROM deployments WHERE tenant_id=? AND project_id=? AND environment_id=? AND external_run_id=? AND id<>?',
            [$tenant, $project, $environment, $external, $id])) {
            throw new Problem(409, 'deployment_external_run_conflict');
        }

        $terminal = in_array($row['status'], ['succeeded', 'failed', 'cancelled'], true);
        $newer = $sequence > $row['external_sequence'];
        if ($terminal && $newer && $row['status'] !== $status) {
            throw new Problem(409, 'deployment_outcome_already_final');
        }
        $applied = !$terminal && $newer;
        if ($applied) {
            $this->apply($tenant, $project, $row, $event, $external, $sequence, $status, $author);
        }
        $response = ['event_id' => $event, 'deployment_id' => $id, 'status' => $applied ? $status : $row['status'], 'applied' => $applied];
        $this->store->execute('INSERT INTO deployment_receipts (tenant_id, project_id, deployment_id, event_id, payload_digest,
            external_sequence, reported_status, external_run_id, imported_by, response) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [...$identity, $event, $digest, $sequence, $status, $external, $author, json_encode($response, JSON_THROW_ON_ERROR)]);
        return $response;
    }

    private function apply(int $tenant, int $project, array $row, string $event, string $external, int $sequence, string $status, ?int $author): void
    {
        $id = $row['id'];
        $environment = $row['environment_id'];
        if ($row['status'] === 'queued' && $status === 'succeeded') {
            throw new Problem(409, 'deployment_start_receipt_required');
        }
        if ($status === 'running' && $row['external_run_id'] === null) {
            $this->verifyDispatch($tenant, $project, $row);
        }
        $completed = $status !== 'running';
        if ($status === 'succeeded') {
            if ($this->queue->head($tenant, $project, $environment) !== $row['previous_head_id']) {
                throw new Problem(409, 'environment_head_changed');
            }
            $this->store->execute('INSERT INTO environment_heads (tenant_id, project_id, environment_id, deployment_id) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE deployment_id=?, updated_at=UTC_TIMESTAMP(6)', [$tenant, $project, $environment, $id, $id]);
            if ($row['stage'] === 'production' && $row['kind'] === 'promote') {
                $candidate = $row['candidate_id'];
                $updated = $this->store->execute("UPDATE release_candidates SET state='deployed', updated_at=UTC_TIMESTAMP(6)
                    WHERE tenant_id=? AND project_id=? AND id=? AND state='approved'", [$tenant, $project, $candidate]);
                if ($updated) {
                    $this->audit->recordFor($tenant, $author, 'candidate', $candidate, 'candidate_deployed', ['project_id' => $project, 'deployment_id' => $id]);
                }
            }
        }
        $this->store->execute('UPDATE deployments SET status=?, external_run_id=?, external_sequence=?, updated_at=UTC_TIMESTAMP(6),
            completed_at=IF(?, UTC_TIMESTAMP(6), NULL) WHERE tenant_id=? AND project_id=? AND id=?',
            [$status, $external, $sequence, $completed, $tenant, $project, $id]);
        $this->queue->record($tenant, $project, $id, $author, 'deployment_' . $status,
            ['event_id' => $event, 'external_run_id' => $external, 'sequence' => $sequence, 'environment_id' => $environment]);
    }

    private function verifyDispatch(int $tenant, int $project, array $row): void
    {
        if ($row['kind'] === 'rollback') {
            return;
        }
        $candidate = $this->store->one('SELECT state, production_environment_id, production_base_id FROM release_candidates
            WHERE tenant_id=? AND project_id=? AND id=?',
            [$tenant, $project, $row['candidate_id']]);
        if ($candidate['state'] === 'superseded' || ($candidate['production_base_id'] !== null
            && $this->queue->head($tenant, $project, $candidate['production_environment_id']) !== $candidate['production_base_id'])) {
            throw new Problem(409, 'deployment_candidate_not_ready');
        }
        if ($row['promotion_source_id'] !== null) {
            $prior = $this->queue->row($tenant, $project, $row['promotion_source_id']);
            if ($this->queue->head($tenant, $project, $prior['environment_id']) !== $prior['id']) {
                throw new Problem(409, 'deployment_promotion_source_mismatch');
            }
        }
    }

    private function secret(string $connector): string
    {
        $key = getenv('DELIVERY_CONNECTOR_MASTER_KEY');
        if (!is_string($key) || !preg_match('/\A[0-9a-f]{64}\z/', $key)) {
            throw new Problem(503, 'connector_not_configured');
        }
        return hash_hmac('sha256', 'deployment:' . $connector, hex2bin($key));
    }
}
