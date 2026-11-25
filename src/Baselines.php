<?php
declare(strict_types=1);

namespace DevDelivery;

use PDOException;

final readonly class Baselines
{
    public function __construct(private Store $store, private Projects $projects, private Audit $audit)
    {
    }

    public function createRequirement(Actor $actor, int $project, array $input): array
    {
        $code = Input::slug($input, 'code');
        $title = Input::string($input, 'title', 160);
        $summary = Input::string($input, 'summary', 12_000);
        try {
            return $this->store->transaction(function () use ($actor, $project, $code, $title, $summary): array {
                $this->projects->lockForPlanning($actor, $project);
                $id = $this->store->insert('INSERT INTO requirements (tenant_id, project_id, code, title, summary)
                    VALUES (?, ?, ?, ?, ?)', [$actor->tenantId, $project, $code, $title, $summary]);
                $this->audit->record($actor, 'project', $project, 'requirement_added', ['requirement_id' => $id]);
                return ['id' => $id, 'code' => $code, 'title' => $title, 'summary' => $summary];
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'requirement_code_exists');
            }
            throw $error;
        }
    }

    public function requirements(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        $rows = $this->store->all('SELECT id, code, title, summary FROM requirements
            WHERE tenant_id=? AND project_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $project, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function createCriterion(Actor $actor, int $project, int $requirement, array $input): array
    {
        $code = Input::slug($input, 'code');
        [$title, $statement] = $this->criterionFields($input);
        try {
            return $this->store->transaction(function () use ($actor, $project, $requirement, $code, $title, $statement): array {
                $this->projects->lockForPlanning($actor, $project);
                if (!$this->store->one('SELECT id FROM requirements WHERE tenant_id=? AND project_id=? AND id=?',
                        [$actor->tenantId, $project, $requirement])) {
                    throw new Problem(404, 'requirement_not_found');
                }
                $id = $this->store->insert('INSERT INTO criteria (tenant_id, project_id, requirement_id, code)
                    VALUES (?, ?, ?, ?)', [$actor->tenantId, $project, $requirement, $code]);
                $revision = $this->append($actor, $project, $id, 1, $title, $statement);
                return ['id' => $id, 'requirement_id' => $requirement, 'code' => $code, 'revision' => $revision];
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'criterion_code_exists');
            }
            throw $error;
        }
    }

    public function reviseCriterion(Actor $actor, int $project, int $id, array $input): array
    {
        [$title, $statement] = $this->criterionFields($input);
        $version = Input::integer($input, 'version', 1);
        return $this->store->transaction(function () use ($actor, $project, $id, $title, $statement, $version): array {
            $this->projects->lockForPlanning($actor, $project);
            $current = $this->store->one('SELECT id, version, digest FROM criterion_revisions
                WHERE tenant_id=? AND project_id=? AND criterion_id=? ORDER BY version DESC LIMIT 1',
                [$actor->tenantId, $project, $id]);
            if (!$current) {
                throw new Problem(404, 'criterion_not_found');
            }
            $frozen = $this->store->one('SELECT baseline_id FROM baseline_criteria
                WHERE tenant_id=? AND project_id=? AND criterion_id=? ORDER BY baseline_id DESC LIMIT 1',
                [$actor->tenantId, $project, $id]);
            if ($frozen) {
                throw new Problem(409, 'criterion_change_review_required', ['baseline_id' => $frozen['baseline_id']]);
            }
            if ($current['version'] !== $version) {
                throw new Problem(409, 'criterion_version_conflict', ['version' => $current['version']]);
            }
            if ($current['digest'] === $this->digest([$title, $statement])) {
                throw new Problem(422, 'criterion_revision_unchanged');
            }
            return $this->append($actor, $project, $id, $version + 1, $title, $statement, $current['id']);
        });
    }

    public function history(Actor $actor, int $project, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        $criterion = $this->store->one('SELECT id, code, requirement_id FROM criteria
            WHERE tenant_id=? AND project_id=? AND id=?', [$actor->tenantId, $project, $id]);
        if (!$criterion) {
            throw new Problem(404, 'criterion_not_found');
        }
        $rows = $this->store->all('SELECT id, version, title, statement, digest, author_id, created_at
            FROM criterion_revisions WHERE tenant_id=? AND project_id=? AND criterion_id=? AND version>? ORDER BY version LIMIT 51',
            [$actor->tenantId, $project, $id, $after]);
        return $criterion + ['revisions' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['version'] : null];
    }

    public function appendReviewedRevision(Actor $actor, int $project, array $proposal): array
    {
        $criterion = $proposal['criterion_id'];
        $current = $this->store->one('SELECT id, version, digest FROM criterion_revisions
            WHERE tenant_id=? AND project_id=? AND criterion_id=? ORDER BY version DESC LIMIT 1',
            [$actor->tenantId, $project, $criterion]);
        if ($current['id'] !== $proposal['prior_revision_id']) {
            throw new Problem(409, 'change_revision_stale', ['revision_id' => $current['id']]);
        }
        [$title, $statement] = $this->criterionFields($proposal);
        if ($current['digest'] === $this->digest([$title, $statement])) {
            throw new Problem(422, 'criterion_revision_unchanged');
        }
        return $this->append($actor, $project, $criterion, $current['version'] + 1, $title, $statement, $current['id']);
    }

    public function scope(Actor $actor, int $project): array
    {
        return $this->store->transaction(function () use ($actor, $project): array {
            $charter = $this->projects->lockForPlanning($actor, $project);
            return $this->manifest($actor->tenantId, $charter);
        });
    }

    public function freeze(Actor $actor, int $project, array $input): array
    {
        $title = Input::string($input, 'title', 160);
        $digest = Input::string($input, 'scope_digest', 64, 64);
        return $this->store->transaction(function () use ($actor, $project, $title, $digest): array {
            $tenant = $actor->tenantId;
            $charter = $this->projects->lockForPlanning($actor, $project);
            $manifest = $this->manifest($tenant, $charter);
            if (!$manifest['criteria']) {
                throw new Problem(422, 'baseline_requires_criteria');
            }
            if ($manifest['uncovered_requirements']) {
                throw new Problem(422, 'requirements_without_criteria', ['requirements' => $manifest['uncovered_requirements']]);
            }
            if ($manifest['scope_digest'] !== $digest) {
                throw new Problem(409, 'baseline_scope_changed', ['scope_digest' => $manifest['scope_digest']]);
            }
            $previous = $this->store->one('SELECT id, sequence, manifest_digest FROM baselines
                WHERE tenant_id=? AND project_id=? ORDER BY sequence DESC LIMIT 1', [$tenant, $project]);
            if ($previous && $previous['manifest_digest'] === $digest) {
                throw new Problem(409, 'baseline_scope_already_frozen', ['baseline_id' => $previous['id']]);
            }
            $sequence = ($previous['sequence'] ?? 0) + 1;
            $baseline = $this->store->insert('INSERT INTO baselines
                (tenant_id, project_id, sequence, title, charter_version, charter_snapshot, manifest_digest, frozen_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$tenant, $project, $sequence, $title, $charter['version'], $this->json($charter), $digest, $actor->accountId]);
            foreach ($manifest['criteria'] as $item) {
                $this->store->execute('INSERT INTO baseline_criteria
                    (tenant_id, project_id, baseline_id, criterion_id, revision_id, requirement_snapshot) VALUES (?, ?, ?, ?, ?, ?)',
                    [$tenant, $project, $baseline, $item['criterion_id'], $item['revision_id'], $this->json($item['requirement'])]);
            }
            $this->audit->record($actor, 'project', $project, 'baseline_frozen',
                ['baseline_id' => $baseline, 'sequence' => $sequence, 'digest' => $digest, 'criterion_count' => count($manifest['criteria'])]);
            return $this->view($actor, $project, $baseline);
        });
    }

    public function listing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        $rows = $this->store->all('SELECT id, sequence, title, charter_version, manifest_digest, frozen_by, frozen_at
            FROM baselines WHERE tenant_id=? AND project_id=? AND sequence>? ORDER BY sequence LIMIT 51',
            [$actor->tenantId, $project, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['sequence'] : null];
    }

    public function view(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        $baseline = $this->store->one('SELECT id, sequence, title, charter_version, charter_snapshot, manifest_digest, frozen_by, frozen_at
            FROM baselines WHERE tenant_id=? AND project_id=? AND id=?', [$actor->tenantId, $project, $id]);
        if (!$baseline) {
            throw new Problem(404, 'baseline_not_found');
        }
        $baseline['charter_snapshot'] = json_decode($baseline['charter_snapshot'], true, 32, JSON_THROW_ON_ERROR);
        $baseline['criteria'] = $this->store->all('SELECT b.criterion_id, c.code, b.revision_id, r.version, r.title,
                r.statement, r.digest, b.requirement_snapshot
            FROM baseline_criteria b JOIN criterion_revisions r ON (r.tenant_id,r.project_id,r.criterion_id,r.id) =
                (b.tenant_id,b.project_id,b.criterion_id,b.revision_id)
            JOIN criteria c ON (c.tenant_id,c.project_id,c.id)=(b.tenant_id,b.project_id,b.criterion_id)
            WHERE b.tenant_id=? AND b.project_id=? AND b.baseline_id=? ORDER BY b.criterion_id',
            [$actor->tenantId, $project, $id]);
        foreach ($baseline['criteria'] as &$item) {
            $item['requirement'] = json_decode($item['requirement_snapshot'], true, 32, JSON_THROW_ON_ERROR);
            unset($item['requirement_snapshot']);
        }
        unset($item);
        return $baseline;
    }

    private function manifest(int $tenant, array $charter): array
    {
        $criteria = $this->store->all('SELECT c.id AS criterion_id, c.code, r.id AS revision_id, r.version, r.title, r.statement, r.digest,
                q.id AS requirement_id, q.code AS requirement_code, q.title AS requirement_title, q.summary AS requirement_summary
            FROM criteria c JOIN requirements q ON (q.tenant_id,q.project_id,q.id)=(c.tenant_id,c.project_id,c.requirement_id)
            JOIN criterion_revisions r ON r.id=(SELECT cr.id FROM criterion_revisions cr
                WHERE (cr.tenant_id,cr.project_id,cr.criterion_id)=(c.tenant_id,c.project_id,c.id) ORDER BY cr.version DESC LIMIT 1)
            WHERE c.tenant_id=? AND c.project_id=? ORDER BY c.id', [$tenant, $charter['id']]);
        foreach ($criteria as &$item) {
            $item['requirement'] = ['id' => $item['requirement_id'], 'code' => $item['requirement_code'],
                'title' => $item['requirement_title'], 'summary' => $item['requirement_summary']];
            unset($item['requirement_id'], $item['requirement_code'], $item['requirement_title'], $item['requirement_summary']);
        }
        unset($item);
        $uncovered = $this->store->all('SELECT q.id, q.code, q.title FROM requirements q
            WHERE q.tenant_id=? AND q.project_id=? AND NOT EXISTS (SELECT 1 FROM criteria c
                WHERE (c.tenant_id,c.project_id,c.requirement_id)=(q.tenant_id,q.project_id,q.id)) ORDER BY q.id',
            [$tenant, $charter['id']]);
        return ['charter' => $charter, 'criteria' => $criteria, 'uncovered_requirements' => $uncovered,
            'scope_digest' => $this->digest([$charter, $criteria, $uncovered])];
    }

    private function criterionFields(array $input): array
    {
        return [Input::string($input, 'title', 160), Input::string($input, 'statement', 12_000)];
    }

    private function append(Actor $actor, int $project, int $criterion, int $version, string $title, string $statement, ?int $prior = null): array
    {
        $digest = $this->digest([$title, $statement]);
        $id = $this->store->insert('INSERT INTO criterion_revisions
            (tenant_id, project_id, criterion_id, version, title, statement, digest, author_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$actor->tenantId, $project, $criterion, $version, $title, $statement, $digest, $actor->accountId]);
        $this->audit->record($actor, 'project', $project, $prior ? 'criterion_revised' : 'criterion_added',
            ['criterion_id' => $criterion, 'revision_id' => $id, 'previous_revision_id' => $prior, 'digest' => $digest]);
        return ['id' => $id, 'version' => $version, 'title' => $title, 'statement' => $statement, 'digest' => $digest];
    }

    private function digest(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
