<?php
declare(strict_types=1);

namespace DevDelivery;

use PDOException;

final readonly class Planning
{
    public function __construct(private Store $store, private Projects $projects, private Audit $audit)
    {
    }

    public function createSprint(Actor $actor, int $project, array $input): array
    {
        $team = Input::slug($input, 'team');
        $code = Input::slug($input, 'code');
        $title = Input::string($input, 'title', 160);
        $start = Input::date($input, 'starts_on');
        $end = Input::date($input, 'ends_on');
        $capacity = Input::integer($input, 'capacity', 1, 1_000_000);
        if ($start > $end) {
            throw new Problem(422, 'sprint_dates_reversed');
        }
        return $this->write($actor, $project, 'sprint_code_exists', function () use ($actor, $project, $team, $code, $title, $start, $end, $capacity): array {
            $id = $this->store->insert('INSERT INTO sprints (tenant_id, project_id, team, code, title, starts_on, ends_on, capacity)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$actor->tenantId, $project, $team, $code, $title, $start, $end, $capacity]);
            $this->audit->record($actor, 'project', $project, 'sprint_created', ['sprint_id' => $id, 'team' => $team]);
            return $this->sprint($actor, $project, $id);
        });
    }

    public function createWork(Actor $actor, int $project, array $input): array
    {
        $code = Input::slug($input, 'code');
        $title = Input::string($input, 'title', 160);
        $criterion = Input::integer($input, 'criterion_id', 1);
        $revision = Input::integer($input, 'revision_id', 1);
        $team = Input::slug($input, 'team');
        $owner = Input::integer($input, 'owner_id', 1);
        $effort = Input::integer($input, 'effort', 1, 1_000_000);
        return $this->write($actor, $project, 'work_item_code_exists', function () use ($actor, $project, $code, $title, $criterion, $revision, $team, $owner, $effort): array {
            $this->assertCurrentRevision($actor, $project, $criterion, $revision);
            $this->projects->assertParticipant($actor, $project, $owner);
            $id = $this->store->insert('INSERT INTO work_items
                (tenant_id, project_id, criterion_id, revision_id, code, title, team, owner_id, effort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$actor->tenantId, $project, $criterion, $revision, $code, $title, $team, $owner, $effort]);
            $this->record($actor, $project, $id, 'work_item_created', ['revision_id' => $revision, 'owner_id' => $owner]);
            return $this->work($actor, $project, $id);
        });
    }

    public function listing(Actor $actor, int $project, int $after, string $entity): array
    {
        $this->projects->view($actor, $project);
        $query = match ($entity) {
            'work_items' => 'SELECT id, code, title, criterion_id, revision_id, team, owner_id, claimed_by, claimed_at, effort, sprint_id, review_change_id FROM work_items',
            'sprints' => 'SELECT id, code, title, team, starts_on, ends_on, capacity,
                (SELECT COALESCE(SUM(w.effort),0) FROM work_items w WHERE (w.tenant_id,w.project_id,w.sprint_id)=(s.tenant_id,s.project_id,s.id)) AS used
                FROM sprints s',
        };
        $rows = $this->store->all($query . ' WHERE tenant_id=? AND project_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $project, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function sprintView(Actor $actor, int $project, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        $sprint = $this->sprint($actor, $project, $id);
        $rows = $this->store->all('SELECT id, code, title, effort, owner_id, review_change_id FROM work_items
            WHERE tenant_id=? AND project_id=? AND sprint_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $project, $id, $after]);
        return $sprint + ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function workView(Actor $actor, int $project, int $id): array
    {
        $this->projects->view($actor, $project);
        $work = $this->work($actor, $project, $id);
        $work['dependencies'] = $this->store->all('SELECT d.predecessor_id, p.code, p.team, d.owner_id, d.due_on,
                w.sprint_id, p.sprint_id as predecessor_sprint_id, sw.starts_on, sp.ends_on AS predecessor_ends_on
            FROM work_dependencies d JOIN work_items w ON w.id=d.work_item_id JOIN work_items p ON p.id=d.predecessor_id
            LEFT JOIN sprints sw ON sw.id=w.sprint_id LEFT JOIN sprints sp ON sp.id=p.sprint_id
            WHERE d.tenant_id=? AND d.project_id=? AND d.work_item_id=? ORDER BY p.id',
            [$actor->tenantId, $project, $id]);
        return $work + $this->diagnose($actor, $project, $id);
    }

    public function workTimeline(Actor $actor, int $project, int $id, int $after): array
    {
        $this->projects->view($actor, $project);
        $this->work($actor, $project, $id);
        return $this->audit->timeline($actor, 'work_item', $id, $after);
    }

    public function setDependency(Actor $actor, int $project, int $id, int $predecessor, ?array $input): array
    {
        if ($id === $predecessor) {
            throw new Problem(422, 'dependency_self_reference');
        }
        $owner = $input === null ? null : Input::integer($input, 'owner_id', 1);
        $due = $input === null ? null : Input::date($input, 'due_on');
        return $this->store->transaction(function () use ($actor, $project, $id, $predecessor, $owner, $due): array {
            $this->projects->lockForPlanning($actor, $project);
            $this->work($actor, $project, $id);
            $this->work($actor, $project, $predecessor);
            $identity = [$actor->tenantId, $project, $id, $predecessor];
            if ($owner === null) {
                $changed = $this->store->execute('DELETE FROM work_dependencies
                    WHERE tenant_id=? AND project_id=? AND work_item_id=? AND predecessor_id=?', $identity);
            } else {
                $this->projects->assertParticipant($actor, $project, $owner);
                $changed = $this->store->execute('INSERT INTO work_dependencies
                    (tenant_id, project_id, work_item_id, predecessor_id, owner_id, due_on) VALUES (?, ?, ?, ?, ?, ?) AS incoming
                    ON DUPLICATE KEY UPDATE owner_id=incoming.owner_id, due_on=incoming.due_on', [...$identity, $owner, $due]);
            }
            if ($changed) {
                $this->record($actor, $project, $id, $owner === null ? 'dependency_removed' : 'dependency_saved',
                    ['predecessor_id' => $predecessor, 'owner_id' => $owner, 'due_on' => $due]);
            }
            return $this->workView($actor, $project, $id);
        });
    }

    public function schedule(Actor $actor, int $project, int $id, array $input): array
    {
        if (!array_key_exists('sprint_id', $input)) {
            throw new Problem(422, 'invalid_input', ['field' => 'sprint_id']);
        }
        $target = $input['sprint_id'] === null ? null : Input::integer($input, 'sprint_id', 1);
        return $this->store->transaction(function () use ($actor, $project, $id, $target): array {
            $this->projects->lockForPlanning($actor, $project);
            $work = $this->work($actor, $project, $id);
            $sprint = $target === null ? null : $this->sprint($actor, $project, $target);
            if ($sprint && $sprint['team'] !== $work['team']) {
                throw new Problem(422, 'sprint_team_mismatch');
            }
            $used = $sprint ? (int) $sprint['used'] : 0;
            $extra = $target !== $work['sprint_id'] ? $work['effort'] : 0;
            if ($sprint && $used + $extra > $sprint['capacity']) {
                throw new Problem(409, 'sprint_capacity_exceeded',
                    ['sprint_id' => $target, 'capacity' => $sprint['capacity'], 'used' => $used, 'effort' => $extra]);
            }
            $this->store->execute('UPDATE work_items SET sprint_id=? WHERE tenant_id=? AND project_id=? AND id=?',
                [$target, $actor->tenantId, $project, $id]);
            $conflicts = $this->diagnose($actor, $project, $id);
            if ($target !== null && $conflicts['cycle']) {
                throw new Problem(409, 'dependency_cycle', $conflicts);
            }
            if ($conflicts['schedule_conflicts']) {
                throw new Problem(409, 'dependency_schedule_conflict', $conflicts);
            }
            if ($target !== $work['sprint_id']) {
                $this->record($actor, $project, $id, 'work_item_scheduled',
                    ['previous_sprint_id' => $work['sprint_id'], 'sprint_id' => $target]);
            }
            return $this->workView($actor, $project, $id);
        });
    }

    private function diagnose(Actor $actor, int $project, int $id): array
    {
        $edges = $this->store->all('SELECT d.work_item_id, d.predecessor_id, d.owner_id, d.due_on,
                w.sprint_id, p.sprint_id AS predecessor_sprint_id, sw.starts_on, sp.ends_on AS predecessor_ends_on
            FROM work_dependencies d JOIN work_items w ON w.id=d.work_item_id JOIN work_items p ON p.id=d.predecessor_id
            LEFT JOIN sprints sw ON sw.id=w.sprint_id LEFT JOIN sprints sp ON sp.id=p.sprint_id
            WHERE d.tenant_id=? AND d.project_id=? ORDER BY d.work_item_id, d.predecessor_id', [$actor->tenantId, $project]);
        $upstream = [];
        $roots = [$id => $id];
        $conflicts = [];
        $sprints = [];
        foreach ($edges as $edge) {
            $work = $edge['work_item_id'];
            $predecessor = $edge['predecessor_id'];
            $upstream[$work][] = $predecessor;
            if ($predecessor === $id && $edge['sprint_id'] !== null) {
                $roots[$work] = $work;
            }
            if (($work === $id || $predecessor === $id) && $edge['sprint_id'] !== null
                && ($edge['predecessor_sprint_id'] === null || $edge['predecessor_ends_on'] > min($edge['starts_on'], $edge['due_on']))) {
                $conflicts[] = $edge + ['reason' => $edge['predecessor_sprint_id'] === null ? 'predecessor_unscheduled' : 'predecessor_due_late'];
                $sprints[] = $edge['sprint_id'];
                if ($edge['predecessor_sprint_id'] !== null) {
                    $sprints[] = $edge['predecessor_sprint_id'];
                }
            }
        }
        $cycle = $this->cycle($upstream, $roots);
        if ($cycle) {
            $cycleItems = array_fill_keys($cycle, true);
            foreach ($edges as $edge) {
                if (isset($cycleItems[$edge['work_item_id']]) || isset($cycleItems[$edge['predecessor_id']])) {
                    foreach (['sprint_id', 'predecessor_sprint_id'] as $key) {
                        if ($edge[$key] !== null) {
                            $sprints[] = $edge[$key];
                        }
                    }
                }
            }
        }
        return ['cycle' => $cycle, 'schedule_conflicts' => $conflicts, 'affected_sprint_ids' => array_values(array_unique($sprints))];
    }

    private function cycle(array $upstream, array $roots): array
    {
        $visited = [];
        foreach ($roots as $root) {
            if (isset($visited[$root])) {
                continue;
            }
            $stack = [[$root, 0]];
            $active = [$root => 0];
            while ($stack) {
                $last = count($stack) - 1;
                [$work, $index] = $stack[$last];
                $predecessor = $upstream[$work][$index] ?? null;
                if ($predecessor === null) {
                    $visited[$work] = true;
                    unset($active[$work]);
                    array_pop($stack);
                    continue;
                }
                $stack[$last][1]++;
                if (isset($active[$predecessor])) {
                    return [...array_column(array_slice($stack, $active[$predecessor]), 0), $predecessor];
                }
                if (!isset($visited[$predecessor])) {
                    $active[$predecessor] = count($stack);
                    $stack[] = [$predecessor, 0];
                }
            }
        }
        return [];
    }

    private function work(Actor $actor, int $project, int $id): array
    {
        return $this->store->one('SELECT id, criterion_id, revision_id, code, title, team, owner_id, claimed_by, claimed_at, effort, sprint_id, review_change_id
            FROM work_items WHERE tenant_id=? AND project_id=? AND id=?', [$actor->tenantId, $project, $id])
            ?? throw new Problem(404, 'work_item_not_found');
    }

    private function sprint(Actor $actor, int $project, int $id): array
    {
        return $this->store->one('SELECT s.id, s.team, s.code, s.title, s.starts_on, s.ends_on, s.capacity,
                (SELECT COALESCE(SUM(w.effort),0) FROM work_items w WHERE (w.tenant_id,w.project_id,w.sprint_id)=(s.tenant_id,s.project_id,s.id)) AS used
            FROM sprints s WHERE s.tenant_id=? AND s.project_id=? AND s.id=?', [$actor->tenantId, $project, $id])
            ?? throw new Problem(404, 'sprint_not_found');
    }

    private function assertCurrentRevision(Actor $actor, int $project, int $criterion, int $revision): void
    {
        $current = $this->store->one('SELECT id FROM criterion_revisions WHERE tenant_id=? AND project_id=? AND criterion_id=?
            ORDER BY version DESC LIMIT 1', [$actor->tenantId, $project, $criterion]);
        if (($current['id'] ?? null) !== $revision) {
            throw new Problem(422, 'current_criterion_revision_required');
        }
    }

    private function write(Actor $actor, int $project, string $duplicate, callable $operation): array
    {
        try {
            return $this->store->transaction(function () use ($actor, $project, $operation): array {
                $this->projects->lockForPlanning($actor, $project);
                return $operation();
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, $duplicate);
            }
            throw $error;
        }
    }

    private function record(Actor $actor, int $project, int $id, string $action, array $details): void
    {
        $this->audit->record($actor, 'work_item', $id, $action, ['project_id' => $project] + $details);
        $this->audit->record($actor, 'project', $project, $action, ['work_item_id' => $id] + $details);
    }
}
