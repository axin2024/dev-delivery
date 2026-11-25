<?php
declare(strict_types=1);

namespace DevDelivery;

use PDOException;

final readonly class TestCases
{
    public function __construct(private Store $store, private Projects $projects, private Audit $audit)
    {
    }

    public function create(Actor $actor, int $project, array $input): array
    {
        $code = Input::slug($input, 'code');
        $criterion = Input::integer($input, 'criterion_id', 1);
        $version = $this->versionInput($input + ['junit_name' => $code, 'junit_class' => '']);
        try {
            return $this->store->transaction(function () use ($actor, $project, $code, $criterion, $version): array {
                $this->projects->lockForTesting($actor, $project, true);
                $this->assertRevision($actor, $project, $criterion, $version['revision_id']);
                $id = $this->store->insert('INSERT INTO test_cases
                    (tenant_id, project_id, criterion_id, revision_id, code, title, steps, expected_result) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$actor->tenantId, $project, $criterion, $version['revision_id'], $code, $version['title'], $version['steps'], $version['expected_result']]);
                $this->append($actor, $project, $id, $criterion, 1, $version);
                return $this->current($actor, $project, $id);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'test_case_code_exists');
            }
            throw $error;
        }
    }

    public function revise(Actor $actor, int $project, int $id, array $input): array
    {
        $expected = Input::integer($input, 'version', 1);
        return $this->store->transaction(function () use ($actor, $project, $id, $input, $expected): array {
            $this->projects->lockForTesting($actor, $project, true);
            $current = $this->current($actor, $project, $id);
            if ($current['version'] !== $expected) {
                throw new Problem(409, 'test_case_version_conflict', ['version' => $current['version']]);
            }
            $version = $this->versionInput($input + ['junit_class' => $current['junit_class'], 'junit_name' => $current['junit_name']]);
            $this->assertRevision($actor, $project, $current['criterion_id'], $version['revision_id']);
            if ($version === array_replace($version, array_intersect_key($current, $version))) {
                throw new Problem(422, 'test_case_unchanged');
            }
            $this->append($actor, $project, $id, $current['criterion_id'], $expected + 1, $version);
            $this->store->execute('UPDATE test_cases SET revision_id=?, title=?, steps=?, expected_result=?, review_change_id=NULL
                WHERE tenant_id=? AND project_id=? AND id=?',
                [$version['revision_id'], $version['title'], $version['steps'], $version['expected_result'], $actor->tenantId, $project, $id]);
            return $this->current($actor, $project, $id);
        });
    }

    public function listing(Actor $actor, int $project, int $after): array
    {
        $this->projects->view($actor, $project);
        return self::page($this->store->all($this->currentQuery() .
            ' WHERE c.tenant_id=? AND c.project_id=? AND c.id>? ORDER BY c.id LIMIT 51', [$actor->tenantId, $project, $after]));
    }

    public function history(Actor $actor, int $project, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        $current = $this->current($actor, $project, $id);
        $rows = $this->store->all('SELECT id, version, criterion_revision_id, title, steps, expected_result, junit_class, junit_name,
                authored_by, created_at FROM test_case_versions
            WHERE tenant_id=? AND project_id=? AND case_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $project, $id, $after]);
        return $current + self::page($rows);
    }

    public function timeline(Actor $actor, int $project, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        $this->current($actor, $project, $id);
        return $this->audit->timeline($actor, 'test_case', $id, $after);
    }

    private function append(Actor $actor, int $project, int $id, int $criterion, int $number, array $version): void
    {
        $revision = $version['revision_id'];
        $versionId = $this->store->insert('INSERT INTO test_case_versions (tenant_id, project_id, case_id, version,
            criterion_id, criterion_revision_id, title, steps, expected_result, junit_class, junit_name, authored_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$actor->tenantId, $project, $id, $number, $criterion, $revision, $version['title'], $version['steps'],
                $version['expected_result'], $version['junit_class'], $version['junit_name'], $actor->accountId]);
        $action = $number === 1 ? 'test_case_created' : 'test_case_revised';
        $details = ['project_id' => $project, 'case_version_id' => $versionId, 'revision_id' => $revision];
        $this->audit->record($actor, 'test_case', $id, $action, $details);
        $this->audit->record($actor, 'project', $project, $action, ['test_case_id' => $id] + $details);
    }

    private function versionInput(array $input): array
    {
        return ['revision_id' => Input::integer($input, 'revision_id', 1), 'title' => Input::string($input, 'title', 160),
            'steps' => Input::string($input, 'steps', 12_000), 'expected_result' => Input::string($input, 'expected_result', 12_000),
            'junit_class' => Input::string($input, 'junit_class', 240, 0), 'junit_name' => Input::string($input, 'junit_name', 240)];
    }

    private function current(Actor $actor, int $project, int $id): array
    {
        return $this->store->one($this->currentQuery() . ' WHERE c.tenant_id=? AND c.project_id=? AND c.id=?',
            [$actor->tenantId, $project, $id]) ?? throw new Problem(404, 'test_case_not_found');
    }

    private function currentQuery(): string
    {
        return 'SELECT c.id, c.code, c.title, c.criterion_id, c.revision_id, c.steps, c.expected_result, c.review_change_id,
                v.id AS case_version_id, v.version, v.junit_class, v.junit_name,
                (SELECT r.id FROM criterion_revisions r WHERE
                    (r.tenant_id,r.project_id,r.criterion_id)=(c.tenant_id,c.project_id,c.criterion_id)
                    ORDER BY r.version DESC LIMIT 1) AS current_criterion_revision_id
            FROM test_cases c JOIN test_case_versions v ON v.id=(SELECT cv.id FROM test_case_versions cv
                WHERE (cv.tenant_id,cv.project_id,cv.case_id)=(c.tenant_id,c.project_id,c.id) ORDER BY cv.version DESC LIMIT 1)';
    }

    private function assertRevision(Actor $actor, int $project, int $criterion, int $revision): void
    {
        $current = $this->store->one('SELECT id FROM criterion_revisions WHERE tenant_id=? AND project_id=? AND criterion_id=?
            ORDER BY version DESC LIMIT 1', [$actor->tenantId, $project, $criterion]);
        if (($current['id'] ?? null) !== $revision) {
            throw new Problem(422, 'current_criterion_revision_required');
        }
    }

    private static function page(array $rows): array
    {
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }
}
