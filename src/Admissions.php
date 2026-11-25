<?php
declare(strict_types=1);

namespace LearningCloud;

use PDOException;

final readonly class Admissions
{
    private const COHORT_FIELDS = 'id, revision_id, code, name, starts_on, ends_on, capacity, registration_open, version';
    private const ENROLLMENT_FIELDS = 'id, cohort_id, learner_id, state, transferred_from_id, enrolled_at, closed_at, version';
    private const OCCUPIED = "'reserved','enrolled','studying','completed'";
    private CourseEquivalences $equivalences;
    private LearningPaths $paths;

    public function __construct(private Store $store, private Catalog $catalog, private Accounts $accounts, private Audit $audit)
    {
        $this->equivalences = new CourseEquivalences($store, $audit);
        $this->paths = new LearningPaths($store, $audit);
    }

    public function createCohort(Actor $actor, array $input): array
    {
        $actor->requireManagement();
        $courseId = Input::integer($input, 'course_id', 1);
        $revisionId = array_key_exists('revision_id', $input) ? Input::integer($input, 'revision_id', 1) : null;
        $pathStep = array_key_exists('path_step_id', $input) ? Input::integer($input, 'path_step_id', 1) : null;
        $code = Input::slug($input, 'code');
        $name = Input::string($input, 'name', 160);
        $start = Input::date($input, 'starts_on');
        $end = Input::date($input, 'ends_on');
        $capacity = Input::integer($input, 'capacity', 1, 10_000);
        $open = Input::boolean($input, 'registration_open');
        if ($end < $start) {
            throw new Problem(422, 'invalid_cohort_dates');
        }
        try {
            return $this->store->transaction(function () use ($actor, $courseId, $revisionId, $pathStep, $code, $name, $start, $end, $capacity, $open): array {
                $revision = $this->catalog->revisionForNewCohort($actor, $courseId, $revisionId);
                $id = $this->store->insert(
                    'INSERT INTO cohorts (tenant_id, revision_id, code, name, starts_on, ends_on, capacity, registration_open) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$actor->tenantId, $revision, $code, $name, $start, $end, $capacity, $open]
                );
                if ($pathStep !== null) {
                    $this->paths->bindCohort($actor->tenantId, $id, $courseId, $pathStep);
                }
                $this->audit->record($actor, 'cohort', $id, 'created', ['revision_id' => $revision, 'capacity' => $capacity, 'path_step_id' => $pathStep]);
                return $this->cohort($actor, $id);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'cohort_code_exists');
            }
            throw $error;
        }
    }

    public function reviseCohort(Actor $actor, int $cohortId, array $input): array
    {
        $actor->requireManagement();
        $version = Input::integer($input, 'version', 1);
        $capacity = Input::integer($input, 'capacity', 1, 10_000);
        $open = Input::boolean($input, 'registration_open');
        return $this->store->transaction(function () use ($actor, $cohortId, $version, $capacity, $open): array {
            $cohort = $this->cohortRow($actor, $cohortId, true);
            if ($cohort['version'] !== $version) {
                throw new Problem(409, 'cohort_version_conflict', ['version' => $cohort['version']]);
            }
            $occupied = $this->occupied($actor, $cohortId);
            if ($occupied > $capacity) {
                throw new Problem(409, 'capacity_below_occupancy', ['occupied' => $occupied]);
            }
            $this->store->execute('UPDATE cohorts SET capacity=?, registration_open=?, version=version+1 WHERE tenant_id=? AND id=?', [$capacity, $open, $actor->tenantId, $cohortId]);
            $promoted = $this->promote($actor, $cohortId, $capacity - $occupied);
            $this->audit->record($actor, 'cohort', $cohortId, 'registration_revised', ['capacity' => $capacity, 'open' => $open, 'version' => $version + 1, 'promoted' => $promoted]);
            return $this->cohort($actor, $cohortId) + ['promoted_enrollment_ids' => $promoted];
        });
    }

    public function cohort(Actor $actor, int $cohortId): array
    {
        $row = $this->store->one(
            'SELECT c.id, c.revision_id, c.code, c.name, c.starts_on, c.ends_on, c.capacity, c.registration_open, c.version,
                    r.course_id, r.revision, r.title,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.tenant_id=c.tenant_id AND e.cohort_id=c.id
                      AND e.state IN (' . self::OCCUPIED . ")) AS occupied,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.tenant_id=c.tenant_id AND e.cohort_id=c.id
                      AND e.state='waitlisted') AS waitlisted
               FROM cohorts c JOIN course_revisions r ON r.tenant_id=c.tenant_id AND r.id=c.revision_id
              WHERE c.tenant_id=? AND c.id=?",
            [$actor->tenantId, $cohortId]
        );
        if ($row === null) {
            throw new Problem(404, 'cohort_not_found');
        }
        $path = $this->paths->cohortPath($actor->tenantId, $cohortId);
        return $row + ['path' => $path, 'missing_prerequisites' => $path !== null && $actor->has('learner')
            ? $this->paths->missing($actor->tenantId, $cohortId, $actor->accountId) : []];
    }

    public function cohorts(Actor $actor, int $after): array
    {
        $rows = $this->store->all(
            'SELECT c.id, c.revision_id, c.code, c.name, c.starts_on, c.ends_on, c.capacity, c.registration_open, c.version,
                    r.course_id, r.revision, r.title
               FROM cohorts c JOIN course_revisions r ON r.tenant_id=c.tenant_id AND r.id=c.revision_id
              WHERE c.tenant_id=? AND c.id>? ORDER BY c.id LIMIT 51',
            [$actor->tenantId, $after]
        );
        return $this->page($rows);
    }

    public function enroll(Actor $actor, int $cohortId, array $input, string $key): array
    {
        $key = Input::requestKey($key);
        $learnerId = array_key_exists('learner_id', $input) ? Input::integer($input, 'learner_id', 1) : $actor->accountId;
        if (!$actor->managesCatalog() && (!$actor->has('learner') || $learnerId !== $actor->accountId)) {
            throw new Problem(403, 'learner_or_registrar_required');
        }
        return $this->store->transaction(function () use ($actor, $cohortId, $learnerId, $key): array {
            $cohort = $this->cohortRow($actor, $cohortId, true);
            $prior = $this->store->one(
                'SELECT learner_id, enrollment_id FROM enrollment_requests WHERE tenant_id=? AND actor_id=? AND cohort_id=? AND request_key=?',
                [$actor->tenantId, $actor->accountId, $cohortId, $key]
            );
            if ($prior !== null) {
                if ($prior['learner_id'] !== $learnerId) {
                    throw new Problem(409, 'request_key_reused');
                }
                return $this->detail($actor, $prior['enrollment_id']) + ['created' => false];
            }
            $existing = $this->activeEnrollment($actor, $cohortId, $learnerId);
            if ($existing === null) {
                if (!$cohort['registration_open']) {
                    throw new Problem(409, 'registration_closed');
                }
                if (!$this->accounts->find($actor->tenantId, $learnerId)->has('learner')) {
                    throw new Problem(422, 'account_is_not_a_learner');
                }
                $this->paths->requireEntry($actor->tenantId, $cohortId, $learnerId);
                $hasSeat = $this->occupied($actor, $cohortId) < $cohort['capacity'];
                $id = $this->insertEnrollment($actor, $cohortId, $learnerId, $hasSeat ? 'enrolled' : 'waitlisted');
                $this->audit->record($actor, 'enrollment', $id, $hasSeat ? 'enrolled' : 'waitlisted', ['cohort_id' => $cohortId, 'learner_id' => $learnerId]);
            } else {
                $id = $existing['id'];
            }
            $this->store->execute(
                'INSERT INTO enrollment_requests (tenant_id, actor_id, cohort_id, request_key, learner_id, enrollment_id) VALUES (?, ?, ?, ?, ?, ?)',
                [$actor->tenantId, $actor->accountId, $cohortId, $key, $learnerId, $id]
            );
            return $this->detail($actor, $id) + ['created' => $existing === null];
        });
    }

    public function withdraw(Actor $actor, int $enrollmentId): array
    {
        return $this->store->transaction(function () use ($actor, $enrollmentId): array {
            $beforeLock = $this->ownOrManaged($actor, $enrollmentId);
            $cohort = $this->cohortRow($actor, $beforeLock['cohort_id'], true);
            $enrollment = $this->ownOrManaged($actor, $enrollmentId, lock: true);
            if ($enrollment['state'] === 'withdrawn') {
                return $this->detail($actor, $enrollmentId);
            }
            if (!in_array($enrollment['state'], ['waitlisted', 'reserved', 'enrolled', 'studying'], true)) {
                throw new Problem(409, 'enrollment_cannot_withdraw', ['state' => $enrollment['state']]);
            }
            $this->close($actor, $enrollmentId, 'withdrawn');
            $promoted = $enrollment['state'] !== 'waitlisted' ? $this->promote($actor, $cohort['id'], 1) : [];
            $this->audit->record($actor, 'enrollment', $enrollmentId, 'withdrawn', ['from' => $enrollment['state'], 'promoted' => $promoted]);
            return $this->detail($actor, $enrollmentId);
        });
    }

    public function activateEnterpriseSeat(Actor $learner, int $cohortId, int $assignmentId): int
    {
        // The caller holds the pool lock and commits the assignment with this enrollment.
        $cohort = $this->cohortRow($learner, $cohortId, true);
        if ($this->activeEnrollment($learner, $cohortId, $learner->accountId) !== null) {
            throw new Problem(409, 'learner_already_in_cohort');
        }
        if (!$cohort['registration_open']) {
            throw new Problem(409, 'registration_closed');
        }
        $this->paths->requireEntry($learner->tenantId, $cohortId, $learner->accountId);
        if ($this->occupied($learner, $cohortId) >= $cohort['capacity']) {
            throw new Problem(409, 'enterprise_cohort_full');
        }
        $id = $this->insertEnrollment($learner, $cohortId, $learner->accountId, 'enrolled');
        $this->audit->record($learner, 'enrollment', $id, 'enterprise_enrolled', ['assignment_id' => $assignmentId, 'cohort_id' => $cohortId]);
        return $id;
    }

    public function transfer(Actor $actor, int $enrollmentId, array $input): array
    {
        $targetId = Input::integer($input, 'target_cohort_id', 1);
        return $this->store->transaction(function () use ($actor, $enrollmentId, $targetId): array {
            $beforeLock = $this->ownOrManaged($actor, $enrollmentId);
            $sourceId = $beforeLock['cohort_id'];
            if ($sourceId === $targetId) {
                throw new Problem(422, 'same_cohort');
            }
            // Every transfer acquires the two capacity locks in the same order.
            $first = $this->cohortRow($actor, min($sourceId, $targetId), true);
            $second = $this->cohortRow($actor, max($sourceId, $targetId), true);
            [$source, $target] = $sourceId < $targetId ? [$first, $second] : [$second, $first];
            $enrollment = $this->ownOrManaged($actor, $enrollmentId, lock: true);
            if ($enrollment['state'] === 'transferred') {
                $previous = $this->store->one('SELECT id, cohort_id FROM enrollments WHERE tenant_id=? AND transferred_from_id=?', [$actor->tenantId, $enrollmentId]);
                if (($previous['cohort_id'] ?? null) === $targetId) {
                    return $this->detail($actor, $previous['id']);
                }
            }
            if (!in_array($enrollment['state'], ['enrolled', 'studying'], true)) {
                throw new Problem(409, 'enrollment_cannot_transfer', ['state' => $enrollment['state']]);
            }
            if (!$target['registration_open']) {
                throw new Problem(409, 'registration_closed');
            }
            $rule = $source['revision_id'] === $target['revision_id'] ? null
                : $this->equivalences->approved($actor->tenantId, $source['revision_id'], $target['revision_id']);
            if ($rule !== null && $rule['source_course_id'] !== $rule['target_course_id']
                && $this->wasEnterpriseFunded($actor->tenantId, $enrollmentId)) {
                throw new Problem(409, 'transfer_requires_purchased_course');
            }
            if ($this->activeEnrollment($actor, $targetId, $enrollment['learner_id']) !== null) {
                throw new Problem(409, 'already_in_target_cohort');
            }
            $this->paths->requireEntry($actor->tenantId, $targetId, $enrollment['learner_id']);
            if ($this->occupied($actor, $targetId) >= $target['capacity']) {
                throw new Problem(409, 'target_cohort_full');
            }
            $newId = $this->insertEnrollment($actor, $targetId, $enrollment['learner_id'], 'enrolled', $enrollmentId);
            $seconds = $this->equivalences->copyStudy($actor->tenantId, $enrollmentId, $newId, $rule);
            if ($seconds > 0 || ($rule === null && $enrollment['state'] === 'studying')) {
                $this->store->execute("UPDATE enrollments SET state='studying' WHERE tenant_id=? AND id=?", [$actor->tenantId, $newId]);
            }
            $snapshot = $this->gradeSnapshot($actor->tenantId, $enrollmentId);
            $this->store->execute('INSERT INTO enrollment_transfers (tenant_id, source_enrollment_id, target_enrollment_id, equivalence_id,
                equivalence_snapshot, grade_snapshot, credited_seconds) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$actor->tenantId, $enrollmentId, $newId, $rule['id'] ?? null,
                    $rule === null ? null : json_encode($rule, JSON_THROW_ON_ERROR), $snapshot, $seconds]);
            $this->close($actor, $enrollmentId, 'transferred');
            $promoted = $this->promote($actor, $sourceId, 1);
            $this->audit->record($actor, 'enrollment', $enrollmentId, 'transferred', ['target_enrollment_id' => $newId, 'promoted' => $promoted,
                'equivalence_id' => $rule['id'] ?? null, 'equivalence_version' => $rule['version'] ?? null, 'credited_seconds' => $seconds]);
            $this->audit->record($actor, 'enrollment', $newId, 'transfer_received', ['from_enrollment_id' => $enrollmentId]);
            return $this->detail($actor, $newId);
        });
    }

    public function transferOptions(Actor $actor, int $enrollmentId, int $after): array
    {
        $enrollment = $this->ownOrManaged($actor, $enrollmentId);
        $source = $this->cohort($actor, $enrollment['cohort_id']);
        $enterprise = $this->wasEnterpriseFunded($actor->tenantId, $enrollmentId);
        $rows = $this->store->all('SELECT c.id, c.name, v.title, v.revision, c.revision_id
            FROM cohorts c JOIN course_revisions v ON v.tenant_id=c.tenant_id AND v.id=c.revision_id
            LEFT JOIN course_equivalences q ON q.tenant_id=c.tenant_id AND q.source_revision_id=?
                AND q.target_revision_id=c.revision_id AND q.enabled
            WHERE c.tenant_id=? AND c.id>? AND c.id<>? AND c.registration_open AND (c.revision_id=? OR q.id IS NOT NULL)
                AND (NOT ? OR v.course_id=?) ORDER BY c.id LIMIT 51',
            [$source['revision_id'], $actor->tenantId, $after, $source['id'], $source['revision_id'], $enterprise, $source['course_id']]);
        return $this->page($rows);
    }

    public function transferHistory(Actor $actor, int $id): array
    {
        $enrollment = $this->ownOrManaged($actor, $id);
        $rows = $this->store->all("SELECT src.id AS source_enrollment_id, dst.id AS target_enrollment_id,
                src.cohort_id AS source_cohort_id, dst.cohort_id AS target_cohort_id, src.closed_at AS transferred_at,
                s.credited_seconds, s.equivalence_snapshot, s.grade_snapshot
            FROM enrollments dst JOIN enrollments src ON src.tenant_id=dst.tenant_id AND src.id=dst.transferred_from_id
            LEFT JOIN enrollment_transfers s ON s.tenant_id=src.tenant_id AND s.source_enrollment_id=src.id
            WHERE dst.tenant_id=? AND (dst.id=? OR src.id=?) AND dst.learner_id=? ORDER BY dst.id",
            [$actor->tenantId, $id, $id, $enrollment['learner_id']]);
        foreach ($rows as &$row) {
            foreach (['grade_snapshot', 'equivalence_snapshot'] as $field) {
                $row[$field] = $row[$field] === null ? null : json_decode($row[$field], true, 32, JSON_THROW_ON_ERROR);
            }
        }
        unset($row);
        return ['items' => $rows];
    }

    private function wasEnterpriseFunded(int $tenant, int $enrollment): bool
    {
        return $this->store->one('WITH RECURSIVE lineage AS (
                SELECT id, transferred_from_id FROM enrollments WHERE tenant_id=? AND id=?
                UNION ALL SELECT e.id, e.transferred_from_id FROM enrollments e
                    JOIN lineage l ON l.transferred_from_id=e.id WHERE e.tenant_id=?
            ) SELECT a.id FROM lineage l JOIN seat_assignments a ON a.tenant_id=? AND a.enrollment_id=l.id LIMIT 1',
            [$tenant, $enrollment, $tenant, $tenant]) !== null;
    }

    private function gradeSnapshot(int $tenant, int $enrollment): string
    {
        // One statement captures both grade sets at the same consistent read point.
        return $this->store->one("SELECT JSON_OBJECT(
            'exams', COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT('attempt_id', a.id, 'sitting_id', s.id,
                'title', s.title, 'sequence', s.sequence, 'score', a.score, 'pass_score', s.pass_score, 'retake_mode', s.retake_mode))
                FROM exam_attempts a JOIN exam_sittings s ON s.tenant_id=a.tenant_id AND s.id=a.sitting_id
                WHERE a.tenant_id=e.tenant_id AND a.enrollment_id=e.id AND a.graded_at IS NOT NULL), JSON_ARRAY()),
            'coursework', COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT('submission_id', s.id, 'assignment_id', a.id,
                'title', a.title, 'revision', s.revision, 'score', g.score))
                FROM coursework_submissions s JOIN coursework_grades g ON g.tenant_id=s.tenant_id AND g.submission_id=s.id
                JOIN coursework_assignments a ON a.tenant_id=s.tenant_id AND a.id=s.assignment_id
                WHERE s.tenant_id=e.tenant_id AND s.enrollment_id=e.id), JSON_ARRAY())) AS grades
            FROM enrollments e WHERE e.tenant_id=? AND e.id=?", [$tenant, $enrollment])['grades'];
    }

    public function detail(Actor $actor, int $enrollmentId): array
    {
        return $this->ownOrManaged($actor, $enrollmentId, true);
    }

    public function mine(Actor $actor, int $after): array
    {
        $rows = $this->store->all(
            'SELECT e.id, e.cohort_id, e.state, e.enrolled_at, e.closed_at, e.version, c.name AS cohort_name, c.code AS cohort_code
               FROM enrollments e JOIN cohorts c ON c.tenant_id=e.tenant_id AND c.id=e.cohort_id
              WHERE e.tenant_id=? AND e.learner_id=? AND e.id>? ORDER BY e.id LIMIT 51',
            [$actor->tenantId, $actor->accountId, $after]
        );
        return $this->page($rows);
    }

    public function roster(Actor $actor, int $cohortId, int $after): array
    {
        $actor->requireManagement();
        $this->cohortRow($actor, $cohortId);
        $rows = $this->store->all(
            'SELECT e.id, e.learner_id, e.state, e.enrolled_at, e.closed_at, e.version, a.name AS learner_name
               FROM enrollments e JOIN accounts a ON a.tenant_id=e.tenant_id AND a.id=e.learner_id
              WHERE e.tenant_id=? AND e.cohort_id=? AND e.id>? ORDER BY e.id LIMIT 51',
            [$actor->tenantId, $cohortId, $after]
        );
        return $this->page($rows);
    }

    private function cohortRow(Actor $actor, int $id, bool $lock = false): array
    {
        $row = $this->store->one('SELECT ' . self::COHORT_FIELDS . ' FROM cohorts WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$actor->tenantId, $id]);
        return $row ?? throw new Problem(404, 'cohort_not_found');
    }

    private function ownOrManaged(Actor $actor, int $id, bool $includeWaitlistPosition = false, bool $lock = false): array
    {
        $position = $includeWaitlistPosition ? ", CASE WHEN e.state='waitlisted' THEN (
            SELECT COUNT(*) FROM enrollments w WHERE w.tenant_id=e.tenant_id AND w.cohort_id=e.cohort_id AND w.state='waitlisted' AND w.id<=e.id
        ) END AS waitlist_position" : '';
        $row = $this->store->one('SELECT ' . self::ENROLLMENT_FIELDS . $position . ' FROM enrollments e WHERE e.tenant_id=? AND e.id=?' . ($lock ? ' FOR UPDATE' : ''), [$actor->tenantId, $id]);
        if ($row === null || (!$actor->managesCatalog() && $row['learner_id'] !== $actor->accountId)) {
            throw new Problem(404, 'enrollment_not_found');
        }
        return $row;
    }

    private function activeEnrollment(Actor $actor, int $cohortId, int $learnerId): ?array
    {
        return $this->store->one('SELECT id FROM enrollments WHERE tenant_id=? AND cohort_id=? AND active_learner_id=?', [$actor->tenantId, $cohortId, $learnerId]);
    }

    private function occupied(Actor $actor, int $cohortId): int
    {
        return $this->store->one('SELECT COUNT(*) AS total FROM enrollments WHERE tenant_id=? AND cohort_id=? AND state IN (' . self::OCCUPIED . ')', [$actor->tenantId, $cohortId])['total'];
    }

    private function insertEnrollment(Actor $actor, int $cohortId, int $learnerId, string $state, ?int $from = null): int
    {
        return $this->store->insert(
            "INSERT INTO enrollments (tenant_id, cohort_id, learner_id, state, transferred_from_id, enrolled_at) VALUES (?, ?, ?, ?, ?, IF(?='enrolled', UTC_TIMESTAMP(6), NULL))",
            [$actor->tenantId, $cohortId, $learnerId, $state, $from, $state]
        );
    }

    private function close(Actor $actor, int $id, string $state): void
    {
        $this->store->execute('UPDATE enrollments SET state=?, closed_at=UTC_TIMESTAMP(6), version=version+1 WHERE tenant_id=? AND id=?', [$state, $actor->tenantId, $id]);
    }

    private function promote(Actor $actor, int $cohortId, int $count): array
    {
        if ($count === 0) {
            return [];
        }
        $next = $this->store->all("SELECT id FROM enrollments WHERE tenant_id=? AND cohort_id=? AND state='waitlisted' ORDER BY id LIMIT ?", [$actor->tenantId, $cohortId, $count]);
        $ids = array_column($next, 'id');
        foreach ($ids as $id) {
            $this->store->execute("UPDATE enrollments SET state='enrolled', enrolled_at=UTC_TIMESTAMP(6), version=version+1 WHERE tenant_id=? AND id=?", [$actor->tenantId, $id]);
            $this->audit->record($actor, 'enrollment', $id, 'promoted', ['cohort_id' => $cohortId]);
        }
        return $ids;
    }

    private function page(array $rows): array
    {
        $more = count($rows) > 50;
        $items = array_slice($rows, 0, 50);
        return ['items' => $items, 'next_after' => $more ? $items[49]['id'] : null];
    }
}
