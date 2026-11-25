<?php
declare(strict_types=1);

namespace LearningCloud;

use PDOException;

final readonly class Retraining
{
    public function __construct(private Store $store, private Audit $audit)
    {
    }

    public function listing(Actor $actor, int $after, string $state): array
    {
        if (!$actor->managesCatalog() && !$actor->has('learner')) {
            throw new Problem(403, 'retraining_access_required');
        }
        $conditions = ['r.tenant_id=?', 'r.id>?'];
        $parameters = [$actor->tenantId, $after];
        if (!$actor->managesCatalog()) {
            $conditions[] = 'source.learner_id=?';
            $parameters[] = $actor->accountId;
        }
        if ($state !== 'all') {
            $conditions[] = 'r.state=?';
            $parameters[] = $state;
        }
        $rows = $this->store->all('SELECT r.id, r.cycle, r.state, r.due_at, r.created_at, r.target_enrollment_id, r.finished_at,
            k.serial AS original_serial, k.course_title, k.holder_name, source.id AS original_enrollment_id,
            source.cohort_id AS original_cohort_id, target.cohort_id AS target_cohort_id, target.state AS target_state,
            renewal.serial AS renewed_serial
            FROM retraining_tasks r
            JOIN credentials k ON k.tenant_id=r.tenant_id AND k.id=r.credential_id
            JOIN enrollments source ON source.tenant_id=k.tenant_id AND source.id=k.enrollment_id
            LEFT JOIN enrollments target ON target.tenant_id=r.tenant_id AND target.id=r.target_enrollment_id
            LEFT JOIN credentials renewal ON renewal.tenant_id=r.tenant_id AND renewal.id=r.renewed_credential_id
            WHERE ' . implode(' AND ', $conditions) . ' ORDER BY r.id LIMIT 51', $parameters);
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function assign(Actor $actor, int $id, array $input): array
    {
        $actor->requireManagement();
        $targetId = Input::integer($input, 'enrollment_id', 1);
        try {
            return $this->store->transaction(function () use ($actor, $id, $targetId): array {
                $tenant = $actor->tenantId;
                $target = $this->store->one('SELECT e.learner_id, e.cohort_id, e.state, e.enrolled_at, v.course_id
                    FROM enrollments e JOIN cohorts c ON c.tenant_id=e.tenant_id AND c.id=e.cohort_id
                    JOIN course_revisions v ON v.tenant_id=c.tenant_id AND v.id=c.revision_id
                    WHERE e.tenant_id=? AND e.id=? FOR UPDATE OF e', [$tenant, $targetId]);
                $task = $this->store->one('SELECT r.state, r.target_enrollment_id, e.learner_id, e.cohort_id, k.expires_at, v.course_id,
                    prior.state AS prior_state
                    FROM retraining_tasks r JOIN credentials k ON k.tenant_id=r.tenant_id AND k.id=r.credential_id
                    JOIN enrollments e ON e.tenant_id=k.tenant_id AND e.id=k.enrollment_id
                    JOIN cohorts c ON c.tenant_id=e.tenant_id AND c.id=e.cohort_id
                    JOIN course_revisions v ON v.tenant_id=c.tenant_id AND v.id=c.revision_id
                    LEFT JOIN enrollments prior ON prior.tenant_id=r.tenant_id AND prior.id=r.target_enrollment_id
                    WHERE r.tenant_id=? AND r.id=? FOR UPDATE OF r', [$tenant, $id])
                    ?? throw new Problem(404, 'retraining_task_not_found');
                if (in_array($task['state'], ['enrolled', 'completed'], true) && $task['target_enrollment_id'] === $targetId) {
                    return ['id' => $id, 'enrollment_id' => $targetId, 'state' => $task['state'], 'replayed' => true];
                }
                $replacement = $task['state'] === 'enrolled' && in_array($task['prior_state'], ['withdrawn', 'transferred'], true);
                if ($task['state'] !== 'pending' && !$replacement) {
                    throw new Problem(409, 'retraining_task_not_assignable');
                }
                if ($target === null || $target['cohort_id'] === $task['cohort_id'] || $target['course_id'] !== $task['course_id']
                    || $target['learner_id'] !== $task['learner_id'] || !in_array($target['state'], ['enrolled', 'studying'], true)
                    || $target['enrolled_at'] < $task['expires_at']) {
                    throw new Problem(422, 'invalid_retraining_enrollment');
                }
                $this->store->execute("UPDATE retraining_tasks SET state='enrolled', target_enrollment_id=? WHERE tenant_id=? AND id=?",
                    [$targetId, $tenant, $id]);
                $this->audit->record($actor, 'retraining_task', $id, $replacement ? 'enrollment_replaced' : 'enrollment_linked',
                    ['enrollment_id' => $targetId, 'previous_enrollment_id' => $task['target_enrollment_id']]);
                return ['id' => $id, 'enrollment_id' => $targetId, 'state' => 'enrolled', 'replayed' => false];
            });
        } catch (PDOException $error) {
            if (!Store::duplicate($error)) {
                throw $error;
            }
            throw new Problem(409, 'retraining_target_already_linked');
        }
    }

    public function sweep(int $limit): array
    {
        $rows = $this->store->all('SELECT k.tenant_id, k.id FROM credentials k
            WHERE k.revoked_at IS NULL AND k.expires_at<=UTC_TIMESTAMP(6) AND NOT EXISTS (
                SELECT 1 FROM retraining_tasks r WHERE r.tenant_id=k.tenant_id AND r.credential_id=k.id AND r.cycle=1)
            ORDER BY k.expires_at, k.id LIMIT ?', [$limit]);
        $created = 0;
        foreach ($rows as $candidate) {
            $created += $this->store->transaction(function () use ($candidate): int {
                $scope = [$candidate['tenant_id'], $candidate['id']];
                $certificate = $this->store->one('SELECT expires_at, revoked_at, expires_at<=UTC_TIMESTAMP(6) AS due
                    FROM credentials WHERE tenant_id=? AND id=? FOR UPDATE', $scope);
                if (!$certificate['due'] || $certificate['revoked_at'] !== null
                    || $this->store->one('SELECT id FROM retraining_tasks WHERE tenant_id=? AND credential_id=? AND cycle=1', $scope) !== null) {
                    return 0;
                }
                return $this->store->execute('INSERT INTO retraining_tasks (tenant_id, credential_id, cycle, due_at) VALUES (?, ?, 1, ?)',
                    [...$scope, $certificate['expires_at']]);
            });
        }
        return ['retraining_tasks_created' => $created];
    }
}
