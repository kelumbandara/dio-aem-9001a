<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Manufacturing\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\QualityInspection;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quality inspection decides what happens to units that have been reported and not yet judged: accepted (they may go into
 * finished-goods stock), rejected (scrap) or sent to rework. Nobody can judge more units than are waiting.
 */
final class InspectionService
{
    public function __construct(
        private readonly ItemCatalog $items,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data  accepted?, rejected?, rework?, reject_reason? (needed when something is rejected or reworked), notes?, date?
     */
    public function inspect(ProductionOrder $order, array $data, ?int $by = null): QualityInspection
    {
        return DB::transaction(function () use ($order, $data, $by): QualityInspection {
            $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'in_progress') {
                throw ManufacturingException::state('production order', $locked->status, 'in progress');
            }
            $item = $this->items->requireActive($locked->item_id);
            $accepted = $this->qty($data['accepted'] ?? null, $item->baseUomDecimals, 'accepted');
            $rejected = $this->qty($data['rejected'] ?? null, $item->baseUomDecimals, 'rejected');
            $rework = $this->qty($data['rework'] ?? null, $item->baseUomDecimals, 'rework');
            $total = Decimal::add(Decimal::add($accepted, $rejected), $rework);
            if (Decimal::compare($total, '0') <= 0) {
                throw ManufacturingException::make('manufacturing.inspection_empty', 'Enter how many units were accepted, rejected or sent to rework.', 422, 'accepted');
            }
            if (Decimal::compare($total, $locked->pendingQty()) > 0) {
                throw ManufacturingException::make('manufacturing.inspection_too_many', 'Only '.Decimal::trim($locked->pendingQty()).' '.$item->baseUomCode.' are waiting for inspection.', 422, 'accepted');
            }
            $reason = trim((string) ($data['reject_reason'] ?? ''));
            if ((Decimal::compare($rejected, '0') > 0 || Decimal::compare($rework, '0') > 0) && $reason === '') {
                throw ManufacturingException::make('manufacturing.reason_required', 'Say why units were rejected or sent to rework.', 422, 'reject_reason');
            }

            $tz = config('core.display_timezone');
            $given = trim((string) ($data['date'] ?? ''));
            $date = $given === '' ? CarbonImmutable::now($tz)->startOfDay() : CarbonImmutable::createFromFormat('!Y-m-d', $given, $tz);
            if ($date === null || ($given !== '' && $date->toDateString() !== $given) || $date->greaterThan(CarbonImmutable::now($tz)->endOfDay())) {
                throw ManufacturingException::make('manufacturing.date_invalid', 'Use today\'s date or an earlier one, like 2026-11-15.', 422, 'date');
            }

            $inspection = QualityInspection::query()->create([
                'order_id' => $locked->id, 'inspected_on' => $date->toDateString(), 'accepted_qty' => $accepted, 'rejected_qty' => $rejected, 'rework_qty' => $rework,
                'reject_reason' => $reason === '' ? null : mb_substr($reason, 0, 255), 'notes' => trim((string) ($data['notes'] ?? '')) === '' ? null : mb_substr(trim((string) $data['notes']), 0, 500), 'inspected_by' => $by,
            ]);
            $locked->forceFill([
                'accepted_qty' => Decimal::add((string) $locked->accepted_qty, $accepted),
                'rejected_qty' => Decimal::add((string) $locked->rejected_qty, $rejected),
                'rework_qty' => Decimal::add((string) $locked->rework_qty, $rework),
                'version' => $locked->version + 1,
            ])->save();

            $this->audit->record('manufacturing.inspection_recorded', $locked, [], ['order_no' => $locked->order_no, 'accepted' => $accepted, 'rejected' => $rejected, 'rework' => $rework, 'reason' => $reason]);
            $this->outbox->record('manufacturing.inspection.recorded', ['order_id' => $locked->id, 'order_no' => $locked->order_no, 'inspection_id' => $inspection->id, 'accepted' => $accepted, 'rejected' => $rejected, 'rework' => $rework], 'production_order', $locked->id);

            return $inspection;
        });
    }

    private function qty(mixed $value, int $decimals, string $field): string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return '0.000000';
        }
        if (! Decimal::isDecimal($v, $decimals) || Decimal::compare($v, '0') < 0) {
            throw ManufacturingException::make('manufacturing.qty_invalid', "Enter zero or more with at most {$decimals} decimals.", 422, $field);
        }

        return Decimal::round($v, 6);
    }
}
