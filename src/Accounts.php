<?php
declare(strict_types=1);

namespace LearningCloud;

use PDOException;

final readonly class Accounts
{
    private const ROLES = ['admin', 'registrar', 'instructor', 'learner'];
    private const UNKNOWN_ACCOUNT_HASH = '$2y$12$DBBBwt/HNztgZ6XoqT16GuUwx1A8Kg8cTRcCmuRBoVOuGYaN1HPxS';

    public function __construct(private Store $store, private Audit $audit)
    {
    }

    public function provision(array $input): Actor
    {
        $slug = Input::slug($input, 'tenant');
        $tenantName = Input::string($input, 'tenant_name', 160);
        [$email, $name, $passwordHash] = $this->accountFields($input);
        try {
            return $this->store->transaction(function () use ($slug, $tenantName, $email, $name, $passwordHash): Actor {
                $tenantId = $this->store->insert('INSERT INTO tenants (slug, name) VALUES (?, ?)', [$slug, $tenantName]);
                $this->store->execute("INSERT INTO competency_rollouts (tenant_id,mode) VALUES (?,'enabled')",[$tenantId]);
                $id = $this->insertAccount($tenantId, $email, $name, $passwordHash, ['admin']);
                $actor = new Actor($tenantId, $id, $name, ['admin']);
                $this->audit->record($actor, 'tenant', $tenantId, 'provisioned', ['slug' => $slug]);
                return $actor;
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'tenant_exists');
            }
            throw $error;
        }
    }

    public function create(Actor $actor, array $input): Actor
    {
        if (!$actor->has('admin')) {
            throw new Problem(403, 'admin_required');
        }
        $roles = Input::list($input, 'roles', 1, count(self::ROLES));
        foreach ($roles as $role) {
            Input::choice(['role' => $role], 'role', self::ROLES);
        }
        $roles = array_values(array_unique($roles));
        [$email, $name, $passwordHash] = $this->accountFields($input);
        try {
            return $this->store->transaction(function () use ($actor, $email, $name, $passwordHash, $roles): Actor {
                $id = $this->insertAccount($actor->tenantId, $email, $name, $passwordHash, $roles);
                $this->audit->record($actor, 'account', $id, 'created', ['roles' => $roles]);
                return new Actor($actor->tenantId, $id, $name, $roles);
            });
        } catch (PDOException $error) {
            if (Store::duplicate($error)) {
                throw new Problem(409, 'account_exists');
            }
            throw $error;
        }
    }

    public function authenticate(array $input): Actor
    {
        $tenant = Input::slug($input, 'tenant');
        $email = Input::email($input);
        $password = Input::password($input);
        $row = $this->store->one(
            'SELECT a.id, a.tenant_id, a.password_hash FROM tenants t JOIN accounts a ON a.tenant_id=t.id
              WHERE t.slug=? AND a.email=?',
            [$tenant, $email]
        );
        if (!password_verify($password, $row['password_hash'] ?? self::UNKNOWN_ACCOUNT_HASH) || $row === null) {
            throw new Problem(401, 'invalid_credentials');
        }
        return $this->find($row['tenant_id'], $row['id']);
    }

    public function listing(Actor $actor, int $after): array
    {
        if (!$actor->has('admin')) {
            throw new Problem(403, 'admin_required');
        }
        $rows = $this->store->all(
            'SELECT a.id, a.name, a.email, (SELECT GROUP_CONCAT(r.role ORDER BY r.role) FROM account_roles r
               WHERE r.tenant_id=a.tenant_id AND r.account_id=a.id) AS roles
               FROM accounts a WHERE a.tenant_id=? AND a.id>? ORDER BY a.id LIMIT 51',
            [$actor->tenantId, $after]
        );
        $items = array_slice($rows, 0, 50);
        foreach ($items as &$item) {
            $item['roles'] = $item['roles'] === null ? [] : explode(',', $item['roles']);
        }
        unset($item);
        return ['items' => $items, 'next_after' => count($rows) > 50 ? $items[49]['id'] : null];
    }

    public function find(int $tenantId, int $accountId): Actor
    {
        $account = $this->store->one('SELECT name FROM accounts WHERE tenant_id=? AND id=?', [$tenantId, $accountId]);
        if (!$account) {
            throw new Problem(404, 'account_not_found');
        }
        $roles = $this->store->all(
            "SELECT role FROM account_roles WHERE tenant_id=? AND account_id=?
             UNION SELECT 'enterprise_admin' FROM company_admins WHERE tenant_id=? AND account_id=? ORDER BY role",
            [$tenantId, $accountId, $tenantId, $accountId]
        );
        return new Actor($tenantId, $accountId, $account['name'], array_column($roles, 'role'));
    }

    private function accountFields(array $input): array
    {
        return [Input::email($input), Input::string($input, 'name', 160), password_hash(Input::password($input, true), PASSWORD_BCRYPT, ['cost' => 12])];
    }

    private function insertAccount(int $tenantId, string $email, string $name, string $passwordHash, array $roles): int
    {
        $id = $this->store->insert('INSERT INTO accounts (tenant_id, email, name, password_hash) VALUES (?, ?, ?, ?)', [$tenantId, $email, $name, $passwordHash]);
        foreach ($roles as $role) {
            $this->store->execute('INSERT INTO account_roles (tenant_id, account_id, role) VALUES (?, ?, ?)', [$tenantId, $id, $role]);
        }
        return $id;
    }
}
