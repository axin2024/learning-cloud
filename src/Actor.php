<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class Actor
{
    public function __construct(
        public int $tenantId,
        public int $accountId,
        public string $name,
        public array $roles,
    ) {
    }

    public function has(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function managesCatalog(): bool
    {
        return $this->has('admin') || $this->has('registrar');
    }

    public function requireManagement(): void
    {
        if (!$this->managesCatalog()) {
            throw new Problem(403, 'registrar_required');
        }
    }

    public function view(): array
    {
        return ['tenant_id' => $this->tenantId, 'account_id' => $this->accountId, 'name' => $this->name, 'roles' => $this->roles];
    }
}
