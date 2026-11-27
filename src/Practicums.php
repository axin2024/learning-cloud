<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class Practicums
{
    public function __construct(private Store $store, private TeachingStaff $staff, private Audit $audit) {}

    public function publish(Actor $actor, int $cohort, array $input): array
    {
        $this->staff->requireMarker($actor, $cohort, true);
        $scope = [$actor->tenantId, $cohort];
        if (!$this->store->one('SELECT rubric_id FROM cohort_rubrics WHERE tenant_id=? AND cohort_id=?', $scope)) {
            throw new Problem(409, 'bind_cohort_rubric_first');
        }
        $id = $this->store->insert('INSERT INTO practicums (tenant_id,cohort_id,title,instructions,sample,due_at)
            VALUES (?,?,?,?,?,?)', [...$scope, Input::string($input, 'title', 160), Input::string($input, 'instructions', 8000),
            Input::string($input, 'sample', 8000), Input::utcDateTime($input, 'due_at')]);
        $this->audit->record($actor, 'practicum', $id, 'published', ['cohort_id' => $cohort]);
        return ['practicum_id' => $id, 'brief_rev' => 1];
    }

    public function listing(Actor $actor, int $cohort, int $after): array
    {
        (new CompetencyRubrics($this->store, $this->audit))->forCohort($actor, $cohort);
        $rows = $this->store->all('SELECT id,title,due_at,brief_rev FROM practicums
            WHERE tenant_id=? AND cohort_id=? AND id>? ORDER BY id LIMIT 51', [$actor->tenantId, $cohort, $after]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function task(Actor $actor, int $id): array
    {
        return $this->store->one('SELECT p.id,p.cohort_id,p.title,p.instructions,p.sample,p.due_at,p.brief_rev,
            r.id rubric_id,r.revision rubric_rev FROM practicums p JOIN cohort_rubrics c
            ON c.tenant_id=p.tenant_id AND c.cohort_id=p.cohort_id JOIN rubric_revisions r ON r.tenant_id=c.tenant_id AND r.id=c.rubric_id
            WHERE p.tenant_id=? AND p.id=?', [$actor->tenantId, $id]) ?? throw new Problem(404, 'practicum_not_found');
    }

    public function assign(Actor $actor, int $id, array $input): array
    {
        $task = $this->task($actor, $id);
        $this->staff->requireMarker($actor, $task['cohort_id'], true);
        $scope = [$actor->tenantId, $id];
        $this->store->one('SELECT id FROM practicums WHERE tenant_id=? AND id=? FOR UPDATE', $scope);
        $members = Input::list($input, 'members', 1, 50);
        $team = $this->store->insert('INSERT INTO practicum_teams (tenant_id,practicum_id,name) VALUES (?,?,?)',
            [...$scope, Input::string($input, 'name', 120)]);
        $ids = [];
        foreach ($members as $index => $member) {
            if (!is_array($member)) {
                throw new Problem(422, 'invalid_input', ['field' => "members.$index"]);
            }
            $learner = Input::integer($member, 'learner_id', 1);
            if (!$this->store->one("SELECT id FROM enrollments WHERE tenant_id=? AND cohort_id=? AND learner_id=?
                AND state IN ('enrolled','studying') FOR SHARE", [$actor->tenantId, $task['cohort_id'], $learner])) {
                throw new Problem(422, 'active_enrollment_required', ['field' => "members.$index.learner_id"]);
            }
            if ($this->store->one('SELECT id FROM practicum_assignments WHERE tenant_id=? AND practicum_id=? AND learner_id=?', [...$scope, $learner])) {
                throw new Problem(409, 'learner_already_assigned', ['learner_id' => $learner]);
            }
            $ids[] = $this->store->insert('INSERT INTO practicum_assignments
                (tenant_id,practicum_id,team_id,learner_id,responsibility,material) VALUES (?,?,?,?,?,?)',
                [...$scope, $team, $learner, Input::string($member, 'responsibility', 160), Input::string($member, 'material', 8000)]);
        }
        $this->audit->record($actor, 'practicum', $id, 'assigned', ['team_id' => $team, 'assignment_ids' => $ids]);
        return ['team_id' => $team, 'brief_rev' => $task['brief_rev'], 'assignment_ids' => $ids];
    }

    public function membership(Actor $actor, array $task, int $team, bool $write = false): array
    {
        $states = $write ? "'enrolled','studying'" : "'enrolled','studying','completed'";
        if ($write) {
            $this->store->one('SELECT id FROM practicum_teams WHERE tenant_id=? AND practicum_id=? AND id=? FOR UPDATE',
                [$actor->tenantId, $task['id'], $team]);
        }
        return $this->store->one("SELECT a.id FROM practicum_assignments a JOIN enrollments e
            ON e.tenant_id=a.tenant_id AND e.learner_id=a.learner_id AND e.cohort_id=?
            WHERE a.tenant_id=? AND a.practicum_id=? AND a.team_id=? AND a.learner_id=? AND e.state IN ($states)"
            . ($write ? ' FOR SHARE' : ''), [$task['cohort_id'], $actor->tenantId, $task['id'], $team, $actor->accountId])
            ?? throw new Problem(404, 'team_not_found');
    }

    public function detail(Actor $actor, int $id): array
    {
        $task = $this->task($actor, $id);
        (new CompetencyRubrics($this->store, $this->audit))->forCohort($actor, $task['cohort_id']);
        $marker = $this->isMarker($actor, $task['cohort_id']);
        $scope = [$actor->tenantId, $id];
        $filter = '';
        if (!$marker) {
            $filter = ' AND a.team_id=(SELECT team_id FROM practicum_assignments WHERE tenant_id=? AND practicum_id=? AND learner_id=?)';
            $scope = [...$scope, ...$scope, $actor->accountId];
        }
        $rows = $this->store->all('SELECT a.id assignment_id,a.team_id,t.name team_name,a.learner_id,a.responsibility,
            CASE WHEN ? OR a.learner_id=? THEN a.material END material,
            CASE WHEN ? OR a.learner_id=? THEN c.body END contribution,c.revision
            FROM practicum_assignments a JOIN practicum_teams t ON t.id=a.team_id
            LEFT JOIN practicum_contributions c ON c.assignment_id=a.id AND c.revision=(
                SELECT MAX(v.revision) FROM practicum_contributions v WHERE v.assignment_id=a.id)
            WHERE a.tenant_id=? AND a.practicum_id=?' . $filter . ' ORDER BY a.team_id,a.id',
            [$marker, $actor->accountId, $marker, $actor->accountId, ...$scope]);
        $teamIds = array_column($rows, 'team_id');
        $extensions = [];
        $submissions = [];
        if ($teamIds) {
            $limit = $marker ? '' : ' AND team_id=?';
            $lookup = $marker ? [$actor->tenantId, $id] : [$actor->tenantId, $id, $teamIds[0]];
            $extensions = $this->store->all('SELECT team_id,attempt,reason,approved_by,until_at FROM practicum_extensions
                WHERE tenant_id=? AND practicum_id=?' . $limit . ' ORDER BY team_id,attempt', $lookup);
            $submissions = $this->store->all('SELECT team_id,attempt,submitted_at,late FROM practicum_submissions
                WHERE tenant_id=? AND practicum_id=?' . $limit . ' ORDER BY team_id,attempt', $lookup);
        }
        return $task + ['assignments' => $rows, 'can_assign' => $marker, 'extensions' => $extensions, 'submissions' => $submissions];
    }

    public function candidates(Actor $actor, int $id, int $after): array
    {
        $task = $this->task($actor, $id);
        $this->staff->requireMarker($actor, $task['cohort_id']);
        $rows = $this->store->all("SELECT a.id,a.name FROM accounts a JOIN enrollments e ON e.tenant_id=a.tenant_id AND e.learner_id=a.id
            WHERE a.tenant_id=? AND e.cohort_id=? AND a.id>? AND e.state IN ('enrolled','studying') AND NOT EXISTS
            (SELECT 1 FROM practicum_assignments p WHERE p.tenant_id=a.tenant_id AND p.learner_id=a.id AND p.practicum_id=?)
            ORDER BY a.id LIMIT 51", [$actor->tenantId, $task['cohort_id'], $after, $id]);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function isMarker(Actor $actor, int $cohort): bool
    {
        return $actor->managesCatalog() || ($actor->has('instructor') && $this->store->one(
            'SELECT 1 FROM cohort_staff WHERE tenant_id=? AND cohort_id=? AND account_id=?',
            [$actor->tenantId, $cohort, $actor->accountId]));
    }

    public function contribution(Actor $actor, int $id, int $team, array $input): array
    {
        $assignment = $this->membership($actor, $this->task($actor, $id), $team, true)['id'];
        $this->store->one('SELECT id FROM practicum_assignments WHERE id=? FOR UPDATE', [$assignment]);
        $current = $this->store->one('SELECT COALESCE(MAX(revision),0) rev FROM practicum_contributions WHERE assignment_id=?', [$assignment])['rev'];
        if (Input::integer($input, 'previous_revision', 0) !== (int)$current) {
            throw new Problem(409, 'contribution_revision_changed', ['revision' => (int)$current]);
        }
        $this->store->execute('INSERT INTO practicum_contributions (assignment_id,revision,body) VALUES (?,?,?)',
            [$assignment, $current + 1, Input::string($input, 'body', 8000)]);
        return ['assignment_id' => $assignment, 'revision' => $current + 1];
    }

    public function extension(Actor $actor, int $id, int $team, array $input, bool $approve): array
    {
        $task = $this->task($actor, $id);
        if ($approve) {
            $this->staff->requireMarker($actor, $task['cohort_id'], true);
        } else {
            $this->membership($actor, $task, $team, true);
        }
        $scope = [$actor->tenantId, $id, $team];
        if (!$this->store->one('SELECT id FROM practicum_teams WHERE tenant_id=? AND practicum_id=? AND id=? FOR UPDATE', $scope)) {
            throw new Problem(404, 'team_not_found');
        }
        $attempt = Input::integer($input, 'attempt', 1);
        $scope[] = $attempt;
        $existing = $this->store->one('SELECT id,approved_by FROM practicum_extensions
            WHERE tenant_id=? AND practicum_id=? AND team_id=? AND attempt=?', $scope);
        if ($approve) {
            if (!$existing || $existing['approved_by'] !== null) {
                throw new Problem(409, 'pending_extension_required');
            }
            $until = Input::utcDateTime($input, 'until_at');
            $now = $this->store->one('SELECT UTC_TIMESTAMP(6) clock')['clock'];
            if ($until <= max($task['due_at'], $now)) {
                throw new Problem(422, 'invalid_extension_deadline', ['field' => 'until_at']);
            }
            $extension = $existing['id'];
            $this->store->execute('UPDATE practicum_extensions SET approved_by=?,until_at=? WHERE id=?', [$actor->accountId, $until, $extension]);
        } else {
            if ($existing) {
                throw new Problem(409, 'extension_already_requested');
            }
            $extension = $this->store->insert('INSERT INTO practicum_extensions (tenant_id,practicum_id,team_id,attempt,reason,requested_by)
                VALUES (?,?,?,?,?,?)', [...$scope, Input::string($input, 'reason', 500), $actor->accountId]);
        }
        $this->audit->record($actor, 'practicum', $id, $approve ? 'extension_approved' : 'extension_requested',
            ['extension_id' => $extension, 'team_id' => $team, 'attempt' => $attempt]);
        return ['extension_id' => $extension, 'approved' => $approve, 'attempt' => $attempt];
    }
}
