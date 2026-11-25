<?php
declare(strict_types=1);

namespace LearningCloud;

use PDOException;

final readonly class SeatPurchases
{
    public function __construct(private Store $store, private Companies $companies, private Admissions $admissions, private Audit $audit)
    {
    }

    public function purchase(Actor $actor, int $companyId, array $input): array
    {
        $this->companies->requireSpaceAdmin($actor);
        $contract = Input::slug($input, 'contract_code');
        $course = Input::integer($input, 'course_id', 1);
        $total = Input::integer($input, 'total', 1, 100_000);
        $start = Input::date($input, 'starts_on');
        $end = Input::date($input, 'ends_on');
        if ($end < $start) {
            throw new Problem(422, 'invalid_contract_dates');
        }
        try {
            return $this->store->transaction(function () use ($actor, $companyId, $contract, $course, $total, $start, $end): array {
                $this->companies->lock($actor, $companyId, true);
                if (!$this->store->one('SELECT id FROM course_revisions WHERE tenant_id=? AND course_id=? AND published_at IS NOT NULL LIMIT 1', [$actor->tenantId, $course])) {
                    throw new Problem(404, 'published_course_not_found');
                }
                $id = $this->store->insert('INSERT INTO seat_pools (tenant_id, company_id, course_id, contract_code, total, starts_on, ends_on) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$actor->tenantId, $companyId, $course, $contract, $total, $start, $end]);
                $this->audit->record($actor, 'seat_pool', $id, 'purchased', ['company_id' => $companyId, 'course_id' => $course, 'total' => $total, 'contract_code' => $contract]);
                return $this->pool($actor, $companyId, $id);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'contract_course_exists');
            }
            throw $error;
        }
    }

    public function listing(Actor $actor, int $companyId, int $after): array
    {
        $this->companies->scope($actor, $companyId);
        $rows = $this->store->all(
            'SELECT id, course_id, contract_code, starts_on, ends_on, total FROM seat_pools
              WHERE tenant_id=? AND company_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $companyId, $after]
        );
        return $this->page($rows);
    }

    public function pool(Actor $actor, int $companyId, int $poolId): array
    {
        $this->companies->scope($actor, $companyId);
        // The grouped rows and remaining total are derived from the same statement snapshot.
        $rows = $this->store->all(
            "SELECT p.id, p.course_id, p.contract_code, p.starts_on, p.ends_on, p.total,
                    p.starts_on <= UTC_DATE() AND UTC_DATE() <= p.ends_on AS active,
                    d.id AS department_id, d.name AS department_name,
                    COUNT(CASE WHEN a.state='assigned' THEN 1 END) AS assigned,
                    COUNT(CASE WHEN a.state='activated' THEN 1 END) AS activated
               FROM seat_pools p LEFT JOIN company_departments d ON d.tenant_id=p.tenant_id AND d.company_id=p.company_id
               LEFT JOIN seat_assignments a ON a.tenant_id=p.tenant_id AND a.pool_id=p.id AND a.department_id=d.id AND a.state IN ('assigned','activated')
              WHERE p.tenant_id=? AND p.company_id=? AND p.id=?
              GROUP BY p.id, p.course_id, p.contract_code, p.starts_on, p.ends_on, p.total, d.id, d.name ORDER BY d.id",
            [$actor->tenantId, $companyId, $poolId]
        );
        if (!$rows) {
            throw new Problem(404, 'seat_pool_not_found');
        }
        $pool = array_diff_key($rows[0], array_flip(['department_id', 'department_name', 'assigned', 'activated']));
        $departments = [];
        foreach ($rows as $row) {
            if ($row['department_id'] !== null) {
                $departments[] = array_intersect_key($row, array_flip(['department_id', 'department_name', 'assigned', 'activated']));
            }
        }
        return $pool + ['available' => $pool['total'] - array_sum(array_column($departments, 'assigned')) - array_sum(array_column($departments, 'activated')), 'departments' => $departments];
    }

    public function assign(Actor $actor, int $companyId, int $poolId, array $input, string $key): array
    {
        $cohortId = Input::integer($input, 'cohort_id', 1);
        $rows = Input::list($input, 'rows', 1, 500);
        $requests = array_map(static fn (mixed $row): array => is_array($row) ? [$row['department_id'] ?? null, $row['learner_id'] ?? null] : [null, null], $rows);
        $digest = hash('sha256', json_encode([$cohortId, $requests], JSON_THROW_ON_ERROR));
        return $this->store->transaction(function () use ($actor, $companyId, $poolId, $cohortId, $requests, $key, $digest): array {
            $this->companies->lock($actor, $companyId, true);
            $pool = $this->lockPool($actor, $companyId, $poolId);
            $prior = $this->store->one('SELECT id, request_digest, receipt, created_at FROM seat_batches WHERE tenant_id=? AND pool_id=? AND actor_id=? AND request_key=?',
                [$actor->tenantId, $poolId, $actor->accountId, $key]);
            if ($prior !== null) {
                if ($prior['request_digest'] !== $digest) {
                    throw new Problem(409, 'request_key_reused');
                }
                return $this->receipt($prior) + ['replayed' => true];
            }
            $this->requireActive($pool);
            $cohort = $this->admissions->cohort($actor, $cohortId);
            if ($cohort['course_id'] !== $pool['course_id']) {
                throw new Problem(422, 'cohort_not_in_contract');
            }
            $remaining = $pool['total'] - $this->seatsUsed($actor, $poolId);
            $number = $pool['next_seat_number'];
            $items = [];
            foreach ($requests as $index => [$department, $learner]) {
                $item = ['row' => $index + 1];
                try {
                    $departmentId = Input::integer(['department_id' => $department], 'department_id', 1);
                    $learnerId = Input::integer(['learner_id' => $learner], 'learner_id', 1);
                    $item += ['department_id' => $departmentId, 'learner_id' => $learnerId];
                    $membership = $this->companies->membership($actor->tenantId, $companyId, $learnerId);
                    if (($membership['department_id'] ?? null) !== $departmentId) {
                        throw new Problem(422, 'learner_not_in_department');
                    }
                    $existing = $this->store->one('SELECT id, seat_number, cohort_id FROM seat_assignments WHERE tenant_id=? AND pool_id=? AND active_learner_id=?',
                        [$actor->tenantId, $poolId, $learnerId]);
                    if ($existing !== null) {
                        if ($existing['cohort_id'] !== $cohortId) {
                            throw new Problem(409, 'learner_already_assigned_elsewhere');
                        }
                        $items[] = $item + ['outcome' => 'duplicate', 'assignment_id' => $existing['id'], 'seat_number' => $existing['seat_number']];
                        continue;
                    }
                    if ($remaining === 0) {
                        throw new Problem(409, 'contract_quota_exhausted');
                    }
                    $id = $this->store->insert('INSERT INTO seat_assignments (tenant_id, company_id, pool_id, department_id, learner_id, cohort_id, seat_number) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$actor->tenantId, $companyId, $poolId, $departmentId, $learnerId, $cohortId, $number]);
                    $this->audit->record($actor, 'seat_assignment', $id, 'assigned', ['pool_id' => $poolId, 'department_id' => $departmentId, 'learner_id' => $learnerId, 'cohort_id' => $cohortId]);
                    $items[] = $item + ['outcome' => 'assigned', 'assignment_id' => $id, 'seat_number' => $number++];
                    $remaining--;
                } catch (Problem $error) {
                    $items[] = $item + ['outcome' => 'rejected', 'reason' => $error->reason];
                }
            }
            $this->store->execute('UPDATE seat_pools SET next_seat_number=? WHERE tenant_id=? AND id=?', [$number, $actor->tenantId, $poolId]);
            $receipt = ['cohort_id' => $cohortId, 'items' => $items, 'available_at_commit' => $remaining];
            $id = $this->store->insert('INSERT INTO seat_batches (tenant_id, pool_id, actor_id, request_key, request_digest, receipt) VALUES (?, ?, ?, ?, ?, ?)',
                [$actor->tenantId, $poolId, $actor->accountId, $key, $digest, json_encode($receipt, JSON_THROW_ON_ERROR)]);
            $this->audit->record($actor, 'seat_batch', $id, 'committed', ['pool_id' => $poolId, 'row_count' => count($items), 'available' => $remaining]);
            return $this->batch($actor, $companyId, $poolId, $id) + ['replayed' => false];
        });
    }

    public function batch(Actor $actor, int $companyId, int $poolId, int $batchId): array
    {
        $this->companies->scope($actor, $companyId);
        $row = $this->store->one(
            'SELECT b.id, b.receipt, b.created_at FROM seat_batches b JOIN seat_pools p ON p.tenant_id=b.tenant_id AND p.id=b.pool_id
             WHERE b.tenant_id=? AND p.company_id=? AND b.pool_id=? AND b.id=?',
            [$actor->tenantId, $companyId, $poolId, $batchId]
        );
        return $this->receipt($row ?? throw new Problem(404, 'seat_batch_not_found'));
    }

    public function batches(Actor $actor, int $companyId, int $poolId, int $after): array
    {
        $this->companies->scope($actor, $companyId);
        $this->findPool($actor, $companyId, $poolId, '');
        $rows = $this->store->all(
            "SELECT id, actor_id, created_at, JSON_LENGTH(receipt, '$.items') AS row_count FROM seat_batches
              WHERE tenant_id=? AND pool_id=? AND id>? ORDER BY id LIMIT 51",
            [$actor->tenantId, $poolId, $after]
        );
        return $this->page($rows);
    }

    public function assignments(Actor $actor, int $companyId, int $poolId, int $after): array
    {
        $this->companies->scope($actor, $companyId);
        $this->findPool($actor, $companyId, $poolId, '');
        return $this->listAssignments($actor, 'a.company_id=? AND a.pool_id=?', [$companyId, $poolId], $after);
    }

    public function mine(Actor $actor, int $after): array
    {
        return $this->listAssignments($actor, 'a.learner_id=?', [$actor->accountId], $after);
    }

    private function listAssignments(Actor $actor, string $scope, array $ids, int $after): array
    {
        return $this->page($this->store->all(
            "SELECT a.id, a.state, a.learner_id, a.department_id, a.cohort_id, a.seat_number, a.enrollment_id, a.assigned_at, a.activated_at, a.reclaimed_at,
                    c.name AS cohort_name, d.name AS department_name, p.contract_code, p.starts_on, p.ends_on, v.name AS company_name
               FROM seat_assignments a JOIN cohorts c ON c.tenant_id=a.tenant_id AND c.id=a.cohort_id
               JOIN seat_pools p ON p.tenant_id=a.tenant_id AND p.id=a.pool_id
               JOIN companies v ON v.tenant_id=a.tenant_id AND v.id=a.company_id
               JOIN company_departments d ON d.tenant_id=a.tenant_id AND d.company_id=a.company_id AND d.id=a.department_id
              WHERE a.tenant_id=? AND $scope AND a.id>? ORDER BY a.id LIMIT 51",
            [$actor->tenantId, ...$ids, $after]
        ));
    }

    public function reclaim(Actor $actor, int $companyId, int $poolId, int $id): array
    {
        return $this->store->transaction(function () use ($actor, $companyId, $poolId, $id): array {
            $this->companies->lock($actor, $companyId, true);
            $this->lockPool($actor, $companyId, $poolId);
            $row = $this->assignment($actor, $id);
            if ($row['pool_id'] !== $poolId) {
                throw new Problem(404, 'seat_assignment_not_found');
            }
            if ($row['state'] === 'activated') {
                throw new Problem(409, 'activated_seat_cannot_reclaim');
            }
            if ($row['state'] === 'assigned') {
                $this->store->execute("UPDATE seat_assignments SET state='reclaimed', reclaimed_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?", [$actor->tenantId, $id]);
                $this->audit->record($actor, 'seat_assignment', $id, 'reclaimed', ['pool_id' => $poolId]);
            }
            return $this->assignment($actor, $id);
        });
    }

    public function activate(Actor $learner, int $id): array
    {
        return $this->store->transaction(function () use ($learner, $id): array {
            $candidate = $this->assignment($learner, $id);
            if ($candidate['learner_id'] !== $learner->accountId || !$learner->has('learner')) {
                throw new Problem(404, 'seat_assignment_not_found');
            }
            $pool = $this->lockPool($learner, $candidate['company_id'], $candidate['pool_id']);
            $current = $this->assignment($learner, $id);
            if ($current['state'] === 'activated') {
                return $current;
            }
            if ($current['state'] === 'reclaimed') {
                throw new Problem(409, 'seat_reclaimed');
            }
            $this->requireActive($pool);
            $enrollment = $this->admissions->activateEnterpriseSeat($learner, $current['cohort_id'], $id);
            $this->store->execute("UPDATE seat_assignments SET state='activated', enrollment_id=?, activated_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?", [$enrollment, $learner->tenantId, $id]);
            $this->audit->record($learner, 'seat_assignment', $id, 'activated', ['enrollment_id' => $enrollment, 'pool_id' => $current['pool_id']]);
            return $this->assignment($learner, $id);
        });
    }

    private function lockPool(Actor $actor, int $companyId, int $poolId): array
    {
        return $this->findPool($actor, $companyId, $poolId, ' FOR UPDATE');
    }

    private function findPool(Actor $actor, int $companyId, int $poolId, string $lock): array
    {
        $pool = $this->store->one('SELECT course_id, total, next_seat_number, starts_on, ends_on FROM seat_pools WHERE tenant_id=? AND company_id=? AND id=?' . $lock,
            [$actor->tenantId, $companyId, $poolId]);
        return $pool ?? throw new Problem(404, 'seat_pool_not_found');
    }

    private function requireActive(array $pool): void
    {
        $today = gmdate('Y-m-d');
        if ($pool['starts_on'] > $today || $pool['ends_on'] < $today) {
            throw new Problem(409, 'contract_outside_validity');
        }
    }

    private function seatsUsed(Actor $actor, int $poolId): int
    {
        return $this->store->one("SELECT COUNT(*) AS n FROM seat_assignments WHERE tenant_id=? AND pool_id=? AND state IN ('assigned','activated')", [$actor->tenantId, $poolId])['n'];
    }

    private function assignment(Actor $actor, int $id): array
    {
        $row = $this->store->one(
            'SELECT id, company_id, pool_id, department_id, learner_id, cohort_id, seat_number, state, enrollment_id, assigned_at, activated_at, reclaimed_at
               FROM seat_assignments WHERE tenant_id=? AND id=?',
            [$actor->tenantId, $id]
        );
        return $row ?? throw new Problem(404, 'seat_assignment_not_found');
    }

    private function receipt(array $row): array
    {
        return ['id' => $row['id'], 'created_at' => $row['created_at']] + json_decode($row['receipt'], true, 32, JSON_THROW_ON_ERROR);
    }

    private function page(array $rows): array
    {
        $items = array_slice($rows, 0, 50);
        return ['items' => $items, 'next_after' => count($rows) > 50 ? $items[49]['id'] : null];
    }
}
