<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class OwnershipTransfers
{
    public function __construct(private Store $store, private Projects $projects, private Audit $audit)
    {
    }

    public function change(Actor $actor, int $project, string $object, int $id, array $input): array
    {
        $previous = Input::integer($input, 'expected_owner_id', 1);
        $next = Input::integer($input, 'next_owner_id', 1);
        $reason = Input::string($input, 'reason', 2000);
        if ($previous === $next) {
            throw new Problem(422, 'ownership_unchanged');
        }

        return $this->store->transaction(function () use ($actor, $project, $object, $id, $previous, $next, $reason): array {
            $this->projects->lockForPlanning($actor, $project);
            $row = $this->object($actor, $project, $object, $id);
            if ($row['owner_id'] !== $previous) {
                throw new Problem(409, 'ownership_changed', ['owner_id' => $row['owner_id']]);
            }
            $this->projects->assertParticipant($actor, $project, $next);
            $identity = [$actor->tenantId, $project];
            $milestone = $object === 'milestone';
            $update = $milestone
                ? 'UPDATE milestones SET owner_id=?, version=version+1 WHERE tenant_id=? AND project_id=? AND id=?'
                : 'UPDATE work_items SET owner_id=?, claimed_by=NULL, claimed_at=NULL WHERE tenant_id=? AND project_id=? AND id=?';
            $this->store->execute($update, [$next, ...$identity, $id]);
            $transfer = $this->store->insert('INSERT INTO ownership_transfers (tenant_id, project_id, milestone_id, work_item_id,
                previous_owner_id, next_owner_id, previous_claimed_by, previous_claimed_at, changed_by, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$identity, $milestone ? $id : null, $milestone ? null : $id, $previous, $next, $row['claimed_by'] ?? null,
                    $row['claimed_at'] ?? null, $actor->accountId, $reason]);
            $details = ['transfer_id' => $transfer, 'previous_owner_id' => $previous, 'next_owner_id' => $next];
            $this->audit->record($actor, $object, $id, 'ownership_transferred', ['project_id' => $project] + $details);
            $this->audit->record($actor, 'project', $project, 'ownership_transferred', [$object . '_id' => $id] + $details);
            return ['id' => $id, 'owner_id' => $next, 'transfer_id' => $transfer];
        });
    }

    public function history(Actor $actor, int $project, string $object, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        $this->object($actor, $project, $object, $id);
        $column = $object === 'milestone' ? 'milestone_id' : 'work_item_id';
        $rows = $this->store->all("SELECT t.id, t.previous_owner_id, p.name AS previous_owner_name, t.next_owner_id, n.name AS next_owner_name,
                t.previous_claimed_by, t.previous_claimed_at, t.changed_by, t.reason, t.changed_at
            FROM ownership_transfers t JOIN accounts p ON (p.tenant_id,p.id)=(t.tenant_id,t.previous_owner_id)
            JOIN accounts n ON (n.tenant_id,n.id)=(t.tenant_id,t.next_owner_id)
            WHERE t.tenant_id=? AND t.project_id=? AND t.{$column}=? AND t.id>? ORDER BY t.id LIMIT 51",
            [$actor->tenantId, $project, $id, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    private function object(Actor $actor, int $project, string $object, int $id): array
    {
        $query = match ($object) {
            'milestone' => 'SELECT owner_id FROM milestones',
            'work_item' => 'SELECT owner_id, claimed_by, claimed_at FROM work_items',
        };
        return $this->store->one($query . ' WHERE tenant_id=? AND project_id=? AND id=?', [$actor->tenantId, $project, $id])
            ?? throw new Problem(404, $object . '_not_found');
    }
}
