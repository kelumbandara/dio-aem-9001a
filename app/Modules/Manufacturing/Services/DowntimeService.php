<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Manufacturing\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Models\DowntimeEvent;
use App\Modules\Manufacturing\Models\DowntimeReason;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\Shift;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Time a machine stood still, with the reason. Always for a shift and a machine; optionally for the order it held up.
 * An entry is "from HH:MM for N minutes" on the shift's date (a night shift's after-midnight hours roll to the next day
 * by themselves). Two entries for one machine cannot overlap. Entries are never edited: void and enter again.
 */
final class DowntimeService
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data  machine|machine_id, shift|shift_id, reason|reason_id, date?, from (HH:MM) + minutes, or started_at + ended_at; order? (order number); notes?
     */
    public function report(array $data, ?int $by = null, string $source = 'manual', ?string $externalId = null): DowntimeEvent
    {
        return DB::transaction(function () use ($data, $by, $source, $externalId): DowntimeEvent {
            if ($externalId !== null && ($existing = DowntimeEvent::query()->where('source', $source)->where('external_event_id', $externalId)->first()) !== null) {
                return $existing;
            }
            $machine = $this->machine($data);
            $shift = $this->shifts->find($data);
            $reason = $this->reason($data);
            $order = $this->order($data);
            $date = $this->shifts->date($shift, $data['date'] ?? null);
            [$start, $end] = $this->span($data, $shift, $date);

            // One machine, one story at a time.
            DB::select('select pg_advisory_xact_lock(?, ?)', [7002, $machine->id]);
            if (DowntimeEvent::query()->where('machine_id', $machine->id)->whereNull('voided_at')->where('started_at', '<', $end)->where('ended_at', '>', $start)->exists()) {
                throw ManufacturingException::make('manufacturing.downtime_overlap', 'This machine already has downtime recorded for part of that time.', 422, 'from');
            }

            $minutes = Decimal::round(Decimal::div((string) ($end->getTimestamp() - $start->getTimestamp()), '60'), 2);
            $event = DowntimeEvent::query()->create([
                'order_id' => $order?->id, 'machine_id' => $machine->id, 'shift_id' => $shift->id, 'reason_id' => $reason->id, 'shift_date' => $date->toDateString(),
                'started_at' => $start, 'ended_at' => $end, 'minutes' => $minutes, 'notes' => trim((string) ($data['notes'] ?? '')) === '' ? null : mb_substr(trim((string) $data['notes']), 0, 500),
                'source' => $source, 'external_event_id' => $externalId, 'reported_by' => $by,
            ]);
            $this->audit->record('manufacturing.downtime_reported', $event, [], ['machine' => $machine->code, 'shift' => $shift->code, 'reason' => $reason->code, 'minutes' => $minutes]);
            $this->outbox->record('manufacturing.downtime.reported', ['downtime_id' => $event->id, 'machine' => $machine->code, 'shift' => $shift->code, 'reason' => $reason->code, 'category' => $reason->category, 'minutes' => $minutes], 'downtime_event', $event->id);

            return $event;
        });
    }

    public function void(DowntimeEvent $event, string $reason, ?int $by = null): DowntimeEvent
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ManufacturingException::make('manufacturing.reason_required', 'Say why this entry is cancelled.', 422, 'reason');
        }

        return DB::transaction(function () use ($event, $reason, $by): DowntimeEvent {
            $locked = DowntimeEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at !== null) {
                throw ManufacturingException::make('manufacturing.already_voided', 'This entry is already cancelled.', 409);
            }
            $locked->forceFill(['voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 255), 'voided_by' => $by])->save();
            $this->audit->record('manufacturing.downtime_voided', $locked, [], ['reason' => $reason]);

            return $locked->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    private function machine(array $data): Machine
    {
        $machine = isset($data['machine_id']) && $data['machine_id'] !== ''
            ? Machine::query()->find((int) $data['machine_id'])
            : Machine::query()->whereRaw('lower(code) = ?', [strtolower(trim((string) ($data['machine'] ?? '')))])->first();
        if ($machine === null || $machine->status === 'retired') {
            throw ManufacturingException::make('manufacturing.machine_required', 'Choose the machine that stopped.', 422, 'machine');
        }

        return $machine;
    }

    /** @param  array<string, mixed>  $data */
    private function reason(array $data): DowntimeReason
    {
        $reason = isset($data['reason_id']) && $data['reason_id'] !== ''
            ? DowntimeReason::query()->find((int) $data['reason_id'])
            : DowntimeReason::query()->whereRaw('lower(code) = ?', [strtolower(trim((string) ($data['reason'] ?? '')))])->first();
        if ($reason === null || ! $reason->is_active) {
            throw ManufacturingException::make('manufacturing.reason_invalid', 'Choose the reason the machine stopped.', 422, 'reason');
        }

        return $reason;
    }

    /** @param  array<string, mixed>  $data */
    private function order(array $data): ?ProductionOrder
    {
        $no = trim((string) ($data['order'] ?? ''));
        if ($no === '') {
            return null;
        }
        $order = ProductionOrder::query()->whereRaw('lower(order_no) = ?', [strtolower($no)])->first();
        if ($order === null || ! in_array($order->status, ['released', 'in_progress', 'completed'], true)) {
            throw ManufacturingException::make('manufacturing.order_invalid', 'That production order does not exist or has not started.', 422, 'order');
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function span(array $data, Shift $shift, CarbonImmutable $date): array
    {
        $tz = config('core.display_timezone');
        if (! empty($data['started_at']) && ! empty($data['ended_at'])) {
            try {
                $start = CarbonImmutable::parse((string) $data['started_at'], $tz);
                $end = CarbonImmutable::parse((string) $data['ended_at'], $tz);
            } catch (\Throwable) {
                throw ManufacturingException::make('manufacturing.time_invalid', 'Use times like 2026-11-15 14:30.', 422, 'from');
            }
        } else {
            $from = trim((string) ($data['from'] ?? ''));
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $from, $m) !== 1) {
                throw ManufacturingException::make('manufacturing.time_invalid', 'Enter the time it stopped like 14:30.', 422, 'from');
            }
            $minutes = trim((string) ($data['minutes'] ?? ''));
            if (! Decimal::isDecimal($minutes, 0) || (int) $minutes < 1) {
                throw ManufacturingException::make('manufacturing.minutes_invalid', 'Enter how many minutes it was stopped (whole minutes, 1 or more).', 422, 'minutes');
            }
            $start = $date->setTime((int) $m[1], (int) $m[2]);
            // a night shift's hours after midnight belong to the shift date of the evening before
            if ((string) $shift->start_time > (string) $shift->end_time && $start->format('H:i:s') < (string) $shift->end_time) {
                $start = $start->addDay();
            }
            $end = $start->addMinutes((int) $minutes);
        }
        if ($end <= $start || $start->diffInMinutes($end) > 1440) {
            throw ManufacturingException::make('manufacturing.minutes_invalid', 'Downtime must be at least a minute and at most a day.', 422, 'minutes');
        }
        if ($end->greaterThan(CarbonImmutable::now($tz)->addMinutes(5))) {
            throw ManufacturingException::make('manufacturing.date_future', 'Downtime that has not happened yet cannot be reported.', 422, 'from');
        }

        return [$start->utc(), $end->utc()];
    }
}
