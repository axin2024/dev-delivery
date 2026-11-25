<?php
declare(strict_types=1);

namespace DevDelivery;

use JsonException;

final readonly class GitEvents
{
    public function __construct(private Store $store, private Projects $projects, private GitDelivery $git)
    {
    }

    public function credentials(Actor $actor, int $project, int $id): array
    {
        return $this->store->transaction(function () use ($actor, $project, $id): array {
            $this->projects->lockForPlanning($actor, $project);
            $repository = $this->git->repository($actor->tenantId, $project, $id, true);
            return ['path' => '/api/connectors/git/' . $repository['connector_id'],
                'signing_secret' => $this->signingSecret($repository['connector_id'])];
        });
    }

    public function receive(string $connector, string $timestamp, string $signature, string $body): array
    {
        if (!preg_match('/\A[0-9]{10}\z/', $timestamp) || abs(time() - (int) $timestamp) > 300
            || !preg_match('/\Asha256=[0-9a-f]{64}\z/', $signature)) {
            throw new Problem(401, 'connector_signature_invalid');
        }
        $secret = $this->signingSecret($connector);
        if (!hash_equals('sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) {
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
        return $this->store->transaction(function () use ($connector, $input, $body): array {
            $repository = $this->store->one('SELECT id, tenant_id, project_id, provider, provider_repository_id
                FROM repositories WHERE connector_id=? FOR UPDATE', [$connector]);
            if (!$repository) {
                throw new Problem(401, 'connector_signature_invalid');
            }
            return $this->process($repository, $input, hash('sha256', $body), null);
        });
    }

    public function imported(Actor $actor, int $project, int $id, array $input): array
    {
        return $this->store->transaction(function () use ($actor, $project, $id, $input): array {
            $this->projects->lockForPlanning($actor, $project);
            $repository = $this->git->repository($actor->tenantId, $project, $id, true);
            return $this->process($repository, $input, hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)), $actor->accountId);
        });
    }

    public function history(Actor $actor, int $project, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        $repo = $this->git->repository($actor->tenantId, $project, $id, false);
        $rows = $this->store->all('SELECT id, external_id, type, imported_by, payload_digest, receipt, received_at
            FROM git_events WHERE tenant_id=? AND project_id=? AND repository_id=? AND id>? ORDER BY id LIMIT 51',
            [...$this->git->identity($repo), $after]);
        $items = array_slice($rows, 0, 50);
        foreach ($items as &$row) {
            $row['receipt'] = json_decode($row['receipt'], true, 32, JSON_THROW_ON_ERROR);
        }
        unset($row);
        return ['items' => $items, 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    private function process(array $repository, array $input, string $digest, ?int $author): array
    {
        Input::integer($input, 'version', 1, 1);
        if (($input['provider'] ?? null) !== $repository['provider']
            || ($input['provider_repository_id'] ?? null) !== $repository['provider_repository_id']) {
            throw new Problem(422, 'connector_repository_mismatch');
        }
        $event = GitDelivery::externalId($input, 'event_id');
        $type = Input::choice($input, 'type', ['ref.updated', 'build.updated', 'merge.updated']);
        $data = $input['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw new Problem(422, 'invalid_input', ['field' => 'data']);
        }
        $identity = [...$this->git->identity($repository), $event];
        $existing = $this->store->one('SELECT payload_digest, receipt FROM git_events
            WHERE tenant_id=? AND project_id=? AND repository_id=? AND external_id=?', $identity);
        if ($existing) {
            if ($existing['payload_digest'] !== $digest) {
                throw new Problem(409, 'connector_event_content_conflict');
            }
            return json_decode($existing['receipt'], true, 32, JSON_THROW_ON_ERROR);
        }
        $result = match ($type) {
            'ref.updated' => $this->git->advance($repository, $data),
            'build.updated' => $this->build($repository, $data),
            'merge.updated' => $this->merge($repository, $data),
        };
        $receipt = ['event_id' => $event, 'type' => $type, 'repository_id' => $repository['id']] + $result;
        $this->store->execute('INSERT INTO git_events (tenant_id, project_id, repository_id, external_id,
            type, payload_digest, imported_by, receipt) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [...$identity, $type, $digest, $author, json_encode($receipt, JSON_THROW_ON_ERROR)]);
        return $receipt;
    }

    private function build(array $repository, array $input): array
    {
        $snapshot = $this->eventSnapshot($repository, $input);
        $run = GitDelivery::externalId($input, 'run_id');
        $sequence = Input::integer($input, 'run_sequence', 1);
        $status = Input::choice($input, 'status', ['queued', 'running', 'passed', 'failed', 'cancelled']);
        $identity = $this->git->identity($repository);
        $previous = $this->store->one('SELECT id, snapshot_id, run_sequence, status FROM build_runs
            WHERE tenant_id=? AND project_id=? AND repository_id=? AND provider_run_id=?', [...$identity, $run]);
        if ($previous && ($previous['snapshot_id'] !== $snapshot['id'] || $previous['run_sequence'] !== $sequence)) {
            throw new Problem(409, 'build_run_identity_conflict');
        }
        $current = $this->git->current($repository, $snapshot['branch'])['id'] === $snapshot['id'];
        $state = 'applied';
        if (!$previous) {
            if ($this->store->one('SELECT id FROM build_runs WHERE tenant_id=? AND project_id=? AND repository_id=?
                    AND snapshot_id=? AND run_sequence=?', [...$identity, $snapshot['id'], $sequence])) {
                throw new Problem(409, 'build_run_sequence_conflict');
            }
            $id = $this->store->insert('INSERT INTO build_runs
                (tenant_id, project_id, repository_id, snapshot_id, provider_run_id, run_sequence, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [...$identity, $snapshot['id'], $run, $sequence, $status]);
        } else {
            $id = $previous['id'];
            if ($previous['status'] === $status) {
                $state = 'unchanged';
            } elseif (!in_array($previous['status'], ['queued', 'running'], true) || $status === 'queued') {
                $state = 'ignored_older_status';
                $status = $previous['status'];
            } else {
                $this->store->execute('UPDATE build_runs SET status=?, updated_at=UTC_TIMESTAMP(6)
                    WHERE tenant_id=? AND project_id=? AND repository_id=? AND id=?', [$status, ...$identity, $id]);
            }
        }
        return ['state' => $state, 'snapshot_id' => $snapshot['id'], 'commit_sha' => $snapshot['commit_sha'],
            'build_id' => $id, 'run_id' => $run, 'status' => $status, 'current_snapshot' => $current];
    }

    private function merge(array $repository, array $input): array
    {
        $snapshot = $this->eventSnapshot($repository, $input);
        $remote = GitDelivery::externalId($input, 'merge_id');
        $version = Input::integer($input, 'source_version', 1);
        $status = Input::choice($input, 'state', ['open', 'merged', 'closed']);
        $title = Input::string($input, 'title', 160);
        $identity = $this->git->identity($repository);
        $previous = $this->store->one('SELECT id, snapshot_id, source_version, state, title FROM merge_requests
            WHERE tenant_id=? AND project_id=? AND repository_id=? AND remote_id=?', [...$identity, $remote]);
        $result = 'applied';
        if (!$previous) {
            $id = $this->store->insert('INSERT INTO merge_requests
                (tenant_id, project_id, repository_id, snapshot_id, remote_id, source_version, state, title) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [...$identity, $snapshot['id'], $remote, $version, $status, $title]);
        } else {
            $id = $previous['id'];
            if ($version < $previous['source_version']) {
                $result = 'ignored_older_version';
            } elseif ($version === $previous['source_version']) {
                if ($snapshot['id'] !== $previous['snapshot_id'] || $status !== $previous['state'] || $title !== $previous['title']) {
                    throw new Problem(409, 'merge_version_content_conflict');
                }
                $result = 'unchanged';
            } else {
                $this->store->execute('UPDATE merge_requests SET snapshot_id=?, source_version=?, state=?, title=?, updated_at=UTC_TIMESTAMP(6)
                    WHERE tenant_id=? AND project_id=? AND repository_id=? AND id=?',
                    [$snapshot['id'], $version, $status, $title, ...$identity, $id]);
            }
        }
        return ['state' => $result, 'snapshot_id' => $snapshot['id'], 'commit_sha' => $snapshot['commit_sha'],
            'merge_request_id' => $id, 'merge_id' => $remote, 'source_version' => $version, 'reported_state' => $status];
    }

    private function eventSnapshot(array $repository, array $input): array
    {
        $snapshot = $this->git->snapshot($repository, Input::integer($input, 'snapshot_id', 1));
        if ($snapshot['commit_sha'] !== GitDelivery::sha($input, 'commit_sha')) {
            throw new Problem(409, 'connector_snapshot_commit_mismatch');
        }
        return $snapshot;
    }

    private function signingSecret(string $connector): string
    {
        $key = getenv('DELIVERY_CONNECTOR_MASTER_KEY');
        if (!is_string($key) || !preg_match('/\A[0-9a-f]{64}\z/', $key)) {
            throw new Problem(503, 'connector_not_configured');
        }
        return hash_hmac('sha256', 'git:' . $connector, hex2bin($key));
    }
}
