<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Models\OutboxEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Writes integration events to the transactional outbox. Call inside the same DB transaction as
 * the business change; {@see OutboxRelay} publishes the rows afterwards.
 */
final class OutboxRecorder implements EventOutbox
{
    public function record(
        string $eventType,
        array $data,
        ?string $aggregateType = null,
        string|int|null $aggregateId = null,
        ?int $branchId = null,
    ): void {
        $actorId = Auth::id();

        OutboxEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId !== null ? (string) $aggregateId : null,
            'payload' => $data,
            'correlation_id' => Context::get('request_id'),
            'actor_type' => $actorId !== null ? 'user' : 'system',
            'actor_id' => $actorId,
            'branch_id' => $branchId,
            'occurred_at' => now(),
            'available_at' => now(),
        ]);
    }
}
