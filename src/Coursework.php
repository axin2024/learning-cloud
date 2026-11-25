<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class Coursework
{
    public function __construct(private Store $store, private AssignmentCatalog $assignments, private TeachingStaff $staff, private Audit $audit)
    {
    }

    public function mine(Actor $learner, int $enrollmentId, int $after): array
    {
        $enrollment = $this->ownEnrollment($learner, $enrollmentId);
        $rows = $this->store->all(
            'SELECT t.id, t.title, t.due_at, t.grace_minutes, t.required_for_completion, t.pass_score, s.revision, g.score
               FROM coursework_assignments t LEFT JOIN coursework_submissions s ON s.id=(SELECT latest.id FROM coursework_submissions latest
                    WHERE latest.tenant_id=t.tenant_id AND latest.assignment_id=t.id AND latest.enrollment_id=? ORDER BY latest.revision DESC LIMIT 1)
               LEFT JOIN coursework_grades g ON g.tenant_id=s.tenant_id AND g.submission_id=s.id
              WHERE t.tenant_id=? AND t.cohort_id=? AND t.published_at IS NOT NULL AND t.id>? ORDER BY t.id LIMIT 51',
            [$enrollmentId, $learner->tenantId, $enrollment['cohort_id'], $after]
        );
        return $this->page($rows, 'id');
    }

    public function learnerHistory(Actor $learner, int $enrollmentId, int $assignmentId, int $after): array
    {
        $enrollment = $this->ownEnrollment($learner, $enrollmentId);
        $assignment = $this->publishedAssignment($learner, $enrollment, $assignmentId);
        $result = $this->history($learner->tenantId, $assignmentId, $enrollmentId, $after);
        $latest = $this->latest($learner->tenantId, $assignmentId, $enrollmentId);
        $permission = $this->permission($enrollment, $assignment, $latest, $this->now());
        return $result + ['assignment' => $assignment, 'can_submit' => $permission === null, 'blocked_reason' => $permission,
            'latest_revision' => $latest['revision'] ?? null];
    }

    public function submit(Actor $learner, int $enrollmentId, int $assignmentId, array $input, string $requestKey): array
    {
        $answer = $input['answer'] ?? null;
        if (!is_string($answer) || trim($answer) === '' || mb_strlen($answer) > 32000) {
            throw new Problem(422, 'invalid_assignment_answer');
        }
        $answer = str_replace(["\r\n", "\r"], "\n", $answer);
        return $this->store->transaction(function () use ($learner, $enrollmentId, $assignmentId, $answer, $requestKey): array {
            $enrollment = $this->ownEnrollment($learner, $enrollmentId, true);
            $assignment = $this->publishedAssignment($learner, $enrollment, $assignmentId);
            $prior = $this->store->one('SELECT id, answer, revision, submitted_at, late FROM coursework_submissions WHERE tenant_id=? AND assignment_id=? AND enrollment_id=? AND request_key=?',
                [$learner->tenantId, $assignmentId, $enrollmentId, $requestKey]);
            if ($prior !== null) {
                if ($prior['answer'] !== $answer) {
                    throw new Problem(409, 'request_key_reused');
                }
                unset($prior['answer']);
                return $prior + ['replayed' => true];
            }
            $latest = $this->latest($learner->tenantId, $assignmentId, $enrollmentId);
            $now = $this->now();
            $reason = $this->permission($enrollment, $assignment, $latest, $now);
            if ($reason !== null) {
                throw new Problem(409, $reason);
            }
            $late = $now > $assignment['due_at'];
            $revision = ($latest['revision'] ?? 0) + 1;
            $id = $this->store->insert('INSERT INTO coursework_submissions (tenant_id, assignment_id, enrollment_id, revision, answer, late, request_key, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$learner->tenantId, $assignmentId, $enrollmentId, $revision, $answer, $late, $requestKey, $now]);
            if ($latest !== null && $latest['expires_at'] !== null && $latest['expires_at'] >= $now && $latest['consumed_by'] === null) {
                $this->store->execute('UPDATE coursework_returns SET consumed_by=? WHERE tenant_id=? AND submission_id=?', [$id, $learner->tenantId, $latest['id']]);
            }
            $this->audit->record($learner, 'assignment', $assignmentId, 'submitted', ['submission_id' => $id, 'revision' => $revision, 'enrollment_id' => $enrollmentId, 'late' => $late]);
            return ['id' => $id, 'revision' => $revision, 'submitted_at' => $now, 'late' => (int) $late, 'replayed' => false];
        });
    }

    public function queue(Actor $marker, int $assignmentId, int $after): array
    {
        $this->publishedForMarker($marker, $assignmentId);
        $rows = $this->store->all('SELECT s.id, s.enrollment_id, s.revision, s.late, s.submitted_at, g.score, a.name AS learner_name
            FROM coursework_submissions s JOIN enrollments e ON e.tenant_id=s.tenant_id AND e.id=s.enrollment_id
            JOIN accounts a ON a.tenant_id=e.tenant_id AND a.id=e.learner_id
            LEFT JOIN coursework_grades g ON g.tenant_id=s.tenant_id AND g.submission_id=s.id
            WHERE s.tenant_id=? AND s.assignment_id=? AND s.enrollment_id>? AND NOT EXISTS (
                SELECT 1 FROM coursework_submissions newer WHERE newer.tenant_id=s.tenant_id AND newer.assignment_id=s.assignment_id
                    AND newer.enrollment_id=s.enrollment_id AND newer.revision>s.revision)
            ORDER BY s.enrollment_id LIMIT 51', [$marker->tenantId, $assignmentId, $after]);
        return $this->page($rows, 'enrollment_id');
    }

    public function markingHistory(Actor $marker, int $assignmentId, int $enrollmentId, int $after): array
    {
        $assignment = $this->publishedForMarker($marker, $assignmentId);
        $enrollment = $this->enrollment($marker->tenantId, $enrollmentId);
        if ($assignment['cohort_id'] !== $enrollment['cohort_id']) {
            throw new Problem(404, 'assignment_not_found');
        }
        return $this->history($marker->tenantId, $assignmentId, $enrollmentId, $after) + [
            'assignment' => $assignment, 'latest_revision' => $this->latest($marker->tenantId, $assignmentId, $enrollmentId)['revision'] ?? null,
            'active' => in_array($enrollment['state'], ['enrolled', 'studying'], true)];
    }

    public function grade(Actor $actor, int $submissionId, array $input): array
    {
        return $this->store->transaction(function () use ($actor, $submissionId, $input): array {
            [$assignment, $submission] = $this->scopeSubmission($actor, $submissionId);
            [$score, $feedback, $rubric, $comments] = $this->marks($assignment, $submission['answer'], $input);
            $digest = hash('sha256', json_encode([$score, $feedback, $rubric, $comments], JSON_THROW_ON_ERROR));
            $prior = $this->store->one('SELECT request_digest, marker_id FROM coursework_grades WHERE tenant_id=? AND submission_id=?', [$actor->tenantId, $submissionId]);
            if ($prior !== null) {
                if ($prior['marker_id'] !== $actor->accountId || !hash_equals($prior['request_digest'], $digest)) {
                    throw new Problem(409, 'submission_already_graded');
                }
                return ['id' => $submissionId, 'score' => $score, 'replayed' => true];
            }
            $this->store->execute('INSERT INTO coursework_grades (tenant_id, submission_id, marker_id, score, feedback, rubric_marks, annotations, request_digest)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$actor->tenantId, $submissionId, $actor->accountId, $score, $feedback,
                    json_encode($rubric, JSON_THROW_ON_ERROR), json_encode($comments, JSON_THROW_ON_ERROR), $digest]);
            $this->audit->record($actor, 'assignment', $assignment['id'], 'grade_published', ['submission_id' => $submissionId, 'score' => $score]);
            return ['id' => $submissionId, 'score' => $score, 'replayed' => false];
        });
    }

    public function allowReturn(Actor $actor, int $submissionId, array $input): array
    {
        $expires = Input::utcDateTime($input, 'expires_at');
        $note = Input::string($input, 'note', 4000);
        return $this->store->transaction(function () use ($actor, $submissionId, $expires, $note): array {
            [$task, $submission, $enrollment] = $this->scopeSubmission($actor, $submissionId);
            $grant = $this->store->one('SELECT marker_id, note, expires_at FROM coursework_returns WHERE tenant_id=? AND submission_id=?', [$actor->tenantId, $submissionId]);
            if ($grant !== null && $grant['marker_id'] === $actor->accountId && $grant['note'] === $note && $grant['expires_at'] === $expires) {
                return ['id' => $submissionId, 'expires_at' => $expires, 'replayed' => true];
            }
            if (!in_array($enrollment['state'], ['enrolled', 'studying'], true)) {
                throw new Problem(409, 'enrollment_cannot_submit_coursework');
            }
            $latest = $this->latest($actor->tenantId, $task['id'], $enrollment['id']);
            if ($latest['id'] !== $submissionId) {
                throw new Problem(409, 'submission_is_not_latest');
            }
            $now = $this->now();
            if ($expires <= $now) {
                throw new Problem(422, 'return_expiry_must_be_future');
            }
            if ($grant !== null) {
                if ($grant['expires_at'] >= $now) {
                    throw new Problem(409, 'assignment_return_already_open');
                }
                $this->store->execute('UPDATE coursework_returns SET marker_id=?, note=?, expires_at=?, granted_at=? WHERE tenant_id=? AND submission_id=?',
                    [$actor->accountId, $note, $expires, $now, $actor->tenantId, $submissionId]);
            } else {
                $this->store->execute('INSERT INTO coursework_returns (tenant_id, submission_id, marker_id, note, expires_at, granted_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$actor->tenantId, $submissionId, $actor->accountId, $note, $expires, $now]);
            }
            $this->audit->record($actor, 'assignment', $task['id'], 'resubmission_authorized', ['submission_id' => $submissionId, 'expires_at' => $expires, 'note' => $note]);
            return ['id' => $submission['id'], 'expires_at' => $expires, 'replayed' => false];
        });
    }

    private function marks(array $assignment, string $answer, array $input): array
    {
        $entries = $assignment['rubric'];
        $rubric = [];
        $score = 0;
        foreach (Input::list($input, 'marks', count($entries), count($entries)) as $index => $mark) {
            if (!is_array($mark)) {
                throw new Problem(422, 'invalid_assignment_mark');
            }
            $points = Input::integer($mark, 'points', 0, $entries[$index]['points']);
            $rubric[] = ['points' => $points, 'note' => Input::string(['note' => $mark['note'] ?? ''], 'note', 2000, 0)];
            $score += $points;
        }
        $comments = [];
        $lines = substr_count($answer, "\n") + 1;
        foreach (Input::list(['annotations' => $input['annotations'] ?? []], 'annotations', 0, 50) as $entry) {
            if (!is_array($entry)) {
                throw new Problem(422, 'invalid_assignment_annotation');
            }
            $first = Input::integer($entry, 'start_line', 1, $lines);
            $comments[] = ['start_line' => $first, 'end_line' => Input::integer($entry, 'end_line', $first, $lines), 'note' => Input::string($entry, 'note', 2000)];
        }
        return [$score, Input::string(['feedback' => $input['feedback'] ?? ''], 'feedback', 4000, 0), $rubric, $comments];
    }

    private function scopeSubmission(Actor $actor, int $id): array
    {
        $submission = $this->store->one('SELECT id, assignment_id, enrollment_id, answer FROM coursework_submissions WHERE tenant_id=? AND id=?', [$actor->tenantId, $id]);
        if ($submission === null) {
            throw new Problem(404, 'submission_not_found');
        }
        $task = $this->publishedForMarker($actor, $submission['assignment_id'], true);
        $enrollment = $this->enrollment($actor->tenantId, $submission['enrollment_id'], true);
        if ($task['cohort_id'] !== $enrollment['cohort_id']) {
            throw new Problem(404, 'submission_not_found');
        }
        return [$task, $submission, $enrollment];
    }

    private function history(int $tenant, int $assignment, int $enrollment, int $after): array
    {
        $rows = $this->store->all('SELECT s.id, s.revision, s.answer, s.submitted_at, s.late, g.score, g.feedback, g.rubric_marks, g.annotations,
            g.graded_at, marker.name AS marker_name, x.note AS return_note, x.expires_at AS return_expires_at, x.consumed_by AS return_consumed_by
            FROM coursework_submissions s LEFT JOIN coursework_grades g ON g.tenant_id=s.tenant_id AND g.submission_id=s.id
            LEFT JOIN coursework_returns x ON x.tenant_id=s.tenant_id AND x.submission_id=s.id
            LEFT JOIN accounts marker ON marker.tenant_id=g.tenant_id AND marker.id=g.marker_id
            WHERE s.tenant_id=? AND s.assignment_id=? AND s.enrollment_id=? AND s.revision>? ORDER BY s.revision LIMIT 51',
            [$tenant, $assignment, $enrollment, $after]);
        foreach ($rows as &$row) {
            foreach (['rubric_marks', 'annotations'] as $field) {
                $row[$field] = $row[$field] === null ? null : json_decode($row[$field], true, 32, JSON_THROW_ON_ERROR);
            }
        }
        unset($row);
        return $this->page($rows, 'revision');
    }

    private function latest(int $tenant, int $assignment, int $enrollment): ?array
    {
        return $this->store->one('SELECT s.id, s.revision, x.expires_at, x.consumed_by FROM coursework_submissions s
            LEFT JOIN coursework_returns x ON x.tenant_id=s.tenant_id AND x.submission_id=s.id
            WHERE s.tenant_id=? AND s.assignment_id=? AND s.enrollment_id=? ORDER BY s.revision DESC LIMIT 1', [$tenant, $assignment, $enrollment]);
    }

    private function permission(array $enrollment, array $task, ?array $latest, string $now): ?string
    {
        if (!in_array($enrollment['state'], ['enrolled', 'studying'], true)) {
            return 'enrollment_cannot_submit_coursework';
        }
        if ($now <= $task['due_at'] || ($latest === null && $now <= $task['grace_ends_at'])) {
            return null;
        }
        if ($latest !== null && $latest['expires_at'] !== null && $latest['expires_at'] >= $now && $latest['consumed_by'] === null) {
            return null;
        }
        return $latest === null ? 'assignment_submission_closed' : 'assignment_return_required';
    }

    private function publishedForMarker(Actor $actor, int $id, bool $lock = false): array
    {
        $task = $this->assignments->row($actor->tenantId, $id);
        $this->staff->requireMarker($actor, $task['cohort_id'], $lock);
        if ($task['published_at'] === null) {
            throw new Problem(404, 'assignment_not_found');
        }
        return $task;
    }

    private function publishedAssignment(Actor $actor, array $enrollment, int $assignmentId): array
    {
        $assignment = $this->assignments->row($actor->tenantId, $assignmentId);
        if ($assignment['cohort_id'] !== $enrollment['cohort_id'] || $assignment['published_at'] === null) {
            throw new Problem(404, 'assignment_not_found');
        }
        return $assignment;
    }

    private function ownEnrollment(Actor $actor, int $id, bool $lock = false): array
    {
        $row = $this->enrollment($actor->tenantId, $id, $lock);
        if (!$actor->has('learner') || $row['learner_id'] !== $actor->accountId || $row['enrolled_at'] === null) {
            throw new Problem(404, 'enrollment_not_found');
        }
        return $row;
    }

    private function enrollment(int $tenant, int $id, bool $lock = false): array
    {
        $row = $this->store->one('SELECT id, cohort_id, learner_id, state, enrolled_at FROM enrollments WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$tenant, $id]);
        return $row ?? throw new Problem(404, 'enrollment_not_found');
    }

    private function now(): string
    {
        return $this->store->one('SELECT UTC_TIMESTAMP(6) AS now_utc')['now_utc'];
    }

    private function page(array $rows, string $key): array
    {
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49][$key] : null];
    }
}
