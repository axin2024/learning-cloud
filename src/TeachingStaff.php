<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class TeachingStaff
{
    public function __construct(private Store $store, private Accounts $accounts, private Audit $audit)
    {
    }

    public function cohorts(Actor $actor, int $after): array
    {
        $rows = $this->store->all(
            'SELECT c.id, c.code, c.name, r.title AS course_title, s.duty
               FROM cohort_staff s JOIN cohorts c ON c.tenant_id=s.tenant_id AND c.id=s.cohort_id
               JOIN course_revisions r ON r.tenant_id=c.tenant_id AND r.id=c.revision_id
              WHERE s.tenant_id=? AND s.account_id=? AND s.cohort_id>? ORDER BY s.cohort_id LIMIT 51',
            [$actor->tenantId, $actor->accountId, $after]
        );
        return ['items' => array_slice($rows, 0, 50), 'next_after' => count($rows) > 50 ? $rows[49]['id'] : null];
    }

    public function listing(Actor $actor, int $cohortId): array
    {
        $actor->requireManagement();
        $this->cohort($actor, $cohortId);
        return ['items' => $this->store->all('SELECT s.account_id, a.name, s.duty FROM cohort_staff s JOIN accounts a ON a.tenant_id=s.tenant_id AND a.id=s.account_id
             WHERE s.tenant_id=? AND s.cohort_id=? ORDER BY s.account_id', [$actor->tenantId, $cohortId])];
    }

    public function set(Actor $actor, int $cohortId, int $accountId, array $input): array
    {
        $actor->requireManagement();
        $duty = Input::choice($input, 'duty', ['instructor', 'assistant', 'none']);
        return $this->store->transaction(function () use ($actor, $cohortId, $accountId, $duty): array {
            $this->cohort($actor, $cohortId, ' FOR UPDATE');
            $parameters = [$actor->tenantId, $cohortId, $accountId];
            $previous = $this->store->one('SELECT duty FROM cohort_staff WHERE tenant_id=? AND cohort_id=? AND account_id=?', $parameters)['duty'] ?? null;
            if ($duty === 'none' && $previous !== null) {
                $this->store->execute('DELETE FROM cohort_staff WHERE tenant_id=? AND cohort_id=? AND account_id=?', $parameters);
                $this->audit->record($actor, 'cohort', $cohortId, 'teaching_access_revoked', ['account_id' => $accountId, 'duty' => $previous]);
            } elseif ($duty !== 'none' && $previous !== $duty) {
                if (!$this->accounts->find($actor->tenantId, $accountId)->has('instructor')) {
                    throw new Problem(422, 'account_is_not_teaching_staff');
                }
                $this->store->execute('INSERT INTO cohort_staff (tenant_id, cohort_id, account_id, duty) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE duty=?',
                    [...$parameters, $duty, $duty]);
                $this->audit->record($actor, 'cohort', $cohortId, 'teaching_access_granted', ['account_id' => $accountId, 'duty' => $duty]);
            }
            return ['account_id' => $accountId, 'duty' => $duty];
        });
    }

    public function requireMarker(Actor $actor, int $cohortId, bool $lock = false): void
    {
        $this->cohort($actor, $cohortId, $lock ? ' FOR SHARE' : '');
        if (!$actor->managesCatalog() && (!$actor->has('instructor') || !$this->store->one(
            'SELECT duty FROM cohort_staff WHERE tenant_id=? AND cohort_id=? AND account_id=?', [$actor->tenantId, $cohortId, $actor->accountId]
        ))) {
            throw new Problem(404, 'teaching_cohort_not_found');
        }
    }

    private function cohort(Actor $actor, int $cohortId, string $lock = ''): void
    {
        if (!$this->store->one('SELECT id FROM cohorts WHERE tenant_id=? AND id=?' . $lock, [$actor->tenantId, $cohortId])) {
            throw new Problem(404, 'teaching_cohort_not_found');
        }
    }
}
