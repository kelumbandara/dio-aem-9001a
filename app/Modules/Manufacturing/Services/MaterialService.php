<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\Manufacturing\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Models\ProductionMaterialMovement;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderMaterial;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Materials going to and coming back from a production order. Issuing takes stock out of the order's source warehouse into
 * work in progress (the stock ledger values it at the item's average cost; Finance journals it from the event);
 * returning puts it back at the cost it left at. Both go through the one stock poster, in the same database transaction
 * as the order's own records, and every issue and return is kept as its own permanent row.
 */
final class MaterialService
{
    public const SOURCE_ISSUE = 'production_issue';

    public const SOURCE_RETURN = 'production_return';

    public function __construct(
        private readonly StockPoster $stock,
        private readonly ItemCatalog $items,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * Issue materials to a released order. Going past what the order needs (plus the allowed percentage) is refused unless
     * the caller may over-issue and says why.
     *
     * @param  list<array{material?: int|string, item?: string, qty: string, lot_no?: string|null}>  $lines  material = the order's line number, or item = the component's code
     * @return array{movements: list<ProductionMaterialMovement>, document_no: string}
     *
     * @throws BusinessException
     */
    public function issue(ProductionOrder $order, array $lines, ?int $by = null, bool $mayOverIssue = false, ?string $overReason = null, ?string $date = null): array
    {
        return DB::transaction(function () use ($order, $lines, $by, $mayOverIssue, $overReason, $date): array {
            $locked = $this->lockOpenOrder($order);
            $prepared = $this->prepare($locked, $lines);
            $over = false;
            $pct = (string) config('manufacturing.over_issue_pct');

            foreach ($prepared as $p) {
                /** @var ProductionOrderMaterial $m */
                $m = $p['material'];
                $limit = Decimal::mul((string) $m->required_qty, Decimal::add('1', Decimal::div($pct, '100')));
                $after = Decimal::add($m->netQty(), $p['qty']);
                if (Decimal::compare($after, $limit) > 0) {
                    $info = $this->items->find($m->item_id);
                    if (! $mayOverIssue) {
                        throw ManufacturingException::make('manufacturing.over_issue', 'That is more than this order needs for '.($info->code ?? $m->item_id).' (needs '.Decimal::trim((string) $m->required_qty).', already with the order '.Decimal::trim($m->netQty()).'). A supervisor with permission can allow it.', 422, $p['field'].'.qty');
                    }
                    if (trim((string) $overReason) === '') {
                        throw ManufacturingException::make('manufacturing.over_issue_reason', 'Say why more than the requirement is being issued.', 422, 'reason');
                    }
                    $over = true;
                }
            }

            $posted = $this->stock->post(new StockMovement(
                type: 'issue', postingDate: $this->day($date), reason: 'production',
                lines: array_map(fn (array $p) => new StockLine(itemId: $p['material']->item_id, qty: $p['qty'], warehouseId: $locked->source_warehouse_id, lotNo: $p['lot_no']), $prepared),
                reference: $locked->order_no, notes: 'Materials issued to production order '.$locked->order_no,
                sourceType: self::SOURCE_ISSUE, sourceId: (string) Str::uuid(), createdBy: $by,
            ));

            $values = [];
            foreach ($posted->lines as $l) {
                $values[$l['itemId']] = Decimal::add($values[$l['itemId']] ?? '0', $l['value']);
            }

            $movements = [];
            foreach ($prepared as $p) {
                /** @var ProductionOrderMaterial $m */
                $m = $p['material'];
                $value = Decimal::round($values[$m->item_id] ?? '0', 4);
                $m->forceFill([
                    'issued_qty' => Decimal::add((string) $m->issued_qty, $p['qty']),
                    'issued_value' => Decimal::round(Decimal::add((string) $m->issued_value, $value), 4),
                ])->save();
                $movements[] = ProductionMaterialMovement::query()->create([
                    'order_id' => $locked->id, 'material_id' => $m->id, 'kind' => 'issue', 'qty' => $p['qty'], 'value' => $value,
                    'stock_document_id' => $posted->documentId, 'stock_document_no' => $posted->documentNo, 'posted_on' => $this->day($date),
                    'reason' => $over ? mb_substr(trim((string) $overReason), 0, 255) : null, 'over_issue' => $this->isOver($m, $pct), 'created_by' => $by,
                ]);
            }

            if ($locked->status === 'released') {
                $locked->forceFill(['status' => 'in_progress', 'version' => $locked->version + 1])->save();
            }
            $this->audit->record('manufacturing.materials_issued', $locked, [], ['order_no' => $locked->order_no, 'stock_document' => $posted->documentNo, 'lines' => count($prepared), 'over_issue' => $over]);
            $this->outbox->record('manufacturing.materials.issued', ['order_id' => $locked->id, 'order_no' => $locked->order_no, 'stock_document_no' => $posted->documentNo, 'value' => $posted->totalValue], 'production_order', $locked->id);

            return ['movements' => $movements, 'document_no' => $posted->documentNo];
        });
    }

    /**
     * Take materials back into stock from an order. Quantity cannot exceed what the order still holds; the stock goes back
     * at the average cost it left at, so work in progress is relieved by the same amount it was charged.
     *
     * @param  list<array{material?: int|string, item?: string, qty: string, lot_no?: string|null}>  $lines
     * @return array{movements: list<ProductionMaterialMovement>, document_no: string}
     *
     * @throws BusinessException
     */
    public function return(ProductionOrder $order, array $lines, ?int $by = null, ?string $reason = null, ?string $date = null): array
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ManufacturingException::make('manufacturing.reason_required', 'Say why the material is being returned.', 422, 'reason');
        }

        return DB::transaction(function () use ($order, $lines, $by, $reason, $date): array {
            $locked = $this->lockOpenOrder($order, ['released', 'in_progress']);
            $prepared = $this->prepare($locked, $lines);
            $stockLines = [];
            foreach ($prepared as $p) {
                /** @var ProductionOrderMaterial $m */
                $m = $p['material'];
                if (Decimal::compare($p['qty'], $m->netQty()) > 0) {
                    $info = $this->items->find($m->item_id);
                    throw ManufacturingException::make('manufacturing.return_too_much', 'Only '.Decimal::trim($m->netQty()).' of '.($info->code ?? $m->item_id).' is with this order.', 422, $p['field'].'.qty');
                }
                // The cost of what is still with the order: value issued less value returned, per unit still held.
                $heldValue = Decimal::sub((string) $m->issued_value, (string) $m->returned_value);
                $unitCost = Decimal::compare($heldValue, '0') > 0 ? Decimal::round(Decimal::div($heldValue, $m->netQty()), 6) : '0';
                $stockLines[] = new StockLine(itemId: $m->item_id, qty: $p['qty'], warehouseId: $locked->source_warehouse_id, lotNo: $p['lot_no'], unitCost: $unitCost);
            }

            $posted = $this->stock->post(new StockMovement(
                type: 'receipt', postingDate: $this->day($date), reason: 'production', lines: $stockLines,
                reference: $locked->order_no, notes: 'Materials returned from production order '.$locked->order_no.': '.$reason,
                sourceType: self::SOURCE_RETURN, sourceId: (string) Str::uuid(), createdBy: $by,
            ));

            $values = [];
            foreach ($posted->lines as $l) {
                $values[$l['itemId']] = Decimal::add($values[$l['itemId']] ?? '0', $l['value']);
            }
            $movements = [];
            foreach ($prepared as $p) {
                /** @var ProductionOrderMaterial $m */
                $m = $p['material'];
                $value = Decimal::round($values[$m->item_id] ?? '0', 4);
                $m->forceFill([
                    'returned_qty' => Decimal::add((string) $m->returned_qty, $p['qty']),
                    'returned_value' => Decimal::round(Decimal::add((string) $m->returned_value, $value), 4),
                ])->save();
                $movements[] = ProductionMaterialMovement::query()->create([
                    'order_id' => $locked->id, 'material_id' => $m->id, 'kind' => 'return', 'qty' => $p['qty'], 'value' => $value,
                    'stock_document_id' => $posted->documentId, 'stock_document_no' => $posted->documentNo, 'posted_on' => $this->day($date),
                    'reason' => mb_substr($reason, 0, 255), 'over_issue' => false, 'created_by' => $by,
                ]);
            }

            $this->audit->record('manufacturing.materials_returned', $locked, [], ['order_no' => $locked->order_no, 'stock_document' => $posted->documentNo, 'lines' => count($prepared), 'reason' => $reason]);
            $this->outbox->record('manufacturing.materials.returned', ['order_id' => $locked->id, 'order_no' => $locked->order_no, 'stock_document_no' => $posted->documentNo, 'value' => $posted->totalValue], 'production_order', $locked->id);

            return ['movements' => $movements, 'document_no' => $posted->documentNo];
        });
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<string>  $statuses
     */
    private function lockOpenOrder(ProductionOrder $order, array $statuses = ['released', 'in_progress']): ProductionOrder
    {
        $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        if (! in_array($locked->status, $statuses, true)) {
            throw ManufacturingException::state('production order', $locked->status, 'released or in progress');
        }

        return $locked;
    }

    /**
     * Resolve each line to a material row of the order (locked), check the quantity, refuse a material named twice.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return list<array{material: ProductionOrderMaterial, qty: string, lot_no: string|null, field: string}>
     */
    private function prepare(ProductionOrder $order, array $lines): array
    {
        if ($lines === [] || count($lines) > 200) {
            throw ManufacturingException::make('manufacturing.lines_invalid', 'Enter between 1 and 200 material lines.', 422, 'lines');
        }
        $materials = ProductionOrderMaterial::query()->where('order_id', $order->id)->orderBy('line_no')->lockForUpdate()->get();
        $byLine = $materials->keyBy('line_no');
        $byItem = $this->items->findMany($materials->pluck('item_id')->all());
        $byCode = [];
        foreach ($materials as $m) {
            if (isset($byItem[$m->item_id])) {
                $byCode[strtolower($byItem[$m->item_id]->code)] = $m;
            }
        }

        $out = [];
        $seen = [];
        foreach (array_values($lines) as $i => $line) {
            $field = "lines.{$i}";
            $m = isset($line['material']) && $line['material'] !== ''
                ? $byLine->get((int) $line['material'])
                : ($byCode[strtolower(trim((string) ($line['item'] ?? '')))] ?? null);
            if ($m === null) {
                throw ManufacturingException::make('manufacturing.material_not_on_order', 'That material is not on this order.', 422, "{$field}.material");
            }
            if (isset($seen[$m->id])) {
                throw ManufacturingException::make('manufacturing.material_twice', 'A material can be on one line only; add the quantities together.', 422, "{$field}.material");
            }
            $seen[$m->id] = true;

            $info = $byItem[$m->item_id];
            $qty = trim((string) ($line['qty'] ?? ''));
            if ($qty === '' || ! Decimal::isDecimal($qty, $info->baseUomDecimals) || Decimal::compare($qty, '0') <= 0) {
                throw ManufacturingException::make('manufacturing.qty_invalid', "Enter a quantity above zero for {$info->code} with at most {$info->baseUomDecimals} decimals ({$info->baseUomCode}).", 422, "{$field}.qty");
            }
            $lot = isset($line['lot_no']) && trim((string) $line['lot_no']) !== '' ? trim((string) $line['lot_no']) : null;
            $out[] = ['material' => $m, 'qty' => Decimal::trim(Decimal::round($qty, 6)), 'lot_no' => $lot, 'field' => $field];
        }

        return $out;
    }

    private function isOver(ProductionOrderMaterial $m, string $pct): bool
    {
        $limit = Decimal::mul((string) $m->required_qty, Decimal::add('1', Decimal::div($pct, '100')));

        return Decimal::compare($m->netQty(), $limit) > 0;
    }

    private function day(?string $date): string
    {
        $tz = config('core.display_timezone');
        if ($date !== null && trim($date) !== '') {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', trim($date), $tz);
            if ($d === null || $d->toDateString() !== trim($date)) {
                throw ManufacturingException::make('manufacturing.date_invalid', 'Use a date like 2026-11-15.', 422, 'date');
            }

            return $d->toDateString();
        }

        return CarbonImmutable::now($tz)->toDateString();
    }
}
