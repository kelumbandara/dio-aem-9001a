<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Manufacturing\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\ProductionReport;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * Output reported by operators, always for a shift. One report says how many units were made (good, reject and rework
 * together), how many of them are rejects and how many need rework; the rest wait for quality inspection. Reports are never
 * edited: a mistake is voided (with a reason) and entered again. Units sent to rework come back with a "rework done" report.
 *
 * The order keeps the six quantities apart: reported (all units made), rejected, rework, accepted (by inspection), received
 * (into finished-goods stock). Reported never falls below accepted + rejected + rework, so units already judged keep their
 * reports.
 */
final class OutputService
{
    public function __construct(
        private readonly ItemCatalog $items,
        private readonly ShiftService $shifts,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data  shift|shift_id, date?, produced, rejected?, rework?, operation? (step number), machine|machine_id?, notes?
     * @param  string|null  $externalId  a device's own id for this report: sending it again returns the report already made
     *
     * @throws BusinessException
     */
    public function report(ProductionOrder $order, array $data, ?int $by = null, string $source = 'manual', ?string $externalId = null): ProductionReport
    {
        return DB::transaction(function () use ($order, $data, $by, $source, $externalId): ProductionReport {
            if ($externalId !== null && ($existing = ProductionReport::query()->where('source', $source)->where('external_event_id', $externalId)->first()) !== null) {
                return $existing;
            }
            $locked = $this->lockOpen($order);
            $item = $this->items->requireActive($locked->item_id);
            $shift = $this->shifts->find($data);
            $date = $this->shifts->date($shift, $data['date'] ?? null);

            $produced = $this->qty($data['produced'] ?? null, $item, 'produced', false);
            $reject = $this->qty($data['rejected'] ?? '0', $item, 'rejected', true);
            $rework = $this->qty($data['rework'] ?? '0', $item, 'rework', true);
            if (Decimal::compare(Decimal::add($reject, $rework), $produced) > 0) {
                throw ManufacturingException::make('manufacturing.rejects_exceed', 'Rejects and rework cannot be more than the units made.', 422, 'rejected');
            }
            $cap = Decimal::mul((string) $locked->planned_qty, Decimal::add('1', Decimal::div((string) config('manufacturing.over_production_pct'), '100')));
            if (Decimal::compare(Decimal::add((string) $locked->reported_qty, $produced), $cap) > 0) {
                throw ManufacturingException::make('manufacturing.over_production', 'That is more than this order may make ('.Decimal::trim(Decimal::round($cap, 6)).' '.$item->baseUomCode.' in all). Make another order for the extra.', 422, 'produced');
            }
            $operation = $this->operation($locked, $data['operation'] ?? null);
            $machine = $this->machine($data);

            $report = ProductionReport::query()->create([
                'order_id' => $locked->id, 'operation_id' => $operation?->id, 'shift_id' => $shift->id, 'machine_id' => $machine?->id, 'kind' => 'output',
                'report_date' => $date->toDateString(), 'produced_qty' => $produced, 'reject_qty' => $reject, 'rework_qty' => $rework,
                'notes' => $this->note($data['notes'] ?? null), 'source' => $source, 'external_event_id' => $externalId, 'reported_by' => $by,
            ]);
            $operation?->forceFill(['status' => 'in_progress'])->save();

            $locked->forceFill([
                'reported_qty' => Decimal::add((string) $locked->reported_qty, $produced),
                'rejected_qty' => Decimal::add((string) $locked->rejected_qty, $reject),
                'rework_qty' => Decimal::add((string) $locked->rework_qty, $rework),
                'status' => 'in_progress', 'version' => $locked->version + 1,
            ])->save();

            $this->audit->record('manufacturing.output_reported', $locked, [], ['order_no' => $locked->order_no, 'shift' => $shift->code, 'date' => $date->toDateString(), 'produced' => $produced, 'rejected' => $reject, 'rework' => $rework]);
            $this->outbox->record('manufacturing.output.reported', ['order_id' => $locked->id, 'order_no' => $locked->order_no, 'report_id' => $report->id, 'shift' => $shift->code, 'produced' => $produced, 'rejected' => $reject, 'rework' => $rework], 'production_order', $locked->id);

            return $report;
        });
    }

    /**
     * Units that were sent to rework are done again: they go back to waiting for inspection.
     *
     * @param  array<string, mixed>  $data  shift|shift_id, date?, qty, notes?
     */
    public function reworkDone(ProductionOrder $order, array $data, ?int $by = null): ProductionReport
    {
        return DB::transaction(function () use ($order, $data, $by): ProductionReport {
            $locked = $this->lockOpen($order);
            $item = $this->items->requireActive($locked->item_id);
            $shift = $this->shifts->find($data);
            $date = $this->shifts->date($shift, $data['date'] ?? null);
            $qty = $this->qty($data['qty'] ?? null, $item, 'qty', false);
            if (Decimal::compare($qty, (string) $locked->rework_qty) > 0) {
                throw ManufacturingException::make('manufacturing.rework_too_much', 'Only '.Decimal::trim((string) $locked->rework_qty).' '.$item->baseUomCode.' are waiting for rework.', 422, 'qty');
            }

            $report = ProductionReport::query()->create([
                'order_id' => $locked->id, 'shift_id' => $shift->id, 'kind' => 'rework_done', 'report_date' => $date->toDateString(), 'produced_qty' => $qty,
                'notes' => $this->note($data['notes'] ?? null), 'source' => 'manual', 'reported_by' => $by,
            ]);
            $locked->forceFill(['rework_qty' => Decimal::sub((string) $locked->rework_qty, $qty), 'version' => $locked->version + 1])->save();
            $this->audit->record('manufacturing.rework_done', $locked, [], ['order_no' => $locked->order_no, 'shift' => $shift->code, 'qty' => $qty]);
            $this->outbox->record('manufacturing.rework.done', ['order_id' => $locked->id, 'order_no' => $locked->order_no, 'report_id' => $report->id, 'qty' => $qty], 'production_order', $locked->id);

            return $report;
        });
    }

    /**
     * Cancel a report that was entered wrongly. Refused once its units have been judged by inspection (or, for rework, reworked again).
     */
    public function void(ProductionReport $report, string $reason, ?int $by = null): ProductionReport
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ManufacturingException::make('manufacturing.reason_required', 'Say why this report is cancelled.', 422, 'reason');
        }

        return DB::transaction(function () use ($report, $reason, $by): ProductionReport {
            $order = ProductionOrder::query()->whereKey($report->order_id)->lockForUpdate()->firstOrFail();
            $locked = ProductionReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at !== null) {
                throw ManufacturingException::make('manufacturing.already_voided', 'This report is already cancelled.', 409);
            }
            if (! in_array($order->status, ['released', 'in_progress'], true)) {
                throw ManufacturingException::state('production order', $order->status, 'released or in progress');
            }

            if ($locked->kind === 'output') {
                $reported = Decimal::sub((string) $order->reported_qty, (string) $locked->produced_qty);
                $rejected = Decimal::sub((string) $order->rejected_qty, (string) $locked->reject_qty);
                $rework = Decimal::sub((string) $order->rework_qty, (string) $locked->rework_qty);
                $judged = Decimal::add(Decimal::add((string) $order->accepted_qty, $rejected), $rework);
                if (Decimal::compare($rejected, '0') < 0 || Decimal::compare($rework, '0') < 0 || Decimal::compare($judged, $reported) > 0) {
                    throw ManufacturingException::make('manufacturing.report_in_use', 'Some of these units have already been inspected or reworked, so the report cannot be cancelled.', 422);
                }
                $order->forceFill(['reported_qty' => $reported, 'rejected_qty' => $rejected, 'rework_qty' => $rework, 'version' => $order->version + 1])->save();
            } else {
                if (Decimal::compare((string) $locked->produced_qty, $order->pendingQty()) > 0) {
                    throw ManufacturingException::make('manufacturing.report_in_use', 'Some of these units have already been inspected, so the report cannot be cancelled.', 422);
                }
                $order->forceFill(['rework_qty' => Decimal::add((string) $order->rework_qty, (string) $locked->produced_qty), 'version' => $order->version + 1])->save();
            }

            $locked->forceFill(['voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 255), 'voided_by' => $by])->save();
            $this->audit->record('manufacturing.output_voided', $order, [], ['order_no' => $order->order_no, 'report_id' => $locked->id, 'reason' => $reason]);
            $this->outbox->record('manufacturing.output.voided', ['order_id' => $order->id, 'order_no' => $order->order_no, 'report_id' => $locked->id], 'production_order', $order->id);

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ helpers

    private function lockOpen(ProductionOrder $order): ProductionOrder
    {
        $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        if (! in_array($locked->status, ['released', 'in_progress'], true)) {
            throw ManufacturingException::state('production order', $locked->status, 'released or in progress');
        }

        return $locked;
    }

    private function qty(mixed $value, ItemInfo $item, string $field, bool $mayBeZero): string
    {
        $v = trim((string) $value);
        if ($v === '' && $mayBeZero) {
            return '0.000000';
        }
        if ($v === '' || ! Decimal::isDecimal($v, $item->baseUomDecimals) || Decimal::compare($v, '0') < 0 || (! $mayBeZero && Decimal::compare($v, '0') === 0)) {
            throw ManufacturingException::make('manufacturing.qty_invalid', ($mayBeZero ? 'Enter zero or more' : 'Enter a quantity above zero')." with at most {$item->baseUomDecimals} decimals ({$item->baseUomCode}).", 422, $field);
        }

        return Decimal::round($v, 6);
    }

    private function operation(ProductionOrder $order, mixed $opNo): ?ProductionOrderOperation
    {
        if ($opNo === null || trim((string) $opNo) === '') {
            return null;
        }
        $op = ProductionOrderOperation::query()->where('order_id', $order->id)->where('op_no', (int) $opNo)->first();
        if ($op === null) {
            throw ManufacturingException::make('manufacturing.operation_invalid', 'That routing step is not on this order.', 422, 'operation');
        }

        return $op;
    }

    /** @param  array<string, mixed>  $data */
    private function machine(array $data): ?Machine
    {
        $machine = null;
        if (isset($data['machine_id']) && $data['machine_id'] !== '') {
            $machine = Machine::query()->find((int) $data['machine_id']);
        } elseif (trim((string) ($data['machine'] ?? '')) !== '') {
            $machine = Machine::query()->whereRaw('lower(code) = ?', [strtolower(trim((string) $data['machine']))])->first();
        } else {
            return null;
        }
        if ($machine === null || $machine->status !== 'available') {
            throw ManufacturingException::make('manufacturing.machine_unavailable', 'That machine does not exist or is in maintenance or retired.', 422, 'machine');
        }

        return $machine;
    }

    private function note(mixed $value): ?string
    {
        $v = trim((string) $value);

        return $v === '' ? null : mb_substr($v, 0, 500);
    }
}
