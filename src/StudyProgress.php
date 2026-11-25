<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class StudyProgress
{
    public function __construct(private Store $store, private Audit $audit)
    {
    }

    public function progress(Actor $actor, int $enrollmentId): array
    {
        $enrollment = $this->enrollment($actor->tenantId, $enrollmentId);
        if (!$actor->managesCatalog() && $enrollment['learner_id'] !== $actor->accountId) {
            throw new Problem(404, 'enrollment_not_found');
        }
        return $this->totals($actor->tenantId, $enrollmentId, $enrollment['cohort_id']) + ['enrollment_state' => $enrollment['state']];
    }

    public function totals(int $tenantId, int $enrollmentId, int $cohortId): array
    {
        $chapters = $this->store->all(
            "SELECT ch.id, ch.ordinal, ch.title, ch.kind, ch.duration_seconds, ch.required_seconds, r.minimum_study_seconds,
                    COALESCE(SUM(s.end_second - s.start_second), 0) + 0 AS studied_seconds,
                    COALESCE(MAX(CASE WHEN s.start_second=0 THEN s.end_second END), 0) AS resume_second,
                    b.provider_id IS NOT NULL AS live_configured
               FROM cohorts c JOIN course_revisions r ON r.tenant_id=c.tenant_id AND r.id=c.revision_id
               JOIN chapters ch ON ch.tenant_id=r.tenant_id AND ch.revision_id=r.id
               LEFT JOIN study_live_bindings b ON b.tenant_id=c.tenant_id AND b.cohort_id=c.id AND b.chapter_id=ch.id
               LEFT JOIN study_spans s ON s.tenant_id=c.tenant_id AND s.enrollment_id=? AND s.chapter_id=ch.id
              WHERE c.tenant_id=? AND c.id=?
              GROUP BY ch.id, ch.ordinal, ch.title, ch.kind, ch.duration_seconds, ch.required_seconds, r.minimum_study_seconds, b.provider_id
              ORDER BY ch.ordinal",
            [$enrollmentId, $tenantId, $cohortId]
        );
        $seconds = 0;
        $complete = true;
        $minimum = (int) ($chapters[0]['minimum_study_seconds'] ?? 0);
        foreach ($chapters as &$chapter) {
            unset($chapter['minimum_study_seconds']);
            $chapter['studied_seconds'] = (int) $chapter['studied_seconds'];
            $seconds += $chapter['studied_seconds'];
            $chapter['study_requirement_met'] = $chapter['studied_seconds'] >= $chapter['required_seconds'];
            $complete = $complete && $chapter['study_requirement_met'];
        }
        unset($chapter);
        return ['enrollment_id' => $enrollmentId, 'studied_seconds' => $seconds, 'minimum_study_seconds' => $minimum,
            'study_requirement_met' => $complete && $seconds >= $minimum, 'chapters' => $chapters];
    }

    public function start(Actor $actor, int $enrollmentId, int $chapterId): array
    {
        return $this->store->transaction(function () use ($actor, $enrollmentId, $chapterId): array {
            $enrollment = $this->enrollment($actor->tenantId, $enrollmentId, true);
            $this->requireOwner($actor, $enrollment);
            $this->requireActive($enrollment);
            $chapter = $this->chapter($actor->tenantId, $enrollment['cohort_id'], $chapterId);
            $session = $this->store->one('SELECT id, resume_code FROM study_sessions WHERE tenant_id=? AND enrollment_id=? AND chapter_id=?',
                [$actor->tenantId, $enrollmentId, $chapterId]);
            if ($session === null) {
                $provider = null;
                if ($chapter['kind'] === 'live') {
                    $binding = $this->store->one('SELECT provider_id FROM study_live_bindings WHERE tenant_id=? AND cohort_id=? AND chapter_id=? FOR SHARE',
                        [$actor->tenantId, $enrollment['cohort_id'], $chapterId]);
                    $provider = $binding['provider_id'] ?? throw new Problem(409, 'live_provider_not_configured');
                }
                $code = bin2hex(random_bytes(24));
                $id = $this->store->insert('INSERT INTO study_sessions (tenant_id, enrollment_id, chapter_id, provider_id, resume_code) VALUES (?, ?, ?, ?, ?)',
                    [$actor->tenantId, $enrollmentId, $chapterId, $provider, $code]);
                $this->audit->record($actor, 'study_session', $id, 'started', ['enrollment_id' => $enrollmentId, 'chapter_id' => $chapterId]);
                $session = ['id' => $id, 'resume_code' => $code];
            }
            $span = $this->store->one('SELECT end_second FROM study_spans WHERE tenant_id=? AND enrollment_id=? AND chapter_id=? AND start_second=0',
                [$actor->tenantId, $enrollmentId, $chapterId]);
            return ['id' => $session['id'], 'channel' => $chapter['kind'], 'duration_seconds' => $chapter['duration_seconds'],
                'resume_second' => $span['end_second'] ?? 0] + ($chapter['kind'] === 'live' ? ['session_code' => $session['resume_code']] : []);
        });
    }

    public function browserInterval(Actor $actor, int $sessionId, array $input, string $key): array
    {
        [$start, $end] = $this->interval($input, 30);
        $digest = hash('sha256', "$start/$end");
        return $this->store->transaction(function () use ($actor, $sessionId, $start, $end, $key, $digest): array {
            $session = $this->session($actor->tenantId, $sessionId);
            $enrollment = $this->enrollment($actor->tenantId, $session['enrollment_id'], true);
            $this->requireOwner($actor, $enrollment);
            if ($session['provider_id'] !== null) {
                throw new Problem(403, 'live_provider_interval_required');
            }
            $prior = $this->store->one('SELECT id, state, request_digest FROM study_events WHERE tenant_id=? AND session_id=? AND external_id=?',
                [$actor->tenantId, $sessionId, $key]);
            if ($prior !== null) {
                $this->checkDigest($prior, $digest);
                return ['id' => $prior['id'], 'status' => $prior['state'], 'replayed' => true];
            }
            $this->requireActive($enrollment);
            $this->credit($actor->tenantId, $enrollment, $session, $start, $end);
            $id = $this->store->insert("INSERT INTO study_events (tenant_id, session_id, external_id, request_digest, start_second, end_second, state) VALUES (?, ?, ?, ?, ?, ?, 'accepted')",
                [$actor->tenantId, $sessionId, $key, $digest, $start, $end]);
            return ['id' => $id, 'status' => 'accepted', 'replayed' => false];
        });
    }

    public function enrollment(int $tenantId, int $id, bool $lock = false): array
    {
        $row = $this->store->one('SELECT id, cohort_id, learner_id, state FROM enrollments WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$tenantId, $id]);
        return $row ?? throw new Problem(404, 'enrollment_not_found');
    }

    public function chapter(int $tenantId, int $cohortId, int $chapterId): array
    {
        $row = $this->store->one('SELECT ch.id, ch.kind, ch.duration_seconds FROM chapters ch JOIN cohorts c
            ON c.tenant_id=ch.tenant_id AND c.revision_id=ch.revision_id WHERE c.tenant_id=? AND c.id=? AND ch.id=?',
            [$tenantId, $cohortId, $chapterId]);
        return $row ?? throw new Problem(404, 'study_chapter_not_found');
    }

    public function session(int $tenantId, int $sessionId): array
    {
        $row = $this->store->one('SELECT s.id, s.enrollment_id, s.chapter_id, s.provider_id, ch.duration_seconds FROM study_sessions s JOIN chapters ch
            ON ch.tenant_id=s.tenant_id AND ch.id=s.chapter_id WHERE s.tenant_id=? AND s.id=?', [$tenantId, $sessionId]);
        return $row ?? throw new Problem(404, 'study_session_not_found');
    }

    public function interval(array $input, int $maxLength): array
    {
        $start = Input::integer($input, 'start_second', 0, 86_399);
        $end = Input::integer($input, 'end_second', $start + 1, 86_400);
        if ($end - $start > $maxLength) {
            throw new Problem(422, 'study_interval_too_long');
        }
        return [$start, $end];
    }

    public function checkDigest(array $prior, string $digest): void
    {
        if (!hash_equals($prior['request_digest'], $digest)) {
            throw new Problem(409, 'request_key_reused');
        }
    }

    public function requireActive(array $enrollment): void
    {
        if (!in_array($enrollment['state'], ['enrolled', 'studying'], true)) {
            throw new Problem(409, 'enrollment_cannot_study');
        }
    }

    public function credit(int $tenantId, array $enrollment, array $session, int $start, int $end): void
    {
        if ($end > $session['duration_seconds']) {
            throw new Problem(422, 'study_interval_outside_chapter');
        }
        // The enrollment row lock makes this merge serial per learner and enrollment.
        $key = [$tenantId, $enrollment['id'], $session['chapter_id']];
        $overlap = 'tenant_id=? AND enrollment_id=? AND chapter_id=? AND start_second<=? AND end_second>=?';
        $bounds = $this->store->one('SELECT COUNT(*) AS spans, MIN(start_second) AS lo, MAX(end_second) AS hi FROM study_spans WHERE ' . $overlap, [...$key, $end, $start]);
        if ($bounds['lo'] !== null) {
            if ($bounds['spans'] === 1 && $bounds['lo'] <= $start && $bounds['hi'] >= $end) {
                return;
            }
            $this->store->execute('DELETE FROM study_spans WHERE ' . $overlap, [...$key, $end, $start]);
            $start = min($start, $bounds['lo']);
            $end = max($end, $bounds['hi']);
        }
        $this->store->execute('INSERT INTO study_spans (tenant_id, enrollment_id, chapter_id, start_second, end_second) VALUES (?, ?, ?, ?, ?)',
            [...$key, $start, $end]);
        if ($enrollment['state'] === 'enrolled') {
            $this->store->execute("UPDATE enrollments SET state='studying', version=version+1 WHERE tenant_id=? AND id=?", [$tenantId, $enrollment['id']]);
        }
    }

    private function requireOwner(Actor $actor, array $enrollment): void
    {
        if ($enrollment['learner_id'] !== $actor->accountId || !$actor->has('learner')) {
            throw new Problem(404, 'enrollment_not_found');
        }
    }
}
