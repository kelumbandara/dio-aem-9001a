<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Events\IntegrationEvent;

interface EventOutbox
{
    /**
     * Record an integration event in the transactional outbox (docs/architecture/events.md).
     * Call inside the same DB transaction as the business change. The relay later publishes it as a
     * {@see IntegrationEvent}, at least once.
     *
     * @param  string  $eventType  e.g. "approval.requested"
     * @param  array<string, mixed>  $data  ids and minimum facts, not whole models
     */
    public function record(
        string $eventType,
        array $data,
        ?string $aggregateType = null,
        string|int|null $aggregateId = null,
        ?int $branchId = null,
    ): void;
}
