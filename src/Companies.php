<?php
declare(strict_types=1);

namespace LearningCloud;

use PDOException;

final readonly class Companies
{
    public function __construct(private Store $store, private Accounts $accounts, private Audit $audit)
    {
    }

    public function listing(Actor $actor, int $after): array
    {
        $rows = $this->store->all(
            'SELECT c.id, c.code, c.name FROM companies c WHERE c.tenant_id=? AND c.id>?
             AND (? OR EXISTS (SELECT 1 FROM company_admins a WHERE a.tenant_id=c.tenant_id AND a.company_id=c.id AND a.account_id=?))
             ORDER BY c.id LIMIT 51',
            [$actor->tenantId, $after, $actor->has('admin'), $actor->accountId]
        );
        return $this->page($rows, 'id');
    }

    public function create(Actor $actor, array $input): array
    {
        $this->requireSpaceAdmin($actor);
        $code = Input::slug($input, 'code');
        $name = Input::string($input, 'name', 160);
        try {
            return $this->store->transaction(function () use ($actor, $code, $name): array {
                $id = $this->store->insert('INSERT INTO companies (tenant_id, code, name) VALUES (?, ?, ?)', [$actor->tenantId, $code, $name]);
                $this->audit->record($actor, 'company', $id, 'created', ['code' => $code]);
                return ['id' => $id, 'code' => $code, 'name' => $name];
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'company_code_exists');
            }
            throw $error;
        }
    }

    public function scope(Actor $actor, int $companyId): array
    {
        $row = $this->store->one(
            'SELECT c.id, c.code, c.name FROM companies c WHERE c.tenant_id=? AND c.id=?
             AND (? OR EXISTS (SELECT 1 FROM company_admins a WHERE a.tenant_id=c.tenant_id AND a.company_id=c.id AND a.account_id=?))',
            [$actor->tenantId, $companyId, $actor->has('admin'), $actor->accountId]
        );
        return $row ?? throw new Problem(404, 'company_not_found');
    }

    public function administrators(Actor $actor, int $companyId, int $after): array
    {
        $this->requireSpaceAdmin($actor);
        $this->scope($actor, $companyId);
        $rows = $this->store->all(
            'SELECT a.id, a.name FROM accounts a JOIN company_admins g ON g.tenant_id=a.tenant_id AND g.account_id=a.id
             WHERE g.tenant_id=? AND g.company_id=? AND g.account_id>? ORDER BY g.account_id LIMIT 51',
            [$actor->tenantId, $companyId, $after]
        );
        return $this->page($rows, 'id');
    }

    public function setAdministrator(Actor $actor, int $companyId, int $accountId, array $input): array
    {
        $this->requireSpaceAdmin($actor);
        $enabled = Input::boolean($input, 'enabled');
        return $this->store->transaction(function () use ($actor, $companyId, $accountId, $enabled): array {
            // An update waits for in-flight company writes before removing their authorization.
            $this->lock($actor, $companyId);
            $this->accounts->find($actor->tenantId, $accountId);
            $parameters = [$actor->tenantId, $companyId, $accountId];
            $previous = $this->store->one('SELECT account_id FROM company_admins WHERE tenant_id=? AND company_id=? AND account_id=?', $parameters) !== null;
            if ($enabled !== $previous) {
                if ($enabled) {
                    $this->store->execute('INSERT INTO company_admins (tenant_id, company_id, account_id) VALUES (?, ?, ?)', $parameters);
                } else {
                    $this->store->execute('DELETE FROM company_admins WHERE tenant_id=? AND company_id=? AND account_id=?', $parameters);
                }
                $this->audit->record($actor, 'company', $companyId, $enabled ? 'administrator_granted' : 'administrator_revoked', ['account_id' => $accountId]);
            }
            return ['account_id' => $accountId, 'enabled' => $enabled];
        });
    }

    public function departments(Actor $actor, int $companyId, int $after): array
    {
        $this->scope($actor, $companyId);
        return $this->page($this->store->all(
            'SELECT id, code, name FROM company_departments WHERE tenant_id=? AND company_id=? AND id>? ORDER BY id LIMIT 51',
            [$actor->tenantId, $companyId, $after]
        ), 'id');
    }

    public function createDepartment(Actor $actor, int $companyId, array $input): array
    {
        $code = Input::slug($input, 'code');
        $name = Input::string($input, 'name', 160);
        try {
            return $this->store->transaction(function () use ($actor, $companyId, $code, $name): array {
                $this->lock($actor, $companyId);
                $id = $this->store->insert('INSERT INTO company_departments (tenant_id, company_id, code, name) VALUES (?, ?, ?, ?)', [$actor->tenantId, $companyId, $code, $name]);
                $this->audit->record($actor, 'company', $companyId, 'department_created', ['department_id' => $id, 'code' => $code]);
                return ['id' => $id, 'code' => $code, 'name' => $name];
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'department_code_exists');
            }
            throw $error;
        }
    }

    public function learners(Actor $actor, int $companyId, int $after): array
    {
        $this->scope($actor, $companyId);
        return $this->page($this->store->all(
            'SELECT l.learner_id, l.department_id, a.name FROM company_learners l
             JOIN accounts a ON a.tenant_id=l.tenant_id AND a.id=l.learner_id
             WHERE l.tenant_id=? AND l.company_id=? AND l.learner_id>? ORDER BY l.learner_id LIMIT 51',
            [$actor->tenantId, $companyId, $after]
        ), 'learner_id');
    }

    public function addLearners(Actor $actor, int $companyId, array $input): array
    {
        $this->requireSpaceAdmin($actor);
        $departmentId = Input::integer($input, 'department_id', 1);
        $ids = Input::list($input, 'learner_ids', 1, 500);
        return $this->store->transaction(function () use ($actor, $companyId, $departmentId, $ids): array {
            $this->lock($actor, $companyId);
            if (!$this->store->one('SELECT id FROM company_departments WHERE tenant_id=? AND company_id=? AND id=?', [$actor->tenantId, $companyId, $departmentId])) {
                throw new Problem(404, 'department_not_found');
            }
            $items = [];
            foreach ($ids as $index => $rawId) {
                try {
                    $id = Input::integer(['learner_id' => $rawId], 'learner_id', 1);
                    if (!$this->accounts->find($actor->tenantId, $id)->has('learner')) {
                        throw new Problem(422, 'account_is_not_a_learner');
                    }
                    $prior = $this->membership($actor->tenantId, $companyId, $id);
                    if ($prior !== null && $prior['department_id'] !== $departmentId) {
                        throw new Problem(409, 'learner_department_conflict');
                    }
                    if ($prior === null) {
                        $this->store->execute('INSERT INTO company_learners (tenant_id, company_id, department_id, learner_id) VALUES (?, ?, ?, ?)', [$actor->tenantId, $companyId, $departmentId, $id]);
                        $this->audit->record($actor, 'company', $companyId, 'learner_added', ['department_id' => $departmentId, 'learner_id' => $id]);
                    }
                    $items[] = ['row' => $index + 1, 'learner_id' => $id, 'outcome' => $prior === null ? 'added' : 'duplicate'];
                } catch (Problem $error) {
                    $items[] = ['row' => $index + 1, 'outcome' => 'rejected', 'reason' => $error->reason];
                }
            }
            return ['items' => $items];
        });
    }

    public function membership(int $tenantId, int $companyId, int $learnerId): ?array
    {
        return $this->store->one('SELECT department_id FROM company_learners WHERE tenant_id=? AND company_id=? AND learner_id=?', [$tenantId, $companyId, $learnerId]);
    }

    public function lock(Actor $actor, int $companyId, bool $shared = false): void
    {
        if (!$this->store->one('SELECT id FROM companies WHERE tenant_id=? AND id=?' . ($shared ? ' FOR SHARE' : ' FOR UPDATE'), [$actor->tenantId, $companyId])) {
            throw new Problem(404, 'company_not_found');
        }
        $this->scope($actor, $companyId);
    }

    public function requireSpaceAdmin(Actor $actor): void
    {
        if (!$actor->has('admin')) {
            throw new Problem(403, 'admin_required');
        }
    }

    private function page(array $rows, string $field): array
    {
        $items = array_slice($rows, 0, 50);
        return ['items' => $items, 'next_after' => count($rows) > 50 ? $items[49][$field] : null];
    }
}
