<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Manufacturing\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionLine;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\WorkCenter;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Production orders. A draft is made against the product's ACTIVE bill of materials: its material requirements and routing
 * steps are worked out and written onto the order, and stay as they were even if the bill is replaced later. Releasing
 * the order lets materials be issued. The six quantities (planned, reported, accepted, rejected, rework, received) exist
 * from the start; this sprint fills in only the planned one (output reporting comes next).
 */
final class ProductionOrderService
{
    public function __construct(
        private readonly ItemCatalog $items,
        private readonly BomQueries $boms,
        private readonly WarehouseDirectory $warehouses,
        private readonly StockAvailability $stock,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data  item|item_id (new only), qty, planned_start?, planned_end?, source_warehouse|source_warehouse_id, output_warehouse|output_warehouse_id, line?|production_line_id?, notes?, version? (edit)
     *
     * @throws BusinessException
     */
    public function save(array $data, ?ProductionOrder $order = null, ?int $by = null): ProductionOrder
    {
        return DB::transaction(function () use ($data, $order, $by): ProductionOrder {
            if ($order !== null) {
                $order = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($order->status !== 'draft') {
                    throw ManufacturingException::state('production order', $order->status, 'a draft');
                }
                if (isset($data['version']) && (int) $data['version'] !== $order->version) {
                    throw ManufacturingException::stale('production order');
                }
                $item = $this->items->requireActive($order->item_id);
            } else {
                $item = isset($data['item_id']) ? $this->items->requireActive((int) $data['item_id']) : $this->itemByCode((string) ($data['item'] ?? ''));
            }

            $bom = $this->boms->activeFor($item->id);
            if ($bom === null) {
                throw ManufacturingException::make('manufacturing.no_active_bom', "{$item->code} has no active bill of materials. Activate one first.", 422, 'item');
            }
            $qty = trim((string) ($data['qty'] ?? ''));
            if ($qty === '' || ! Decimal::isDecimal($qty, $item->baseUomDecimals) || Decimal::compare($qty, '0') <= 0) {
                throw ManufacturingException::make('manufacturing.qty_invalid', "Enter a quantity above zero with at most {$item->baseUomDecimals} decimals ({$item->baseUomCode}).", 422, 'qty');
            }
            $qty = Decimal::round($qty, 6);

            $today = $this->today();
            $start = $this->date($data['planned_start'] ?? null, $today, 'planned_start');
            $end = $this->date($data['planned_end'] ?? null, $start, 'planned_end');
            if ($end < $start) {
                throw ManufacturingException::make('manufacturing.dates_invalid', 'The planned finish cannot be before the planned start.', 422, 'planned_end');
            }
            $source = $this->warehouse($data, 'source_warehouse');
            $output = $this->warehouse($data, 'output_warehouse');
            $lineId = $this->line($data);

            $values = [
                'item_id' => $item->id, 'bom_version_id' => $bom->id, 'planned_qty' => $qty, 'planned_start' => $start->toDateString(), 'planned_end' => $end->toDateString(),
                'production_line_id' => $lineId, 'source_warehouse_id' => $source, 'output_warehouse_id' => $output,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? mb_substr(trim((string) $data['notes']), 0, 2000) : null,
            ];

            if ($order === null) {
                $this->numbering->define('manufacturing.order', 'MO/{FY}/{SEQ}', 5, 'fiscal_yearly');
                $order = ProductionOrder::query()->create($values + [
                    'order_no' => $this->numbering->next('manufacturing.order', null, $start), 'status' => 'draft', 'version' => 1, 'created_by' => $by,
                ]);
            } else {
                $order->forceFill($values + ['version' => $order->version + 1])->save();
                $order->materials()->delete();
                $order->operations()->delete();
            }

            foreach ($this->boms->requirements($bom, $qty) as $r) {
                $order->materials()->create(['line_no' => $r['line_no'], 'item_id' => $r['item_id'], 'required_qty' => $r['qty']]);
            }
            foreach ($bom->operations as $op) {
                $order->operations()->create([
                    'op_no' => $op->op_no, 'name' => $op->name, 'work_center_id' => $op->work_center_id, 'machine_id' => $op->machine_id,
                    'planned_setup_minutes' => $op->setup_minutes, 'planned_run_minutes' => Decimal::round(Decimal::mul((string) $op->run_minutes, $qty), 4), 'status' => 'pending',
                ]);
            }

            $this->audit->record('manufacturing.order_saved', $order, [], ['order_no' => $order->order_no, 'item' => $item->code, 'qty' => $qty, 'bom_version_no' => $bom->version_no]);

            return $order->refresh();
        });
    }

    /**
     * Let the order start: materials may now be issued. The bill it was made with must still be the active one (otherwise
     * save the order again to pick up the current bill). Shortages are reported, not refused: stock may arrive before it is issued.
     *
     * @return array{order: ProductionOrder, shortages: list<array{item_id: int, required: string, available: string}>}
     *
     * @throws BusinessException
     */
    public function release(ProductionOrder $order, int $by): array
    {
        return DB::transaction(function () use ($order, $by): array {
            $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw ManufacturingException::state('production order', $locked->status, 'a draft');
            }
            $bom = $locked->bomVersion;
            if ($bom->status !== 'active') {
                throw ManufacturingException::make('manufacturing.bom_replaced', 'The bill of materials this order was made with is no longer active. Open the order and save it again to use the current one.', 409);
            }
            if ($locked->materials()->count() === 0) {
                throw ManufacturingException::make('manufacturing.no_materials', 'This order has no material requirements.', 422);
            }
            foreach ($locked->operations as $op) {
                $center = WorkCenter::query()->find($op->work_center_id);
                $machine = $op->machine_id === null ? null : Machine::query()->find($op->machine_id);
                if ($center === null || ! $center->is_active || ($machine !== null && $machine->status === 'retired')) {
                    throw ManufacturingException::make('manufacturing.routing_unavailable', "Step {$op->op_no} ({$op->name}) uses a work centre or machine that is no longer available.", 422);
                }
            }
            if (! $this->warehouses->isActive($locked->source_warehouse_id) || ! $this->warehouses->isActive($locked->output_warehouse_id)) {
                throw ManufacturingException::make('manufacturing.warehouse_inactive', 'A warehouse on this order is no longer active.', 422);
            }

            $shortages = [];
            foreach ($locked->materials as $m) {
                $available = $this->stock->level($m->item_id, $locked->source_warehouse_id)->available;
                if (Decimal::compare($available, (string) $m->required_qty) < 0) {
                    $shortages[] = ['item_id' => $m->item_id, 'required' => (string) $m->required_qty, 'available' => $available];
                }
            }

            $locked->forceFill(['status' => 'released', 'released_at' => now(), 'released_by' => $by, 'version' => $locked->version + 1])->save();
            $this->audit->record('manufacturing.order_released', $locked, ['status' => 'draft'], ['status' => 'released', 'order_no' => $locked->order_no, 'shortages' => count($shortages)]);
            $this->outbox->record('manufacturing.order.released', ['order_id' => $locked->id, 'order_no' => $locked->order_no, 'item_id' => $locked->item_id, 'planned_qty' => (string) $locked->planned_qty], 'production_order', $locked->id);

            return ['order' => $locked->refresh(), 'shortages' => $shortages];
        });
    }

    /** Cancel an order nothing is being made on: no material may still be with it (return it first). */
    public function cancel(ProductionOrder $order, string $reason, int $by): ProductionOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ManufacturingException::make('manufacturing.reason_required', 'Say why the order is cancelled.', 422, 'reason');
        }

        return DB::transaction(function () use ($order, $reason): ProductionOrder {
            $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'released', 'in_progress'], true)) {
                throw ManufacturingException::state('production order', $locked->status, 'a draft, released or in progress');
            }
            if (Decimal::compare((string) $locked->reported_qty, '0') > 0) {
                throw ManufacturingException::make('manufacturing.has_output', 'Output has been reported on this order; it cannot be cancelled.', 422);
            }
            foreach ($locked->materials as $m) {
                if (Decimal::compare($m->netQty(), '0') !== 0) {
                    $info = $this->items->find($m->item_id);
                    throw ManufacturingException::make('manufacturing.material_outstanding', 'Return the materials first: '.($info->code ?? $m->item_id).' is still with this order ('.$m->netQty().').', 422);
                }
            }
            $from = $locked->status;
            $locked->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255), 'version' => $locked->version + 1])->save();
            $this->audit->record('manufacturing.order_cancelled', $locked, ['status' => $from], ['status' => 'cancelled', 'order_no' => $locked->order_no, 'reason' => $reason]);
            $this->outbox->record('manufacturing.order.cancelled', ['order_id' => $locked->id, 'order_no' => $locked->order_no], 'production_order', $locked->id);

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ helpers

    private function itemByCode(string $code): ItemInfo
    {
        $info = trim($code) === '' ? null : $this->items->findByCode(trim($code));
        if ($info === null) {
            throw ManufacturingException::make('manufacturing.item_not_found', trim($code) === '' ? 'Choose the item to make.' : "There is no item {$code}.", 422, 'item');
        }

        return $this->items->requireActive($info->id);
    }

    /** @param  array<string, mixed>  $data */
    private function warehouse(array $data, string $field): int
    {
        $id = isset($data["{$field}_id"]) ? (int) $data["{$field}_id"] : $this->warehouses->idByCode(trim((string) ($data[$field] ?? '')));
        if ($id === null || $id === 0 || ! $this->warehouses->isActive($id)) {
            throw ManufacturingException::make('manufacturing.warehouse_invalid', 'Choose an active warehouse.', 422, $field);
        }

        return $id;
    }

    /** @param  array<string, mixed>  $data */
    private function line(array $data): ?int
    {
        if (isset($data['production_line_id']) && $data['production_line_id'] !== '') {
            $line = ProductionLine::query()->find((int) $data['production_line_id']);
        } else {
            $code = strtolower(trim((string) ($data['line'] ?? '')));
            if ($code === '') {
                return null;
            }
            $line = ProductionLine::query()->whereRaw('lower(code) = ?', [$code])->first();
        }
        if ($line === null || ! $line->is_active) {
            throw ManufacturingException::make('manufacturing.line_invalid', 'Choose an active production line, or leave it empty.', 422, 'line');
        }

        return $line->id;
    }

    private function date(mixed $value, CarbonImmutable $default, string $field): CarbonImmutable
    {
        $v = trim((string) $value);
        if ($v === '') {
            return $default;
        }
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $v, config('core.display_timezone'));
        if ($d === null || $d->toDateString() !== $v) {
            throw ManufacturingException::make('manufacturing.date_invalid', 'Use a date like 2026-11-15.', 422, $field);
        }

        return $d;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('core.display_timezone'))->startOfDay();
    }
}
