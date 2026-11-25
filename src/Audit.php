<?php
declare(strict_types=1);

namespace LearningCloud;

final readonly class Audit
{
    public function __construct(private Store $store)
    {
    }

    public function record(Actor $actor, string $type, int $id, string $action, array $details = []): void
    {
        $this->store->execute(
            'INSERT INTO audit_events (tenant_id, actor_id, object_type, object_id, action, details) VALUES (?, ?, ?, ?, ?, ?)',
            [$actor->tenantId, $actor->accountId, $type, $id, $action, json_encode((object) $details, JSON_THROW_ON_ERROR)]
        );
    }
}
