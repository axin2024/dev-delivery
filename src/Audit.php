<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class Audit
{
    public function __construct(private Store $store)
    {
    }

    public function record(Actor $actor, string $type, int $id, string $action, array $details = []): void
    {
        $this->recordFor($actor->tenantId, $actor->accountId, $type, $id, $action, $details);
    }

    public function recordFor(int $tenant, ?int $actor, string $type, int $id, string $action, array $details = []): void
    {
        $this->store->execute(
            'INSERT INTO audit_events (tenant_id, actor_id, object_type, object_id, action, details) VALUES (?, ?, ?, ?, ?, ?)',
            [$tenant, $actor, $type, $id, $action, json_encode((object) $details, JSON_THROW_ON_ERROR)]
        );
    }

    public function timeline(Actor $actor, string $type, int $id, int $after): array
    {
        $rows = $this->store->all('SELECT id, actor_id, action, details, occurred_at FROM audit_events
            WHERE tenant_id=? AND object_type=? AND object_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $type, $id, $after]);
        $items = array_slice($rows, 0, 50);
        foreach ($items as &$event) {
            $event['details'] = json_decode($event['details'], false, 32, JSON_THROW_ON_ERROR);
        }
        unset($event);
        return ['items' => $items, 'next_after' => count($rows) > 50 ? $items[49]['id'] : null];
    }
}
