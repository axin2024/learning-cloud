<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class Exams
{
    public const EFFECTIVE_ORDER = "CASE WHEN s.retake_mode='highest' THEN a.score ELSE 0 END DESC, s.sequence DESC";
    public function __construct(private Store $store, private ExamCatalog $catalog, private TeachingStaff $staff, private Audit $audit)
    {
    }

    public function enrollment(Actor $actor, int $id): array
    {
        $enrollment = $this->ownEnrollment($actor, $id);
        $rows = $this->store->all('SELECT s.id, s.title, s.sequence, s.opens_at, s.closes_at, s.duration_seconds, p.revision AS paper_revision,
            a.id AS attempt_id, a.deadline_at, a.answer_version, a.submitted_at, a.score, a.graded_at
            FROM exam_sittings s JOIN exam_papers p ON p.tenant_id=s.tenant_id AND p.id=s.paper_id
            LEFT JOIN exam_attempts a ON a.tenant_id=s.tenant_id AND a.sitting_id=s.id AND a.enrollment_id=?
            WHERE s.tenant_id=? AND s.cohort_id=? ORDER BY s.sequence',
            [$id, $actor->tenantId, $enrollment['cohort_id']]);
        $now = $this->now();
        $priorGrade = false;
        foreach ($rows as &$row) {
            $row['can_start'] = $row['attempt_id'] === null && $this->active($enrollment)
                && $now >= $row['opens_at'] && $now < $row['closes_at'] && ($row['sequence'] === 1 || $priorGrade);
            $priorGrade = $priorGrade || $row['graded_at'] !== null;
        }
        unset($row);
        return ['items' => $rows, 'effective_result' => $this->effective($actor->tenantId, $id)];
    }

    public function start(Actor $actor, int $enrollmentId, int $sittingId): array
    {
        return $this->store->transaction(function () use ($actor, $enrollmentId, $sittingId): array {
            $tenant = $actor->tenantId;
            $enrollment = $this->ownEnrollment($actor, $enrollmentId, true);
            $sitting = $this->catalog->sitting($tenant, $sittingId);
            if ($sitting['cohort_id'] !== $enrollment['cohort_id']) {
                throw new Problem(404, 'exam_sitting_not_found');
            }
            $existing = $this->store->one('SELECT id FROM exam_attempts WHERE tenant_id=? AND enrollment_id=? AND sitting_id=?', [$tenant, $enrollmentId, $sittingId]);
            if ($existing !== null) {
                return $this->view($tenant, $this->attempt($tenant, $existing['id'], 'FOR SHARE'), false, $this->active($enrollment)) + ['replayed' => true];
            }
            if (!$this->active($enrollment)) {
                throw new Problem(409, 'enrollment_cannot_take_exam');
            }
            if ($sitting['sequence'] > 1 && !$this->store->one('SELECT a.id FROM exam_attempts a
                JOIN exam_sittings s ON s.tenant_id=a.tenant_id AND s.id=a.sitting_id
                WHERE a.tenant_id=? AND a.enrollment_id=? AND a.graded_at IS NOT NULL AND s.sequence<? LIMIT 1',
                [$tenant, $enrollmentId, $sitting['sequence']])) {
                throw new Problem(409, 'exam_retake_requires_prior_grade');
            }
            $paper = $this->catalog->paperRow($tenant, $sitting['paper_id']);
            $seed = bin2hex(random_bytes(16));
            $items = $this->draw($paper['pools'], $seed);
            $time = $this->store->one('SELECT UTC_TIMESTAMP(6) AS started, TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)) AS duration_deadline',
                [$sitting['duration_seconds']]);
            if ($time['started'] < $sitting['opens_at'] || $time['started'] >= $sitting['closes_at']) {
                throw new Problem(409, 'exam_sitting_unavailable');
            }
            $deadline = min($time['duration_deadline'] ?? $sitting['closes_at'], $sitting['closes_at']);
            $id = $this->store->insert('INSERT INTO exam_attempts (tenant_id, enrollment_id, sitting_id, seed, started_at, deadline_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$tenant, $enrollmentId, $sittingId, $seed, $time['started'], $deadline]);
            foreach ($items as $index => $item) {
                $this->store->execute('INSERT INTO exam_items (tenant_id, attempt_id, ordinal, kind, prompt, options_json, guidance, points)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$tenant, $id, $index + 1, $item['kind'], $item['prompt'],
                        $item['options'] === null ? null : json_encode($item['options'], JSON_THROW_ON_ERROR),
                        json_encode($item['guidance'], JSON_THROW_ON_ERROR), $item['points']]);
            }
            $this->audit->record($actor, 'exam_attempt', $id, 'started', ['sitting_id' => $sittingId, 'paper_id' => $paper['id'], 'deadline_at' => $deadline]);
            return $this->view($tenant, $this->attempt($tenant, $id), false, true) + ['replayed' => false];
        });
    }

    public function roster(Actor $actor, int $sittingId, int $after): array
    {
        $sitting = $this->catalog->sitting($actor->tenantId, $sittingId);
        $this->staff->requireMarker($actor, $sitting['cohort_id']);
        $rows = $this->store->all('SELECT a.id, a.started_at, a.deadline_at, a.submitted_at, a.submission_mode, a.score, a.graded_at, u.name AS learner_name
            FROM exam_attempts a JOIN enrollments e ON e.tenant_id=a.tenant_id AND e.id=a.enrollment_id
            JOIN accounts u ON u.tenant_id=e.tenant_id AND u.id=e.learner_id
            WHERE a.tenant_id=? AND a.sitting_id=? AND a.id>? ORDER BY a.id LIMIT 51', [$actor->tenantId, $sittingId, $after]);
        return ['sitting' => $sitting, 'items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function detail(Actor $actor, int $id, bool $asMarker): array
    {
        return $this->store->transaction(function () use ($actor, $id, $asMarker): array {
            $tenant = $actor->tenantId;
            $attempt = $this->attempt($tenant, $id, 'FOR SHARE');
            if ($asMarker) {
                $this->staff->requireMarker($actor, $attempt['cohort_id']);
                if ($attempt['submitted_at'] === null) {
                    throw new Problem(409, 'exam_attempt_not_submitted');
                }
                $assignment = $this->markingAssignment($tenant, $id);
                $activeAssignment = $assignment !== null && $assignment['closed_at'] === null;
                $view = $this->view($tenant, $attempt, true, false);
                $canAssign = $view['can_mark'] && (!$activeAssignment || $actor->managesCatalog() || $assignment['assigned_by'] === $actor->accountId);
                $view['can_mark'] = $view['can_mark'] && (!$activeAssignment || $assignment['assigned_to'] === $actor->accountId);
                return $view + [
                    'marking_assignment' => $assignment,
                    'marker_id' => $attempt['marker_id'],
                    'can_assign_marking' => $canAssign,
                    'marking_candidates' => $canAssign ? $this->staff->examMarkers($actor, $attempt['cohort_id'], $attempt['learner_id']) : [],
                ];
            }
            $enrollment = $this->ownEnrollment($actor, $attempt['enrollment_id']);
            return $this->view($tenant, $attempt, false, $this->active($enrollment));
        });
    }

    public function save(Actor $actor, int $id, array $input): array
    {
        $version = Input::integer($input, 'answer_version', 0, 4294967294);
        $changes = Input::list($input, 'answers', 1, 100);
        return $this->store->transaction(function () use ($actor, $id, $version, $changes): array {
            [$attempt, $enrollment] = $this->lockOwn($actor, $id);
            $items = array_column($this->items($actor->tenantId, $id), null, 'ordinal');
            $values = [];
            foreach ($changes as $change) {
                if (!is_array($change)) {
                    throw new Problem(422, 'invalid_exam_answer');
                }
                $ordinal = Input::integer($change, 'ordinal', 1, count($items));
                if (array_key_exists($ordinal, $values) || !array_key_exists('answer', $change)) {
                    throw new Problem(422, 'invalid_exam_answer');
                }
                $values[$ordinal] = $this->answer($items[$ordinal], $change['answer']);
            }
            ksort($values);
            $digest = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            if ($attempt['answer_version'] === $version + 1 && $attempt['save_digest'] === $digest) {
                return ['id' => $id, 'answer_version' => $attempt['answer_version'], 'replayed' => true];
            }
            if (!$this->active($enrollment)) {
                throw new Problem(409, 'enrollment_cannot_take_exam');
            }
            if ($attempt['submitted_at'] !== null || $this->now() >= $attempt['deadline_at']) {
                throw new Problem(409, 'exam_answers_closed');
            }
            $this->checkVersion($attempt, $version);
            foreach ($values as $ordinal => $answer) {
                $this->store->execute('UPDATE exam_items SET answer=? WHERE tenant_id=? AND attempt_id=? AND ordinal=?',
                    [$answer === null ? null : json_encode($answer, JSON_THROW_ON_ERROR), $actor->tenantId, $id, $ordinal]);
            }
            $this->store->execute('UPDATE exam_attempts SET answer_version=answer_version+1, save_digest=? WHERE tenant_id=? AND id=?',
                [$digest, $actor->tenantId, $id]);
            return ['id' => $id, 'answer_version' => $version + 1, 'replayed' => false];
        });
    }

    public function submit(Actor $actor, int $id, array $input): array
    {
        $version = Input::integer($input, 'answer_version', 0);
        return $this->store->transaction(function () use ($actor, $id, $version): array {
            [$attempt, $enrollment] = $this->lockOwn($actor, $id);
            $now = $this->now();
            if ($attempt['submitted_at'] !== null) {
                $this->checkVersion($attempt, $version);
                return $this->receipt($attempt) + ['replayed' => true];
            }
            if ($now >= $attempt['deadline_at']) {
                $attempt = $this->finalize($actor->tenantId, $attempt, $now, 'expired');
                return $this->receipt($attempt) + ['replayed' => false];
            }
            if (!$this->active($enrollment)) {
                throw new Problem(409, 'enrollment_cannot_take_exam');
            }
            $this->checkVersion($attempt, $version);
            $attempt = $this->finalize($actor->tenantId, $attempt, $now, 'manual');
            $this->audit->record($actor, 'exam_attempt', $id, 'submitted', ['answer_version' => $version, 'sitting_id' => $attempt['sitting_id']]);
            return $this->receipt($attempt) + ['replayed' => false];
        });
    }

    public function assignMarking(Actor $actor, int $id, array $input): array
    {
        if (!array_key_exists('assigned_to', $input) || !array_key_exists('expected_assignment_id', $input)) {
            throw new Problem(422, 'invalid_exam_marking_assignment');
        }
        $delegate = $input['assigned_to'] === null ? null : Input::integer($input, 'assigned_to', 1);
        $expected = $input['expected_assignment_id'] === null ? null : Input::integer($input, 'expected_assignment_id', 1);
        return $this->store->transaction(function () use ($actor, $id, $delegate, $expected): array {
            $attempt = $this->lockForMarking($actor, $id);
            if ($attempt['graded_at'] !== null) {
                throw new Problem(409, 'exam_attempt_already_graded');
            }
            $tenant = $actor->tenantId;
            $assignment = $this->markingAssignment($tenant, $id);
            if (($assignment['id'] ?? null) !== $expected) {
                throw new Problem(409, 'exam_marking_assignment_changed');
            }
            $active = $assignment !== null && $assignment['closed_at'] === null;
            if ($active && !$actor->managesCatalog() && $assignment['assigned_by'] !== $actor->accountId) {
                throw new Problem(403, 'exam_marking_assignment_managed_by_grantor');
            }
            if ($delegate === null && !$active) {
                throw new Problem(409, 'exam_marking_assignment_inactive');
            }
            if ($delegate !== null) {
                if (!$this->staff->examMarkers($actor, $attempt['cohort_id'], $attempt['learner_id'], $delegate)) {
                    throw new Problem(422, 'exam_marking_delegate_ineligible');
                }
                if ($active && $assignment['assigned_to'] === $delegate) {
                    return $assignment;
                }
            }
            if ($active) {
                $this->closeMarkingAssignment($tenant, $assignment['id'], $delegate === null ? 'revoked' : 'replaced');
            }
            if ($delegate === null) {
                $this->audit->record($actor, 'exam_attempt', $id, 'marking_assignment_revoked', ['assignment_id' => $assignment['id']]);
            } else {
                $assigned = $this->store->insert('INSERT INTO exam_marking_assignments (tenant_id, attempt_id, assigned_by, assigned_to) VALUES (?, ?, ?, ?)',
                    [$tenant, $id, $actor->accountId, $delegate]);
                $this->audit->record($actor, 'exam_attempt', $id, 'marking_assigned', [
                    'assignment_id' => $assigned, 'assigned_to' => $delegate, 'replaces' => $active ? $assignment['id'] : null,
                ]);
            }
            return $this->markingAssignment($tenant, $id);
        });
    }

    public function grade(Actor $actor, int $id, array $input): array
    {
        $assignmentId = isset($input['marking_assignment_id']) ? Input::integer($input, 'marking_assignment_id', 1) : null;
        return $this->store->transaction(function () use ($actor, $id, $input, $assignmentId): array {
            $tenant = $actor->tenantId;
            $attempt = $this->lockForMarking($actor, $id);
            $items = $this->items($tenant, $id);
            $written = array_column(array_filter($items, static fn (array $item) => $item['kind'] === 'written' && $item['answer'] !== null), null, 'ordinal');
            if ($written === []) {
                throw new Problem(409, 'exam_no_written_marking');
            }
            $marks = [];
            foreach (Input::list($input, 'marks', count($written), count($written)) as $entry) {
                if (!is_array($entry)) {
                    throw new Problem(422, 'invalid_exam_mark');
                }
                $ordinal = Input::integer($entry, 'ordinal', 1, count($items));
                if (!isset($written[$ordinal]) || isset($marks[$ordinal])) {
                    throw new Problem(422, 'invalid_exam_mark');
                }
                $marks[$ordinal] = [
                    'points' => Input::integer($entry, 'points', 0, $written[$ordinal]['points']),
                    'feedback' => Input::string(['feedback' => $entry['feedback'] ?? ''], 'feedback', 4000, 0),
                ];
            }
            ksort($marks);
            $digest = hash('sha256', json_encode($assignmentId === null ? $marks : [$marks, $assignmentId], JSON_THROW_ON_ERROR));
            if ($attempt['graded_at'] !== null) {
                if ($attempt['marker_id'] === $actor->accountId && $attempt['grade_digest'] === $digest) {
                    return $this->receipt($attempt) + ['replayed' => true];
                }
                throw new Problem(409, 'exam_attempt_already_graded');
            }
            $assignment = $this->markingAssignment($tenant, $id);
            $activeId = ($assignment['closed_at'] ?? null) === null ? ($assignment['id'] ?? null) : null;
            if ($activeId !== $assignmentId) {
                throw new Problem(409, 'exam_marking_assignment_changed');
            }
            if ($activeId !== null && $assignment['assigned_to'] !== $actor->accountId) {
                throw new Problem(403, 'exam_marking_assigned_elsewhere');
            }
            foreach ($marks as $ordinal => $mark) {
                $this->store->execute('UPDATE exam_items SET awarded=?, feedback=? WHERE tenant_id=? AND attempt_id=? AND ordinal=?',
                    [$mark['points'], $mark['feedback'], $tenant, $id, $ordinal]);
            }
            $score = $this->total($tenant, $id);
            $this->store->execute('UPDATE exam_attempts SET score=?, graded_at=UTC_TIMESTAMP(6), marker_id=?, grade_digest=? WHERE tenant_id=? AND id=?',
                [$score, $actor->accountId, $digest, $tenant, $id]);
            $metadata = ['score' => $score];
            if ($activeId !== null) {
                $this->closeMarkingAssignment($tenant, $activeId, 'completed');
                $metadata += ['assignment_id' => $activeId, 'assigned_by' => $assignment['assigned_by']];
            }
            $this->audit->record($actor, 'exam_attempt', $id, 'grade_published', $metadata);
            return $this->receipt($this->attempt($tenant, $id)) + ['replayed' => false];
        });
    }

    private function lockForMarking(Actor $actor, int $id): array
    {
        $scope = $this->attempt($actor->tenantId, $id);
        $this->staff->requireMarker($actor, $scope['cohort_id'], true);
        $attempt = $this->attempt($actor->tenantId, $id, 'FOR UPDATE');
        if ($attempt['submitted_at'] === null) {
            throw new Problem(409, 'exam_attempt_not_submitted');
        }
        return $attempt;
    }

    private function markingAssignment(int $tenant, int $attempt): ?array
    {
        return $this->store->one('SELECT m.id, m.assigned_by, m.assigned_to, m.assigned_at, m.closed_at, m.closed_reason,
                grantor.name AS assigned_by_name, delegate.name AS assigned_to_name
            FROM exam_marking_assignments m JOIN accounts grantor ON grantor.tenant_id=m.tenant_id AND grantor.id=m.assigned_by
            JOIN accounts delegate ON delegate.tenant_id=m.tenant_id AND delegate.id=m.assigned_to
            WHERE m.tenant_id=? AND m.attempt_id=? ORDER BY m.id DESC LIMIT 1', [$tenant, $attempt]);
    }

    private function closeMarkingAssignment(int $tenant, int $id, string $reason): void
    {
        $this->store->execute('UPDATE exam_marking_assignments SET closed_at=UTC_TIMESTAMP(6), closed_reason=? WHERE tenant_id=? AND id=?',
            [$reason, $tenant, $id]);
    }

    public function sweep(int $limit): array
    {
        $rows = $this->store->all('SELECT tenant_id, id FROM exam_attempts
            WHERE submitted_at IS NULL AND deadline_at<=UTC_TIMESTAMP(6) ORDER BY deadline_at, id LIMIT ?', [$limit]);
        $completed = 0;
        foreach ($rows as $candidate) {
            $completed += $this->store->transaction(function () use ($candidate): int {
                $attempt = $this->attempt($candidate['tenant_id'], $candidate['id'], 'FOR UPDATE');
                if ($attempt['submitted_at'] !== null) {
                    return 0;
                }
                $now = $this->now();
                if ($now < $attempt['deadline_at']) {
                    return 0;
                }
                $this->finalize($candidate['tenant_id'], $attempt, $now, 'expired');
                return 1;
            });
        }
        return ['exam_attempts_finalized' => $completed];
    }

    public function effective(int $tenant, int $enrollment): ?array
    {
        $row = $this->store->one("SELECT a.id AS attempt_id, s.id AS sitting_id, s.sequence, a.score, s.pass_score, s.retake_mode
            FROM exam_attempts a JOIN exam_sittings s ON s.tenant_id=a.tenant_id AND s.id=a.sitting_id
            WHERE a.tenant_id=? AND a.enrollment_id=? AND a.graded_at IS NOT NULL
            ORDER BY " . self::EFFECTIVE_ORDER . " LIMIT 1", [$tenant, $enrollment]);
        return $row === null ? null : $row + ['passed' => $row['score'] >= $row['pass_score']];
    }

    private function finalize(int $tenant, array $attempt, string $now, string $mode): array
    {
        $id = $attempt['id'];
        $pending = false;
        foreach ($this->items($tenant, $id) as $item) {
            if ($item['kind'] === 'written' && $item['answer'] !== null) {
                $pending = true;
                continue;
            }
            $points = $item['kind'] !== 'written' && $item['answer'] === $item['guidance'] ? $item['points'] : 0;
            $this->store->execute('UPDATE exam_items SET awarded=? WHERE tenant_id=? AND attempt_id=? AND ordinal=?',
                [$points, $tenant, $id, $item['ordinal']]);
        }
        $score = $pending ? null : $this->total($tenant, $id);
        $this->store->execute('UPDATE exam_attempts SET submitted_at=?, submission_mode=?, score=?, graded_at=? WHERE tenant_id=? AND id=?',
            [$now, $mode, $score, $pending ? null : $now, $tenant, $id]);
        return $this->attempt($tenant, $id);
    }

    private function draw(array $pools, string $seed): array
    {
        $draw = [];
        foreach ($pools as $poolIndex => $pool) {
            $order = [];
            foreach ($pool['questions'] as $questionIndex => $question) {
                $order[$questionIndex] = hash_hmac('sha256', "$poolIndex:$questionIndex", $seed);
            }
            asort($order, SORT_STRING);
            foreach (array_slice(array_keys($order), 0, $pool['draw']) as $index) {
                $draw[] = $pool['questions'][$index] + ['points' => $pool['points']];
            }
        }
        return $draw;
    }

    private function answer(array $item, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($item['kind'] === 'written') {
            $answer = Input::string(['answer' => $value], 'answer', 5000, 0);
            return $answer === '' ? null : $answer;
        }
        $choices = ExamCatalog::choices(['answer' => $value], 'answer', count($item['options']),
            $item['kind'] === 'single' ? 1 : count($item['options']));
        return $choices === [] ? null : $choices;
    }

    private function view(int $tenant, array $attempt, bool $marker, bool $active): array
    {
        $now = $this->now();
        $items = $this->items($tenant, $attempt['id']);
        foreach ($items as &$item) {
            if (!$marker) {
                unset($item['guidance']);
                if ($attempt['graded_at'] === null) {
                    unset($item['awarded'], $item['feedback']);
                }
            }
        }
        unset($item);
        return $this->receipt($attempt) + [
            'enrollment_id' => $attempt['enrollment_id'], 'sitting_id' => $attempt['sitting_id'], 'title' => $attempt['title'],
            'paper_revision' => $attempt['paper_revision'], 'started_at' => $attempt['started_at'], 'deadline_at' => $attempt['deadline_at'],
            'server_time' => $now, 'can_save' => $active && $attempt['submitted_at'] === null && $now < $attempt['deadline_at'],
            'can_mark' => $marker && $attempt['submitted_at'] !== null && $attempt['graded_at'] === null,
            'items' => $items,
        ];
    }

    private function receipt(array $attempt): array
    {
        return array_intersect_key($attempt, array_flip(['id', 'answer_version', 'submitted_at', 'submission_mode', 'score', 'graded_at']));
    }

    private function items(int $tenant, int $attempt): array
    {
        $items = $this->store->all('SELECT ordinal, kind, prompt, options_json AS options, guidance, points, answer, awarded, feedback
            FROM exam_items WHERE tenant_id=? AND attempt_id=? ORDER BY ordinal', [$tenant, $attempt]);
        foreach ($items as &$item) {
            foreach (['options', 'guidance', 'answer'] as $field) {
                $item[$field] = $item[$field] === null ? null : json_decode($item[$field], true, 32, JSON_THROW_ON_ERROR);
            }
        }
        unset($item);
        return $items;
    }

    private function attempt(int $tenant, int $id, string $lock = ''): array
    {
        return $this->store->one('SELECT a.*, s.cohort_id, s.title, p.revision AS paper_revision, e.learner_id FROM exam_attempts a
            JOIN exam_sittings s ON s.tenant_id=a.tenant_id AND s.id=a.sitting_id
            JOIN exam_papers p ON p.tenant_id=s.tenant_id AND p.id=s.paper_id
            JOIN enrollments e ON e.tenant_id=a.tenant_id AND e.id=a.enrollment_id
            WHERE a.tenant_id=? AND a.id=?' . ($lock === '' ? '' : " $lock OF a"), [$tenant, $id])
            ?? throw new Problem(404, 'exam_attempt_not_found');
    }

    private function lockOwn(Actor $actor, int $id): array
    {
        $attempt = $this->attempt($actor->tenantId, $id);
        $enrollment = $this->ownEnrollment($actor, $attempt['enrollment_id'], true);
        return [$this->attempt($actor->tenantId, $id, 'FOR UPDATE'), $enrollment];
    }

    private function ownEnrollment(Actor $actor, int $id, bool $lock = false): array
    {
        $row = $this->store->one('SELECT id, cohort_id, state FROM enrollments WHERE tenant_id=? AND id=? AND learner_id=? AND enrolled_at IS NOT NULL'
            . ($lock ? ' FOR UPDATE' : ''), [$actor->tenantId, $id, $actor->accountId]);
        if (!$actor->has('learner') || $row === null) {
            throw new Problem(404, 'enrollment_not_found');
        }
        return $row;
    }

    private function active(array $enrollment): bool
    {
        return in_array($enrollment['state'], ['enrolled', 'studying'], true);
    }

    private function checkVersion(array $attempt, int $requested): void
    {
        if ($attempt['answer_version'] !== $requested) {
            throw new Problem(409, 'exam_answer_version_conflict', ['answer_version' => $attempt['answer_version']]);
        }
    }

    private function now(): string
    {
        return $this->store->one('SELECT UTC_TIMESTAMP(6) AS now_utc')['now_utc'];
    }

    private function total(int $tenant, int $attempt): int
    {
        return (int) $this->store->one('SELECT SUM(awarded) AS total FROM exam_items WHERE tenant_id=? AND attempt_id=?', [$tenant, $attempt])['total'];
    }
}
