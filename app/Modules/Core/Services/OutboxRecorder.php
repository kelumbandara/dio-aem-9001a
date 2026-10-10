<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\OutboxEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Writes integration events to the transactional outbox. Call inside the same DB transaction
 * as the business change. The relay that publishes pending rows arrives with the queue work in S2.
 */
final class OutboxRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $eventType,
        array $payload,
        ?string $aggregateType = null,
        string|int|null $aggregateId = null,
        ?CarbonInterface $availableAt = null,
    ): OutboxEvent {
        return OutboxEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId !== null ? (string) $aggregateId : null,
            'payload' => $payload,
            'correlation_id' => Context::get('request_id'),
            'occurred_at' => now(),
            'available_at' => $availableAt ?? now(),
        ]);
    }
}
