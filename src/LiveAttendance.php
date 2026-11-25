<?php
declare(strict_types=1);

namespace LearningCloud;

use PDOException;

final readonly class LiveAttendance
{
    public function __construct(private Store $store, private StudyProgress $study, private Audit $audit)
    {
    }

    public function providers(Actor $actor, int $after): array
    {
        $actor->requireManagement();
        return $this->page($this->store->all('SELECT id, code, name, created_at, rotated_at FROM attendance_providers WHERE tenant_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $after]));
    }

    public function register(Actor $actor, array $input): array
    {
        $this->requireAdmin($actor);
        $code = Input::slug($input, 'code');
        $name = Input::string($input, 'name', 160);
        $secret = bin2hex(random_bytes(32));
        try {
            return $this->store->transaction(function () use ($actor, $code, $name, $secret): array {
                $id = $this->store->insert('INSERT INTO attendance_providers (tenant_id, code, name, secret_hash) VALUES (?, ?, ?, ?)',
                    [$actor->tenantId, $code, $name, hash('sha256', $secret)]);
                $this->audit->record($actor, 'attendance_provider', $id, 'registered', ['code' => $code]);
                return ['id' => $id, 'code' => $code, 'name' => $name, 'token' => $secret];
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'attendance_provider_code_exists');
            }
            throw $error;
        }
    }

    public function rotate(Actor $actor, int $providerId): array
    {
        $this->requireAdmin($actor);
        return $this->store->transaction(function () use ($actor, $providerId): array {
            $secret = bin2hex(random_bytes(32));
            if (!$this->store->execute('UPDATE attendance_providers SET secret_hash=?, rotated_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',
                [hash('sha256', $secret), $actor->tenantId, $providerId])) {
                throw new Problem(404, 'attendance_provider_not_found');
            }
            $this->audit->record($actor, 'attendance_provider', $providerId, 'rotated');
            return ['id' => $providerId, 'token' => $secret];
        });
    }

    public function bindings(Actor $actor, int $cohortId): array
    {
        $actor->requireManagement();
        $this->cohort($actor, $cohortId);
        return ['items' => $this->store->all(
            "SELECT ch.id, ch.ordinal, ch.title, b.provider_id, p.name AS provider_name FROM cohorts c
               JOIN chapters ch ON ch.tenant_id=c.tenant_id AND ch.revision_id=c.revision_id
               LEFT JOIN study_live_bindings b ON b.tenant_id=c.tenant_id AND b.cohort_id=c.id AND b.chapter_id=ch.id
               LEFT JOIN attendance_providers p ON p.tenant_id=b.tenant_id AND p.id=b.provider_id
              WHERE c.tenant_id=? AND c.id=? AND ch.kind='live' ORDER BY ch.ordinal", [$actor->tenantId, $cohortId])];
    }

    public function bind(Actor $actor, int $cohortId, int $chapterId, array $input): array
    {
        $actor->requireManagement();
        $providerId = Input::integer($input, 'provider_id', 1);
        return $this->store->transaction(function () use ($actor, $cohortId, $chapterId, $providerId): array {
            $this->cohort($actor, $cohortId, true);
            $chapter = $this->study->chapter($actor->tenantId, $cohortId, $chapterId);
            if ($chapter['kind'] !== 'live') {
                throw new Problem(422, 'chapter_not_live');
            }
            if (!$this->store->one('SELECT id FROM attendance_providers WHERE tenant_id=? AND id=?', [$actor->tenantId, $providerId])) {
                throw new Problem(404, 'attendance_provider_not_found');
            }
            $key = [$actor->tenantId, $cohortId, $chapterId];
            $old = $this->store->one('SELECT provider_id FROM study_live_bindings WHERE tenant_id=? AND cohort_id=? AND chapter_id=? FOR UPDATE', $key);
            if (($old['provider_id'] ?? null) === $providerId) {
                return ['chapter_id' => $chapterId, 'provider_id' => $providerId];
            }
            if ($old !== null) {
                if ($this->store->one('SELECT s.id FROM study_sessions s JOIN enrollments e ON e.tenant_id=s.tenant_id AND e.id=s.enrollment_id
                    WHERE s.tenant_id=? AND e.cohort_id=? AND s.chapter_id=? LIMIT 1', $key)) {
                    throw new Problem(409, 'live_provider_already_in_use');
                }
                $this->store->execute('UPDATE study_live_bindings SET provider_id=? WHERE tenant_id=? AND cohort_id=? AND chapter_id=?', [$providerId, ...$key]);
            } else {
                $this->store->execute('INSERT INTO study_live_bindings (tenant_id, cohort_id, chapter_id, provider_id) VALUES (?, ?, ?, ?)', [...$key, $providerId]);
            }
            $this->audit->record($actor, 'cohort', $cohortId, 'attendance_provider_bound', ['chapter_id' => $chapterId, 'provider_id' => $providerId]);
            return ['chapter_id' => $chapterId, 'provider_id' => $providerId];
        });
    }

    public function submit(string $authorization, array $input): array
    {
        if (!preg_match('/\ABearer ([0-9a-f]{64})\z/i', $authorization, $parts)) {
            throw new Problem(401, 'attendance_provider_unauthorized');
        }
        $secret = hash('sha256', $parts[1]);
        $event = Input::requestKey(is_string($input['event_id'] ?? null) ? $input['event_id'] : null);
        $role = Input::choice($input, 'role', ['learner', 'instructor', 'host']);
        $code = $this->sessionCode($input['session_code'] ?? null, true);
        [$start, $end] = $this->study->interval($input, 86_400);
        $digest = hash('sha256', "$role/$code/$start/$end");
        $register = function () use ($secret, $event, $role, $code, $start, $end, $digest): array {
            $provider = $this->store->one('SELECT tenant_id, id FROM attendance_providers WHERE secret_hash=? FOR SHARE', [$secret]);
            if ($provider === null) {
                throw new Problem(401, 'attendance_provider_unauthorized');
            }
            $tenantId = $provider['tenant_id'];
            $providerId = $provider['id'];
            $prior = $this->store->one('SELECT id, state, reason, request_digest FROM study_events WHERE tenant_id=? AND provider_id=? AND external_id=?',
                [$tenantId, $providerId, $event]);
            if ($prior !== null) {
                $this->study->checkDigest($prior, $digest);
                return ['id' => $prior['id'], 'status' => $prior['state'], 'reason' => $prior['reason'], 'replayed' => true];
            }
            $sessionId = null;
            $reason = null;
            $state = 'rejected';
            if ($role === 'learner') {
                try {
                    $session = $this->resolve($tenantId, $providerId, $code);
                    $enrollment = $this->study->enrollment($tenantId, $session['enrollment_id'], true);
                    $this->study->requireActive($enrollment);
                    $this->study->credit($tenantId, $enrollment, $session, $start, $end);
                    $sessionId = $session['id'];
                    $state = 'accepted';
                } catch (Problem $error) {
                    $reason = $error->reason;
                    $state = 'review';
                }
            } else {
                $reason = 'attendance_role_not_learner';
            }
            $id = $this->store->insert('INSERT INTO study_events (tenant_id, session_id, provider_id, external_id, source_role, submitted_code, request_digest, start_second, end_second, state, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$tenantId, $sessionId, $providerId, $event, $role, $code, $digest, $start, $end, $state, $reason]);
            return ['id' => $id, 'status' => $state, 'reason' => $reason, 'replayed' => false];
        };
        try {
            return $this->store->transaction($register);
        } catch (PDOException $error) {
            if (!Store::duplicate($error)) {
                throw $error;
            }
            // The conflicting transaction committed its receipt. Re-read it after undoing any credit in this transaction.
            return $this->store->transaction($register);
        }
    }

    public function reviews(Actor $actor, int $after, string $state): array
    {
        $actor->requireManagement();
        return $this->page($this->store->all(
            'SELECT e.id, e.external_id, e.session_id, e.source_role, e.start_second, e.end_second, e.state, e.reason, e.received_at, e.review_note,
                    e.reviewed_at, p.name AS provider_name FROM study_events e JOIN attendance_providers p ON p.tenant_id=e.tenant_id AND p.id=e.provider_id
              WHERE e.tenant_id=? AND e.state=? AND e.id>? ORDER BY e.id LIMIT 51', [$actor->tenantId, $state, $after]));
    }

    public function review(Actor $actor, int $id, array $input): array
    {
        $actor->requireManagement();
        $decision = Input::choice($input, 'decision', ['accept', 'reject']);
        $note = Input::string($input, 'note', 1000);
        $code = $decision === 'accept' ? $this->sessionCode($input['session_code'] ?? null, false) : null;
        return $this->store->transaction(function () use ($actor, $id, $decision, $note, $code): array {
            $event = $this->store->one('SELECT id, provider_id, state, start_second, end_second FROM study_events WHERE tenant_id=? AND id=? AND provider_id IS NOT NULL FOR UPDATE',
                [$actor->tenantId, $id]);
            if ($event === null) {
                throw new Problem(404, 'attendance_event_not_found');
            }
            if ($event['state'] !== 'review') {
                throw new Problem(409, 'attendance_event_already_reviewed');
            }
            $sessionId = null;
            if ($decision === 'accept') {
                $session = $this->resolve($actor->tenantId, $event['provider_id'], $code);
                $enrollment = $this->study->enrollment($actor->tenantId, $session['enrollment_id'], true);
                $this->study->requireActive($enrollment);
                $this->study->credit($actor->tenantId, $enrollment, $session, $event['start_second'], $event['end_second']);
                $sessionId = $session['id'];
            }
            $state = $decision === 'accept' ? 'accepted' : 'rejected';
            $this->store->execute('UPDATE study_events SET state=?, session_id=?, reviewed_by=?, reviewed_at=UTC_TIMESTAMP(6), review_note=? WHERE tenant_id=? AND id=?',
                [$state, $sessionId, $actor->accountId, $note, $actor->tenantId, $id]);
            $this->audit->record($actor, 'attendance_event', $id, $state, ['session_id' => $sessionId]);
            return ['id' => $id, 'status' => $state, 'session_id' => $sessionId];
        });
    }

    private function resolve(int $tenantId, int $providerId, ?string $code): array
    {
        if ($code === null) {
            throw new Problem(422, 'attendance_session_code_missing');
        }
        $session = $this->store->one('SELECT id FROM study_sessions WHERE tenant_id=? AND resume_code=? AND provider_id=?', [$tenantId, $code, $providerId]);
        if ($session === null) {
            throw new Problem(404, 'attendance_session_unmatched');
        }
        return $this->study->session($tenantId, $session['id']);
    }

    private function sessionCode(mixed $value, bool $optional): ?string
    {
        if ($optional && ($value === null || $value === '')) {
            return null;
        }
        if (!is_string($value) || !preg_match('/\A[0-9a-f]{48}\z/', $value)) {
            throw new Problem(422, 'invalid_attendance_session_code');
        }
        return $value;
    }

    private function cohort(Actor $actor, int $id, bool $lock = false): void
    {
        if (!$this->store->one('SELECT id FROM cohorts WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$actor->tenantId, $id])) {
            throw new Problem(404, 'cohort_not_found');
        }
    }

    private function requireAdmin(Actor $actor): void
    {
        if (!$actor->has('admin')) {
            throw new Problem(403, 'admin_required');
        }
    }

    private function page(array $rows): array
    {
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }
}
