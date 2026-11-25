<?php
declare(strict_types=1);

namespace DevDelivery;

use PDOException;

final readonly class Projects
{
    public function __construct(private Store $store, private Accounts $accounts, private Audit $audit)
    {
    }

    public function create(Actor $actor, array $input): array
    {
        if (!$actor->has('admin') && !$actor->has('product')) {
            throw new Problem(403, 'product_required');
        }
        $code = Input::slug($input, 'code');
        $title = Input::string($input, 'title', 160);
        $charter = Input::string($input, 'charter', 12_000);
        $ownerId = Input::integer($input, 'product_owner_id', 1);
        $signerId = Input::integer($input, 'business_signer_id', 1);
        if (!$actor->has('admin') && $ownerId !== $actor->accountId) {
            throw new Problem(403, 'cannot_assign_another_owner');
        }
        $owner = $this->accounts->find($actor->tenantId, $ownerId);
        $signer = $this->accounts->find($actor->tenantId, $signerId);
        if ($ownerId === $signerId || !$owner->has('product') || !$signer->has('business')) {
            throw new Problem(422, 'distinct_product_owner_and_business_signer_required');
        }
        try {
            return $this->store->transaction(function () use ($actor, $code, $title, $charter, $ownerId, $signerId): array {
                $id = $this->store->insert('INSERT INTO projects
                    (tenant_id, code, title, charter, product_owner_id, business_signer_id) VALUES (?, ?, ?, ?, ?, ?)',
                    [$actor->tenantId, $code, $title, $charter, $ownerId, $signerId]);
                $this->audit->record($actor, 'project', $id, 'created', ['version' => 1]);
                return $this->view($actor, $id);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'project_code_exists');
            }
            throw $error;
        }
    }

    public function view(Actor $actor, int $id): array
    {
        return $this->project($actor, $id, false);
    }

    public function listing(Actor $actor, int $after): array
    {
        $rows = $this->store->all('SELECT p.id, p.code, p.title, p.product_owner_id, p.business_signer_id, p.version
            FROM projects p WHERE p.tenant_id=? AND p.id>? AND (?=1 OR p.product_owner_id=? OR p.business_signer_id=?
                OR EXISTS (SELECT 1 FROM project_members m WHERE m.tenant_id=p.tenant_id AND m.project_id=p.id AND m.account_id=?))
            ORDER BY p.id LIMIT 51',
            [$actor->tenantId, $after, $actor->has('admin') ? 1 : 0, $actor->accountId, $actor->accountId, $actor->accountId]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function update(Actor $actor, int $id, array $input): array
    {
        $title = Input::string($input, 'title', 160);
        $charter = Input::string($input, 'charter', 12_000);
        $version = Input::integer($input, 'version', 1);
        return $this->store->transaction(function () use ($actor, $id, $title, $charter, $version): array {
            $project = $this->lockForPlanning($actor, $id);
            if ($project['version'] !== $version) {
                throw new Problem(409, 'project_version_conflict', ['version' => $project['version']]);
            }
            $this->store->execute('UPDATE projects SET title=?, charter=?, version=version+1 WHERE tenant_id=? AND id=?',
                [$title, $charter, $actor->tenantId, $id]);
            $this->audit->record($actor, 'project', $id, 'charter_revised', ['previous_version' => $version, 'version' => $version + 1]);
            return $this->view($actor, $id);
        });
    }

    public function setMember(Actor $actor, int $id, int $member, array $input): array
    {
        $enabled = Input::boolean($input, 'enabled');
        return $this->store->transaction(function () use ($actor, $id, $member, $enabled): array {
            $project = $this->lockForPlanning($actor, $id);
            $this->accounts->find($actor->tenantId, $member);
            if ($member === $project['product_owner_id'] || $member === $project['business_signer_id']) {
                throw new Problem(422, 'member_has_charter_role');
            }
            $identity = [$actor->tenantId, $id, $member];
            $current = $this->store->one('SELECT account_id FROM project_members
                WHERE tenant_id=? AND project_id=? AND account_id=?', $identity) !== null;
            if ($enabled !== $current) {
                $sql = $enabled ? 'INSERT INTO project_members (tenant_id, project_id, account_id) VALUES (?, ?, ?)'
                    : 'DELETE FROM project_members WHERE tenant_id=? AND project_id=? AND account_id=?';
                $this->store->execute($sql, $identity);
                $this->audit->record($actor, 'project', $id, $enabled ? 'member_added' : 'member_removed', ['account_id' => $member]);
            }
            return ['account_id' => $member, 'enabled' => $enabled];
        });
    }

    public function lockForPlanning(Actor $actor, int $id): array
    {
        $project = $this->project($actor, $id, true);
        if (!$actor->has('admin') && $project['product_owner_id'] !== $actor->accountId) {
            throw new Problem(403, 'project_product_owner_required');
        }
        return $project;
    }

    public function lockForReview(Actor $actor, int $id): array
    {
        $project = $this->project($actor, $id, true);
        if ($project['business_signer_id'] !== $actor->accountId) {
            throw new Problem(403, 'project_business_signer_required');
        }
        return $project;
    }

    public function assertParticipant(Actor $actor, int $project, int $account): void
    {
        $this->view($this->accounts->find($actor->tenantId, $account), $project);
    }

    public function timeline(Actor $actor, int $id, int $after): array
    {
        $this->view($actor, $id);
        return $this->audit->timeline($actor, 'project', $id, $after);
    }

    private function project(Actor $actor, int $id, bool $lock): array
    {
        $row = $this->store->one('SELECT id, code, title, charter, product_owner_id, business_signer_id, version
            FROM projects WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$actor->tenantId, $id]);
        if (!$row) {
            throw new Problem(404, 'project_not_found');
        }
        $privileged = $actor->has('admin') || in_array($actor->accountId, [$row['product_owner_id'], $row['business_signer_id']], true);
        if (!$privileged && !$this->store->one('SELECT account_id FROM project_members WHERE tenant_id=? AND project_id=? AND account_id=?',
                [$actor->tenantId, $id, $actor->accountId])) {
            throw new Problem(404, 'project_not_found');
        }
        return $row;
    }
}
