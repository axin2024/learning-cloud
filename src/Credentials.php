<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class Credentials
{
    private const STATUS = "CASE WHEN k.revoked_at IS NOT NULL THEN 'revoked'
        WHEN k.expires_at<=UTC_TIMESTAMP(6) THEN 'expired' ELSE 'valid' END AS status";

    public function __construct(private Store $store, private StudyProgress $study, private Exams $exams, private Audit $audit)
    {
    }

    public function review(Actor $actor, int $id): array
    {
        $enrollment = $this->study->enrollment($actor->tenantId, $id);
        if (!$actor->managesCatalog() && (!$actor->has('learner') || $enrollment['learner_id'] !== $actor->accountId)) {
            throw new Problem(404, 'enrollment_not_found');
        }
        return $this->requirements($actor->tenantId, $enrollment)
            + ['credential' => $this->byEnrollment($actor->tenantId, $id), 'enrollment_state' => $enrollment['state']];
    }

    public function issue(Actor $actor, int $id, array $input): array
    {
        $actor->requireManagement();
        $validDays = Input::integer($input, 'valid_days', 1, 3650);
        return $this->store->transaction(function () use ($actor, $id, $validDays): array {
            $tenant = $actor->tenantId;
            $before = $this->study->enrollment($tenant, $id);
            $course = $this->store->one('SELECT r.title, r.revision, t.name AS issuer FROM cohorts c
                JOIN course_revisions r ON r.tenant_id=c.tenant_id AND r.id=c.revision_id
                JOIN tenants t ON t.id=c.tenant_id WHERE c.tenant_id=? AND c.id=? FOR SHARE OF c',
                [$tenant, $before['cohort_id']]);
            $enrollment = $this->study->enrollment($tenant, $id, true);
            $prior = $this->byEnrollment($tenant, $id);
            if ($prior !== null) {
                if ($prior['valid_days'] !== $validDays) {
                    throw new Problem(409, 'credential_issue_conflict', ['valid_days' => $prior['valid_days']]);
                }
                return $prior + ['replayed' => true];
            }
            $review = $this->requirements($tenant, $enrollment);
            if (!$review['can_issue']) {
                throw new Problem(409, 'credential_requirements_unmet', ['requirements' => $review['unmet']]);
            }
            $holder = $this->store->one('SELECT name FROM accounts WHERE tenant_id=? AND id=?', [$tenant, $enrollment['learner_id']])['name'];
            $times = $this->store->one('SELECT UTC_TIMESTAMP(6) AS issued, TIMESTAMPADD(DAY, ?, UTC_TIMESTAMP(6)) AS expires', [$validDays]);
            $serial = 'LC-' . strtoupper(bin2hex(random_bytes(16)));
            $certificate = $this->store->insert('INSERT INTO credentials (tenant_id, enrollment_id, serial, issuer_name, holder_name, course_title,
                course_revision, issued_by, issued_at, expires_at, valid_days, evidence) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tenant, $id, $serial, $course['issuer'], $holder, $course['title'], $course['revision'], $actor->accountId, $times['issued'],
                    $times['expires'], $validDays, json_encode($review, JSON_THROW_ON_ERROR)]);
            $this->store->execute("UPDATE enrollments SET state='completed', closed_at=?, version=version+1 WHERE tenant_id=? AND id=?",
                [$times['issued'], $tenant, $id]);
            $renewal = $this->store->one("SELECT id, credential_id FROM retraining_tasks WHERE tenant_id=? AND target_enrollment_id=? AND state='enrolled' FOR UPDATE",
                [$tenant, $id]);
            if ($renewal !== null) {
                $this->store->execute("UPDATE retraining_tasks SET state='completed', renewed_credential_id=?, finished_at=? WHERE tenant_id=? AND id=?",
                    [$certificate, $times['issued'], $tenant, $renewal['id']]);
                $this->audit->record($actor, 'retraining_task', $renewal['id'], 'completed', ['credential_id' => $certificate]);
            }
            $this->audit->record($actor, 'credential', $certificate, 'issued', ['enrollment_id' => $id,
                'exam_attempt_id' => $review['exam']['attempt_id'], 'retraining_task_id' => $renewal['id'] ?? null]);
            return $this->byEnrollment($tenant, $id) + ['replayed' => false];
        });
    }

    public function revoke(Actor $actor, int $id, array $input): array
    {
        $actor->requireManagement();
        $reason = Input::string($input, 'reason', 1000);
        return $this->store->transaction(function () use ($actor, $id, $reason): array {
            $tenant = $actor->tenantId;
            $prior = $this->store->one('SELECT enrollment_id, revoked_at, revoked_by, revocation_reason FROM credentials WHERE tenant_id=? AND id=? FOR UPDATE',
                [$tenant, $id]) ?? throw new Problem(404, 'credential_not_found');
            if ($prior['revoked_at'] !== null) {
                if ($prior['revoked_by'] !== $actor->accountId || $prior['revocation_reason'] !== $reason) {
                    throw new Problem(409, 'credential_already_revoked');
                }
                return $this->byEnrollment($tenant, $prior['enrollment_id']) + ['replayed' => true];
            }
            $this->store->execute('UPDATE credentials SET revoked_at=UTC_TIMESTAMP(6), revoked_by=?, revocation_reason=? WHERE tenant_id=? AND id=?',
                [$actor->accountId, $reason, $tenant, $id]);
            $this->store->execute("UPDATE retraining_tasks SET state='cancelled', finished_at=UTC_TIMESTAMP(6)
                WHERE tenant_id=? AND credential_id=? AND state IN ('pending','enrolled')", [$tenant, $id]);
            $this->audit->record($actor, 'credential', $id, 'revoked', ['enrollment_id' => $prior['enrollment_id'], 'reason' => $reason]);
            return $this->byEnrollment($tenant, $prior['enrollment_id']) + ['replayed' => false];
        });
    }

    public static function verify(Store $store, string $serial): array
    {
        return $store->one('SELECT k.serial, k.issuer_name, k.course_title, k.course_revision, k.issued_at, k.expires_at, ' . self::STATUS
            . ' FROM credentials k WHERE k.serial=?', [$serial]) ?? throw new Problem(404, 'credential_not_found');
    }

    private function requirements(int $tenant, array $enrollment): array
    {
        $id = $enrollment['id'];
        $study = $this->study->totals($tenant, $id, $enrollment['cohort_id']);
        $chapters = array_map(static fn (array $item): array => array_intersect_key($item, array_flip(
            ['id', 'title', 'required_seconds', 'studied_seconds', 'study_requirement_met'])), $study['chapters']);
        $required = $this->store->all('SELECT t.id, t.title, t.pass_score, s.id AS submission_id, s.revision, g.score
            FROM coursework_assignments t LEFT JOIN coursework_submissions s ON s.id=(SELECT recent.id FROM coursework_submissions recent
                WHERE recent.tenant_id=t.tenant_id AND recent.assignment_id=t.id AND recent.enrollment_id=? ORDER BY recent.revision DESC LIMIT 1)
            LEFT JOIN coursework_grades g ON g.tenant_id=s.tenant_id AND g.submission_id=s.id
            WHERE t.tenant_id=? AND t.cohort_id=? AND t.required_for_completion AND t.published_at IS NOT NULL ORDER BY t.id',
            [$id, $tenant, $enrollment['cohort_id']]);
        foreach ($required as &$assignment) {
            $assignment['passed'] = $assignment['score'] !== null && $assignment['score'] >= $assignment['pass_score'];
        }
        unset($assignment);
        // Read the pending flag before the result. Finalizing an exam can only remove a pending row under this enrollment lock.
        $pendingExam = $this->store->one('SELECT id FROM exam_attempts WHERE tenant_id=? AND enrollment_id=? AND graded_at IS NULL LIMIT 1',
            [$tenant, $id]) !== null;
        $exam = $this->exams->effective($tenant, $id);
        $unmet = [];
        if (!in_array($enrollment['state'], ['enrolled', 'studying'], true)) {
            $unmet[] = 'enrollment';
        }
        if (!$study['study_requirement_met']) {
            $unmet[] = 'study';
        }
        if (in_array(false, array_column($required, 'passed'), true)) {
            $unmet[] = 'coursework';
        }
        if ($pendingExam || $exam === null || !$exam['passed']) {
            $unmet[] = 'exam';
        }
        return ['can_issue' => $unmet === [], 'unmet' => $unmet, 'study' => [
            'studied_seconds' => $study['studied_seconds'], 'minimum_study_seconds' => $study['minimum_study_seconds'],
            'passed' => $study['study_requirement_met'], 'chapters' => $chapters,
        ], 'required_assignments' => $required, 'exam' => $exam, 'pending_exam' => $pendingExam];
    }

    private function byEnrollment(int $tenant, int $enrollment): ?array
    {
        $row = $this->store->one('SELECT k.id, k.serial, k.issuer_name, k.holder_name, k.course_title, k.course_revision, k.issued_at,
            k.expires_at, k.valid_days, k.evidence, k.revoked_at, k.revocation_reason, ' . self::STATUS . ',
            source.serial AS original_serial FROM credentials k
            LEFT JOIN retraining_tasks rt ON rt.tenant_id=k.tenant_id AND rt.renewed_credential_id=k.id
            LEFT JOIN credentials source ON source.tenant_id=rt.tenant_id AND source.id=rt.credential_id
            WHERE k.tenant_id=? AND k.enrollment_id=?', [$tenant, $enrollment]);
        if ($row !== null) {
            $row['evidence'] = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR);
        }
        return $row;
    }
}
