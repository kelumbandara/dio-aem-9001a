<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\Manufacturing\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Models\FinishedGoodsReceipt;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finished goods into stock, and completing the order.
 *
 * Costing is materials only (the owner's decision): the material cost the order holds (issued less returned) is spread over the
 * good units. A unit is costed at  remaining work-in-progress ÷ units still expected  where the units expected are the larger of
 * "planned less received" and "accepted less received". So good units absorb their share of everything the order consumed, scrap
 * included, and whatever is left when the order is completed (the order made fewer good units than planned, or rounding) is the
 * order's variance, posted once to the production variance account.
 */
final class FinishedGoodsService
{
    public const SOURCE_RECEIPT = 'production_receipt';

    public function __construct(
        private readonly StockPoster $stock,
        private readonly ItemCatalog $items,
        private readonly ModuleRegistry $modules,
        private readonly JournalPoster $journals,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * Receive accepted units into the order's output warehouse.
     *
     * @param  array<string, mixed>  $data  qty, lot_no?, expiry_date?, date?
     *
     * @throws BusinessException
     */
    public function receive(ProductionOrder $order, array $data, ?int $by = null): FinishedGoodsReceipt
    {
        return DB::transaction(function () use ($order, $data, $by): FinishedGoodsReceipt {
            $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'in_progress') {
                throw ManufacturingException::state('production order', $locked->status, 'in progress');
            }
            $item = $this->items->requireActive($locked->item_id);
            $qty = trim((string) ($data['qty'] ?? ''));
            if ($qty === '' || ! Decimal::isDecimal($qty, $item->baseUomDecimals) || Decimal::compare($qty, '0') <= 0) {
                throw ManufacturingException::make('manufacturing.qty_invalid', "Enter a quantity above zero with at most {$item->baseUomDecimals} decimals ({$item->baseUomCode}).", 422, 'qty');
            }
            $qty = Decimal::round($qty, 6);
            $waiting = Decimal::sub((string) $locked->accepted_qty, (string) $locked->received_qty);
            if (Decimal::compare($qty, $waiting) > 0) {
                throw ManufacturingException::make('manufacturing.receipt_too_much', 'Only '.Decimal::trim($waiting).' '.$item->baseUomCode.' have passed inspection and are waiting to be received.', 422, 'qty');
            }

            $held = $this->heldValue($locked);
            $expected = Decimal::compare(Decimal::sub((string) $locked->planned_qty, (string) $locked->received_qty), $waiting) > 0 ? Decimal::sub((string) $locked->planned_qty, (string) $locked->received_qty) : $waiting;
            $unitCost = Decimal::compare($held, '0') > 0 ? Decimal::round(Decimal::div($held, $expected), 6) : '0.000000';

            $lot = trim((string) ($data['lot_no'] ?? ''));
            $posted = $this->stock->post(new StockMovement(
                type: 'receipt', postingDate: $this->day($data['date'] ?? null), reason: 'production',
                lines: [new StockLine(itemId: $locked->item_id, qty: Decimal::trim($qty), warehouseId: $locked->output_warehouse_id, lotNo: $lot === '' ? null : $lot, expiryDate: trim((string) ($data['expiry_date'] ?? '')) === '' ? null : trim((string) $data['expiry_date']), unitCost: $unitCost)],
                reference: $locked->order_no, notes: 'Finished goods from production order '.$locked->order_no,
                sourceType: self::SOURCE_RECEIPT, sourceId: (string) Str::uuid(), createdBy: $by,
            ));
            $value = Decimal::round(array_reduce($posted->lines, fn (string $c, array $l) => Decimal::add($c, $l['value']), '0'), 4);

            $receipt = FinishedGoodsReceipt::query()->create([
                'order_id' => $locked->id, 'qty' => $qty, 'unit_cost' => $unitCost, 'value' => $value, 'received_on' => $this->day($data['date'] ?? null),
                'lot_no' => $lot === '' ? null : $lot, 'stock_document_id' => $posted->documentId, 'stock_document_no' => $posted->documentNo, 'created_by' => $by,
            ]);
            $locked->forceFill([
                'received_qty' => Decimal::add((string) $locked->received_qty, $qty), 'fg_value' => Decimal::round(Decimal::add((string) $locked->fg_value, $value), 4), 'version' => $locked->version + 1,
            ])->save();

            $this->audit->record('manufacturing.fg_received', $locked, [], ['order_no' => $locked->order_no, 'qty' => $qty, 'unit_cost' => $unitCost, 'value' => $value, 'stock_document' => $posted->documentNo]);
            $this->outbox->record('manufacturing.receipt.posted', ['order_id' => $locked->id, 'order_no' => $locked->order_no, 'receipt_id' => $receipt->id, 'qty' => $qty, 'value' => $value, 'stock_document_no' => $posted->documentNo], 'production_order', $locked->id);

            return $receipt;
        });
    }

    /**
     * Complete the order: everything made has been judged, reworked units are back, every accepted unit is in stock. The material
     * cost not absorbed by good output becomes the order's variance and is posted to the production variance account
     * (work in progress is then nil for this order). After this nothing more can be reported, inspected, issued or returned.
     *
     * @throws BusinessException
     */
    public function complete(ProductionOrder $order, ?int $by = null): ProductionOrder
    {
        return DB::transaction(function () use ($order, $by): ProductionOrder {
            $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'in_progress') {
                throw ManufacturingException::state('production order', $locked->status, 'in progress');
            }
            if (Decimal::compare((string) $locked->reported_qty, '0') <= 0) {
                throw ManufacturingException::make('manufacturing.nothing_made', 'Nothing has been reported on this order. Cancel it instead.', 422);
            }
            if (Decimal::compare($locked->pendingQty(), '0') > 0) {
                throw ManufacturingException::make('manufacturing.inspection_pending', Decimal::trim($locked->pendingQty()).' units are still waiting for inspection.', 422);
            }
            if (Decimal::compare((string) $locked->rework_qty, '0') > 0) {
                throw ManufacturingException::make('manufacturing.rework_pending', Decimal::trim((string) $locked->rework_qty).' units are still in rework.', 422);
            }
            if (Decimal::compare((string) $locked->received_qty, (string) $locked->accepted_qty) !== 0) {
                throw ManufacturingException::make('manufacturing.receipt_pending', Decimal::trim(Decimal::sub((string) $locked->accepted_qty, (string) $locked->received_qty)).' accepted units are not yet received into stock.', 422);
            }

            $variance = $this->heldValue($locked);
            $day = CarbonImmutable::now(config('core.display_timezone'))->toDateString();
            if (Decimal::compare($variance, '0', 4) !== 0 && $this->modules->isEnabled('finance')) {
                $amount = ltrim(Decimal::round($variance, 4), '-');
                $over = str_starts_with(Decimal::round($variance, 4), '-');   // absorbed more than the order consumed (rounding)
                $this->journals->post(new JournalRequest(
                    entryDate: $day, description: "Production variance {$locked->order_no}",
                    lines: [
                        $over ? JournalLineData::debit($amount, 'wip') : JournalLineData::debit($amount, 'production_variance'),
                        $over ? JournalLineData::credit($amount, 'production_variance') : JournalLineData::credit($amount, 'wip'),
                    ],
                    reference: $locked->order_no, sourceType: 'production_order_completion', sourceId: (string) $locked->id, kind: 'system', createdBy: $by, rollForward: true,
                ));
            }

            $locked->operations()->update(['status' => 'done']);
            $locked->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $by, 'variance_value' => Decimal::round($variance, 4), 'version' => $locked->version + 1])->save();
            $this->audit->record('manufacturing.order_completed', $locked, ['status' => 'in_progress'], ['status' => 'completed', 'order_no' => $locked->order_no, 'variance' => $variance]);
            $this->outbox->record('manufacturing.order.completed', ['order_id' => $locked->id, 'order_no' => $locked->order_no, 'received_qty' => (string) $locked->received_qty, 'fg_value' => (string) $locked->fg_value, 'variance_value' => Decimal::round($variance, 4)], 'production_order', $locked->id);

            return $locked->refresh();
        });
    }

    /** Material cost the order holds that has not gone into finished goods: issued less returned less received value. */
    public function heldValue(ProductionOrder $order): string
    {
        $net = '0';
        foreach ($order->materials()->get() as $m) {
            $net = Decimal::add($net, Decimal::sub((string) $m->issued_value, (string) $m->returned_value));
        }

        return Decimal::round(Decimal::sub($net, (string) $order->fg_value), 4);
    }

    private function day(mixed $date): string
    {
        $tz = config('core.display_timezone');
        $v = trim((string) $date);
        if ($v === '') {
            return CarbonImmutable::now($tz)->toDateString();
        }
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $v, $tz);
        if ($d === null || $d->toDateString() !== $v || $d->greaterThan(CarbonImmutable::now($tz)->endOfDay())) {
            throw ManufacturingException::make('manufacturing.date_invalid', 'Use today\'s date or an earlier one, like 2026-11-15.', 422, 'date');
        }

        return $d->toDateString();
    }
}
