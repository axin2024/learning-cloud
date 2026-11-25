<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class AssignmentCatalog
{
    private const FIELDS = 'id, cohort_id, title, instructions, due_at, grace_minutes, required_for_completion, pass_score, rubric, version, published_at';

    public function __construct(private Store $store, private TeachingStaff $staff, private Audit $audit)
    {
    }

    public function listing(Actor $actor, int $cohortId, int $after): array
    {
        $this->staff->requireMarker($actor, $cohortId);
        $visibility = $actor->managesCatalog() ? '' : ' AND published_at IS NOT NULL';
        $rows = $this->store->all('SELECT id, title, due_at, grace_minutes, required_for_completion, pass_score, published_at, version
              FROM coursework_assignments WHERE tenant_id=? AND cohort_id=? AND id>?' . $visibility . ' ORDER BY id LIMIT 51',
            [$actor->tenantId, $cohortId, $after]);
        return $this->page($rows);
    }

    public function detail(Actor $actor, int $id): array
    {
        $task = $this->row($actor->tenantId, $id);
        $this->staff->requireMarker($actor, $task['cohort_id']);
        if (!$actor->managesCatalog() && $task['published_at'] === null) {
            throw new Problem(404, 'assignment_not_found');
        }
        return $task;
    }

    public function draft(Actor $actor, int $cohortId, array $input): array
    {
        $actor->requireManagement();
        $fields = $this->fields($input);
        return $this->store->transaction(function () use ($actor, $cohortId, $fields): array {
            $this->staff->requireMarker($actor, $cohortId, true);
            $id = $this->store->insert('INSERT INTO coursework_assignments (tenant_id, cohort_id, title, instructions, due_at, grace_minutes, required_for_completion, pass_score, rubric) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$actor->tenantId, $cohortId, ...$fields]);
            $this->audit->record($actor, 'assignment', $id, 'drafted', ['cohort_id' => $cohortId]);
            return $this->row($actor->tenantId, $id);
        });
    }

    public function edit(Actor $actor, int $id, array $input): array
    {
        $actor->requireManagement();
        $version = Input::integer($input, 'version', 1);
        $fields = $this->fields($input);
        return $this->store->transaction(function () use ($actor, $id, $version, $fields): array {
            $this->checkVersion($this->row($actor->tenantId, $id, true), $version);
            $this->store->execute('UPDATE coursework_assignments SET title=?, instructions=?, due_at=?, grace_minutes=?, required_for_completion=?, pass_score=?, rubric=?, version=version+1 WHERE tenant_id=? AND id=?',
                [...$fields, $actor->tenantId, $id]);
            $this->audit->record($actor, 'assignment', $id, 'draft_edited', ['version' => $version + 1]);
            return $this->row($actor->tenantId, $id);
        });
    }

    public function publish(Actor $actor, int $id, array $input): array
    {
        $actor->requireManagement();
        $version = Input::integer($input, 'version', 1);
        return $this->store->transaction(function () use ($actor, $id, $version): array {
            $task = $this->row($actor->tenantId, $id, true);
            if ($task['published_at'] !== null && $task['version'] === $version) {
                return $task;
            }
            $this->checkVersion($task, $version);
            $this->store->execute('UPDATE coursework_assignments SET published_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$actor->tenantId, $id]);
            $this->audit->record($actor, 'assignment', $id, 'published', ['cohort_id' => $task['cohort_id'], 'version' => $version]);
            return $this->row($actor->tenantId, $id);
        });
    }

    public function row(int $tenantId, int $id, bool $lock = false): array
    {
        $task = $this->store->one('SELECT ' . self::FIELDS . ', DATE_ADD(due_at, INTERVAL grace_minutes MINUTE) AS grace_ends_at
            FROM coursework_assignments WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$tenantId, $id]);
        if ($task === null) {
            throw new Problem(404, 'assignment_not_found');
        }
        $task['rubric'] = json_decode($task['rubric'], true, 32, JSON_THROW_ON_ERROR);
        return $task;
    }

    private function checkVersion(array $task, int $version): void
    {
        if ($task['published_at'] !== null) {
            throw new Problem(409, 'assignment_already_published');
        }
        if ($task['version'] !== $version) {
            throw new Problem(409, 'assignment_version_conflict', ['version' => $task['version']]);
        }
    }

    private function fields(array $input): array
    {
        $rubric = [];
        $total = 0;
        foreach (Input::list($input, 'rubric', 1, 20) as $entry) {
            if (!is_array($entry)) {
                throw new Problem(422, 'invalid_assignment_rubric');
            }
            $points = Input::integer($entry, 'points', 1, 100);
            $rubric[] = ['label' => Input::string($entry, 'label', 140), 'points' => $points];
            $total += $points;
        }
        if ($total !== 100) {
            throw new Problem(422, 'rubric_points_must_total_100', ['points' => $total]);
        }
        $due = Input::utcDateTime($input, 'due_at');
        $grace = Input::integer($input, 'grace_minutes', 0, 10080);
        $graceEnds = (new \DateTimeImmutable($due, new \DateTimeZone('UTC')))->modify("+$grace minutes");
        if ((int) $graceEnds->format('Y') > 9999) {
            throw new Problem(422, 'invalid_input', ['field' => 'grace_minutes']);
        }
        return [Input::string($input, 'title', 200), Input::string($input, 'instructions', 16000), $due,
            $grace, Input::boolean($input, 'required_for_completion'),
            Input::integer($input, 'pass_score', 0, 100), json_encode($rubric, JSON_THROW_ON_ERROR)];
    }

    private function page(array $rows): array
    {
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }
}
