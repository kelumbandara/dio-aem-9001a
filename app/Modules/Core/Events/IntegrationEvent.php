<?php

namespace App\Modules\Core\Events;

use Carbon\CarbonImmutable;

/**
 * Envelope of docs/architecture/events.md §2, dispatched by the outbox relay. Listeners that do
 * real work should be queued and must be idempotent (delivery is at least once; use `$id` to dedupe).
 */
final readonly class IntegrationEvent
{
    /**
     * @param  'user'|'system'|'ai'  $actorType
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public int $version,
        public CarbonImmutable $occurredAt,
        public string $actorType,
        public ?int $actorId,
        public ?string $correlationId,
        public ?int $branchId,
        public ?string $aggregateType,
        public ?string $aggregateId,
        public array $data,
    ) {}
}
