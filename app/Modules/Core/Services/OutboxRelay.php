<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Events\IntegrationEvent;
use App\Modules\Core\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Publishes pending outbox rows as {@see IntegrationEvent}s, at least once, in order per
 * aggregate (rows are taken oldest first and a failing row blocks later rows of the same aggregate
 * only while it is being retried).
 *
 * Several relays can run at once: rows are claimed with FOR UPDATE SKIP LOCKED. A row is marked
 * processed only after every synchronous listener returned; a listener exception schedules a retry
 * with exponential backoff, and after `core.outbox.max_attempts` the row is parked (`failed_at`)
 * and reported, never silently dropped.
 */
final class OutboxRelay
{
    public function __construct(private readonly Dispatcher $events) {}

    /** @return array{published: int, failed: int, parked: int} */
    public function run(?int $limit = null): array
    {
        $limit ??= (int) config('core.outbox.batch_size', 100);
        $result = ['published' => 0, 'failed' => 0, 'parked' => 0];

        DB::transaction(function () use ($limit, &$result): void {
            $rows = OutboxEvent::query()
                ->whereNull('processed_at')
                ->whereNull('failed_at')
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked') // concurrent relays take different rows instead of waiting
                ->get();

            $blockedAggregates = [];

            foreach ($rows as $row) {
                $aggregate = $row->aggregate_type !== null ? $row->aggregate_type.':'.$row->aggregate_id : null;
                if ($aggregate !== null && isset($blockedAggregates[$aggregate])) {
                    continue; // keep per-aggregate order: wait for the earlier event to succeed
                }

                try {
                    // Own savepoint: a listener that fails mid-way (even with a database error) rolls
                    // back its own writes without aborting the claim transaction of the other rows.
                    DB::transaction(function () use ($row): void {
                        $this->events->dispatch($this->toEvent($row));
                        $row->forceFill(['processed_at' => now(), 'last_error' => null])->save();
                    });
                    $result['published']++;
                } catch (Throwable $e) {
                    report($e);
                    $this->recordFailure($row, $e, $result);
                    if ($aggregate !== null) {
                        $blockedAggregates[$aggregate] = true;
                    }
                }
            }
        });

        return $result;
    }

    /** @param array{published: int, failed: int, parked: int} $result */
    private function recordFailure(OutboxEvent $row, Throwable $e, array &$result): void
    {
        $attempts = $row->attempts + 1;
        $parked = $attempts >= (int) config('core.outbox.max_attempts', 8);

        $row->forceFill([
            'processed_at' => null, // the failed attempt's save was rolled back; make the model say so too
            'attempts' => $attempts,
            'last_error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 1000),
            // 2, 4, 8 ... minutes, capped at one hour
            'available_at' => now()->addMinutes(min(2 ** $attempts, 60)),
            'failed_at' => $parked ? now() : null,
        ])->save();

        $result[$parked ? 'parked' : 'failed']++;
    }

    private function toEvent(OutboxEvent $row): IntegrationEvent
    {
        return new IntegrationEvent(
            id: $row->event_id,
            type: $row->event_type,
            version: 1,
            occurredAt: CarbonImmutable::instance($row->occurred_at),
            actorType: $row->actor_type,
            actorId: $row->actor_id,
            correlationId: $row->correlation_id,
            branchId: $row->branch_id,
            aggregateType: $row->aggregate_type,
            aggregateId: $row->aggregate_id,
            data: $row->payload,
        );
    }
}
