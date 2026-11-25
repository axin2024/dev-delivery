<?php
declare(strict_types=1);

namespace DevDelivery;

final readonly class ReviewPackages
{
    public function __construct(
        private Store $store,
        private Projects $projects,
        private Accounts $accounts,
        private Audit $audit,
    ) {
    }

    public function create(Actor $actor, int $project, array $input): array
    {
        $deployment = Input::integer($input, 'deployment_id', 1);
        $title = Input::string($input, 'title', 160);
        $select = Input::list($input, 'criterion_ids', 1, 500);
        foreach ($select as $id) {
            if (!is_int($id) || $id < 1) {
                throw new Problem(422, 'invalid_input', ['field' => 'criterion_ids']);
            }
        }
        if (count($select) !== count(array_unique($select))) {
            throw new Problem(422, 'package_duplicate_criterion');
        }

        return $this->store->transaction(function () use ($actor, $project, $deployment, $title, $select): array {
            $this->projects->lockForPlanning($actor, $project);
            $identity = [$actor->tenantId, $project];
            $source = json_decode((new BusinessSignoff($this->store, $this->projects, $this->audit))->download($actor, $project, $deployment),
                true, 32, JSON_THROW_ON_ERROR);
            $visible = array_fill_keys($select, true);
            $criteria = [];
            foreach ($source['criteria'] as $item) {
                if (!isset($visible[$item['criterion_id']])) {
                    continue;
                }
                $criteria[] = [
                    'requirement' => ['code' => $item['requirement']['code'], 'title' => $item['requirement']['title']],
                    'code' => $item['code'],
                    'version' => $item['version'],
                    'title' => $item['title'],
                    'statement' => $item['statement'],
                    'status' => $item['status'],
                    'retest_due_on' => $item['decision']['retest_due_on'],
                ];
            }
            if (count($criteria) !== count($visible)) {
                throw new Problem(422, 'package_criterion_outside_report');
            }
            $manifest = SignoffTrace::encode([
                'version' => 1, 'title' => $title, 'release' => ['code' => $source['candidate']['code'], 'version' => $source['candidate']['revision']],
                'released_at' => $source['deployment'][0]['completed_at'], 'criteria' => $criteria,
            ]);
            if (strlen($manifest) > 8_388_608) {
                throw new Problem(422, 'delivery_report_too_large');
            }
            $digest = hash('sha256', $manifest);
            $id = $this->store->insert('INSERT INTO review_packages (tenant_id, project_id, deployment_id, title, digest, content, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)', [...$identity, $deployment, $title, $digest, $manifest, $actor->accountId]);
            $this->record($actor, $project, $id, 'review_package_created', ['deployment_id' => $deployment, 'criterion_ids' => $select, 'digest' => $digest]);
            return $this->internalView($actor, $project, $id);
        });
    }

    public function setGrant(Actor $actor, int $project, int $package, int $reviewer, array $input, bool $revoke): array
    {
        $expires = $revoke ? null : Input::date($input, 'expires_on');
        return $this->store->transaction(function () use ($actor, $project, $package, $reviewer, $expires, $revoke): array {
            $this->projects->lockForPlanning($actor, $project);
            $this->internalRow($actor, $project, $package);
            if (!$this->accounts->find($actor->tenantId, $reviewer)->has(Actor::EXTERNAL_REVIEWER)) {
                throw new Problem(422, 'external_reviewer_required');
            }
            $identity = [$actor->tenantId, $package, $reviewer];
            $row = $this->grant($identity);
            if ($revoke) {
                if (!$row) {
                    throw new Problem(404, 'review_grant_not_found');
                }
                if (!$row['revoked_at']) {
                    $this->store->execute('UPDATE review_package_grants SET revoked_at=UTC_TIMESTAMP(6)
                        WHERE tenant_id=? AND package_id=? AND reviewer_id=?', $identity);
                    $this->record($actor, $project, $package, 'review_grant_revoked', ['reviewer_id' => $reviewer]);
                }
            } else {
                if ($expires < $this->store->one('SELECT UTC_DATE() AS today')['today']) {
                    throw new Problem(422, 'review_grant_date_in_past');
                }
                if (!$row || $row['revoked_at'] || $row['expires_on'] !== $expires) {
                    $this->store->execute('INSERT INTO review_package_grants (tenant_id, package_id, reviewer_id, granted_by, expires_on)
                        VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE granted_by=?, expires_on=?, granted_at=UTC_TIMESTAMP(6), revoked_at=NULL',
                        [...$identity, $actor->accountId, $expires, $actor->accountId, $expires]);
                    $this->record($actor, $project, $package, 'review_grant_saved', ['reviewer_id' => $reviewer,
                        'expires_on' => $expires, 'previous_expires_on' => $row['expires_on'] ?? null]);
                }
            }
            return $this->grant($identity);
        });
    }

    public function internalListing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        return self::page($this->store->all('SELECT id, title, deployment_id, digest, created_at FROM review_packages
            WHERE tenant_id=? AND project_id=? AND id>? ORDER BY id LIMIT 51', [$actor->tenantId, $project, $after]));
    }

    public function internalView(Actor $actor, int $project, int $package): array
    {
        $this->projects->view($actor, $project);
        $row = $this->internalRow($actor, $project, $package, true);
        $row['content'] = json_decode($this->verified($row), true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function grants(Actor $actor, int $project, int $package, int $after): array
    {
        $this->projects->view($actor, $project);
        $this->internalRow($actor, $project, $package);
        $rows = $this->store->all('SELECT g.reviewer_id AS id, a.name, g.expires_on, g.granted_at, g.revoked_at,
                g.revoked_at IS NULL AND g.expires_on>=UTC_DATE() AS active
            FROM review_package_grants g JOIN accounts a ON (a.tenant_id,a.id)=(g.tenant_id,g.reviewer_id)
            WHERE g.tenant_id=? AND g.package_id=? AND g.reviewer_id>? ORDER BY g.reviewer_id LIMIT 51', [$actor->tenantId, $package, $after]);
        return self::page($rows);
    }

    public function timeline(Actor $actor, int $project, int $package, int $after): array
    {
        $this->projects->view($actor, $project);
        $this->internalRow($actor, $project, $package);
        return $this->audit->timeline($actor, 'review_package', $package, $after);
    }

    public function externalListing(Actor $actor, int $after): array
    {
        $this->requireReviewer($actor);
        return self::page($this->store->all('SELECT p.id, p.title, p.digest, p.created_at, g.expires_on
            FROM review_package_grants g JOIN review_packages p ON (p.tenant_id,p.id)=(g.tenant_id,g.package_id)
            WHERE g.tenant_id=? AND g.reviewer_id=? AND g.package_id>? AND g.revoked_at IS NULL AND g.expires_on>=UTC_DATE()
            ORDER BY g.package_id LIMIT 51', [$actor->tenantId, $actor->accountId, $after]));
    }

    public function externalView(Actor $actor, int $package, bool $download): array|string
    {
        $this->requireReviewer($actor);
        $row = $this->store->one('SELECT p.id, p.title, p.digest, p.created_at, p.content, g.expires_on
            FROM review_package_grants g JOIN review_packages p ON (p.tenant_id,p.id)=(g.tenant_id,g.package_id)
            WHERE g.tenant_id=? AND g.reviewer_id=? AND g.package_id=? AND g.revoked_at IS NULL AND g.expires_on>=UTC_DATE()',
            [$actor->tenantId, $actor->accountId, $package]) ?? throw new Problem(404, 'review_package_not_found');
        $bytes = $this->verified($row);
        if ($download) {
            return $bytes;
        }
        $row['content'] = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }

    private function internalRow(Actor $actor, int $project, int $package, bool $withContent = false): array
    {
        $fields = 'id, title, deployment_id, digest, created_by, created_at' . ($withContent ? ', content' : '');
        return $this->store->one('SELECT ' . $fields . ' FROM review_packages WHERE tenant_id=? AND project_id=? AND id=?',
            [$actor->tenantId, $project, $package])
            ?? throw new Problem(404, 'review_package_not_found');
    }

    private function grant(array $identity): ?array
    {
        return $this->store->one('SELECT reviewer_id, expires_on, granted_at, granted_by, revoked_at FROM review_package_grants
            WHERE tenant_id=? AND package_id=? AND reviewer_id=?', $identity);
    }

    private function requireReviewer(Actor $actor): void
    {
        if (!$actor->has(Actor::EXTERNAL_REVIEWER)) {
            throw new Problem(403, 'external_reviewer_required');
        }
    }

    private function record(Actor $actor, int $project, int $package, string $event, array $details): void
    {
        $this->audit->record($actor, 'review_package', $package, $event, ['project_id' => $project] + $details);
        $this->audit->record($actor, 'project', $project, $event, ['package_id' => $package] + $details);
    }

    private function verified(array $row): string
    {
        if (!hash_equals($row['digest'], hash('sha256', $row['content']))) {
            throw new Problem(409, 'review_package_digest_mismatch');
        }
        return $row['content'];
    }

    private static function page(array $rows): array
    {
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }
}
