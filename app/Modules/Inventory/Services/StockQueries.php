<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read models for the stock screens and API: lists with item names filled in through the item catalog
 * (Inventory never reads Master Data tables). All quantities are strings in the item's base unit.
 */
final class StockQueries
{
    public function __construct(
        private readonly ItemCatalog $items,
        private readonly StockAvailability $availability,
        private readonly ReorderMonitor $reorder,
    ) {}

    /**
     * One row per item that has stock.
     *
     * @param  array{q?: ?string, warehouse_id?: ?int, below_reorder?: bool}  $filter
     * @return LengthAwarePaginator<int|string, mixed>
     */
    public function summary(array $filter, int $perPage = 25): LengthAwarePaginator
    {
        $query = DB::table('item_costs as c')->where('c.qty', '>', 0);

        $term = trim((string) ($filter['q'] ?? ''));
        if ($term !== '') {
            $query->whereIn('c.item_id', array_keys($this->items->search($term)) ?: [0]);
        }
        $warehouseId = $filter['warehouse_id'] ?? null;
        if ($warehouseId !== null) {
            $query->whereExists(fn (Builder $b) => $b->select(DB::raw(1))->from('stock_balances as b')->join('locations as l', 'l.id', '=', 'b.location_id')
                ->whereColumn('b.item_id', 'c.item_id')->where('l.warehouse_id', $warehouseId)->where('b.on_hand', '>', 0));
        }
        if (! empty($filter['below_reorder'])) {
            $query->whereIn('c.item_id', collect($this->belowReorder())->pluck('item_id')->all() ?: [0]);
        }

        $page = $query->orderBy('c.item_id')->paginate($perPage)->withQueryString();
        $info = $this->items->findMany($page->getCollection()->pluck('item_id')->map(fn ($id) => (int) $id)->all());

        $page->setCollection($page->getCollection()->map(function ($row) use ($info, $warehouseId) {
            $item = $info[(int) $row->item_id] ?? null;
            $level = $this->availability->level((int) $row->item_id, $warehouseId);
            $avg = $this->availability->averageCost((int) $row->item_id);

            return [
                'item_id' => (int) $row->item_id, 'item_code' => $item?->code, 'item_name' => $item?->name, 'uom' => $item?->baseUomCode,
                'on_hand' => $level->onHand, 'reserved' => $level->reserved, 'available' => $level->available,
                'average_cost' => $avg,
                'value' => $warehouseId === null ? Decimal::round((string) $row->value, 4) : Decimal::round(Decimal::mul($level->onHand, $avg), 4),
            ];
        }));

        return $page;
    }

    /**
     * Where one item's stock sits right now.
     *
     * @return list<array<string, mixed>>
     */
    public function balances(int $itemId): array
    {
        return DB::table('stock_balances as b')->join('locations as l', 'l.id', '=', 'b.location_id')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoin('lots as lt', 'lt.id', '=', 'b.lot_id')
            ->where('b.item_id', $itemId)->where('b.on_hand', '>', 0)
            ->orderBy('w.code')->orderBy('l.code')->orderByRaw('lt.expiry_date asc nulls last')->orderBy('lt.lot_no')
            ->get(['w.code as warehouse', 'w.name as warehouse_name', 'l.code as location', 'l.is_default', 'lt.lot_no', 'lt.expiry_date', 'b.on_hand'])
            ->map(fn ($r) => [
                'warehouse' => $r->warehouse, 'warehouse_name' => $r->warehouse_name, 'location' => $r->is_default ? null : $r->location,
                'lot' => $r->lot_no, 'expiry_date' => $r->expiry_date, 'on_hand' => Decimal::round((string) $r->on_hand, 6),
            ])->all();
    }

    /**
     * The ledger, newest first.
     *
     * @param  array{item_id?: ?int, warehouse_id?: ?int, document_id?: ?int, from?: ?string, to?: ?string}  $filter
     * @return LengthAwarePaginator<int|string, mixed>
     */
    public function ledger(array $filter, int $perPage = 50): LengthAwarePaginator
    {
        $query = DB::table('stock_ledger as e')->join('stock_documents as d', 'd.id', '=', 'e.document_id')
            ->join('locations as l', 'l.id', '=', 'e.location_id')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoin('lots as lt', 'lt.id', '=', 'e.lot_id')
            ->when($filter['item_id'] ?? null, fn ($q, $v) => $q->where('e.item_id', $v))
            ->when($filter['warehouse_id'] ?? null, fn ($q, $v) => $q->where('l.warehouse_id', $v))
            ->when($filter['document_id'] ?? null, fn ($q, $v) => $q->where('e.document_id', $v))
            ->when($filter['from'] ?? null, fn ($q, $v) => $q->where('e.posting_date', '>=', $v))
            ->when($filter['to'] ?? null, fn ($q, $v) => $q->where('e.posting_date', '<=', $v))
            ->orderByDesc('e.id');

        $page = $query->paginate($perPage, ['e.id', 'e.posting_date', 'e.item_id', 'e.qty', 'e.unit_cost', 'e.value', 'd.document_no', 'd.public_id as document_public_id', 'd.doc_type', 'w.code as warehouse', 'l.code as location', 'l.is_default', 'lt.lot_no'])->withQueryString();
        $info = $this->items->findMany($page->getCollection()->pluck('item_id')->map(fn ($id) => (int) $id)->all());

        $page->setCollection($page->getCollection()->map(fn ($r) => [
            'id' => (int) $r->id, 'posting_date' => $r->posting_date, 'item_code' => $info[(int) $r->item_id]->code ?? null, 'item_name' => $info[(int) $r->item_id]->name ?? null,
            'document_no' => $r->document_no, 'document_id' => $r->document_public_id, 'type' => $r->doc_type, 'warehouse' => $r->warehouse,
            'location' => $r->is_default ? null : $r->location, 'lot' => $r->lot_no,
            'qty' => Decimal::round((string) $r->qty, 6), 'unit_cost' => Decimal::round((string) $r->unit_cost, 6), 'value' => Decimal::round((string) $r->value, 4),
        ]));

        return $page;
    }

    /**
     * Lots with stock, earliest expiry first.
     *
     * @param  array{item_id?: ?int, expiring_within_days?: ?int}  $filter
     * @return LengthAwarePaginator<int|string, mixed>
     */
    public function lots(array $filter, int $perPage = 50): LengthAwarePaginator
    {
        $today = now((string) config('core.display_timezone'))->toDateString();
        $stocked = DB::table('stock_balances')->select('lot_id', DB::raw('sum(on_hand) as on_hand'))->whereNotNull('lot_id')->groupBy('lot_id')->havingRaw('sum(on_hand) > 0');
        $query = DB::table('lots as lt')->joinSub($stocked, 's', 's.lot_id', '=', 'lt.id')
            ->when($filter['item_id'] ?? null, fn ($q, $v) => $q->where('lt.item_id', $v))
            ->when(isset($filter['expiring_within_days']), fn ($q) => $q->whereNotNull('lt.expiry_date')->where('lt.expiry_date', '<=', now((string) config('core.display_timezone'))->addDays((int) $filter['expiring_within_days'])->toDateString()))
            ->orderByRaw('lt.expiry_date asc nulls last')->orderBy('lt.id');

        $page = $query->paginate($perPage, ['lt.id', 'lt.item_id', 'lt.lot_no', 'lt.expiry_date', 'lt.received_on', 's.on_hand'])->withQueryString();
        $info = $this->items->findMany($page->getCollection()->pluck('item_id')->map(fn ($id) => (int) $id)->all());

        $page->setCollection($page->getCollection()->map(fn ($r) => [
            'item_code' => $info[(int) $r->item_id]->code ?? null, 'item_name' => $info[(int) $r->item_id]->name ?? null, 'lot' => $r->lot_no,
            'expiry_date' => $r->expiry_date, 'received_on' => $r->received_on, 'on_hand' => Decimal::round((string) $r->on_hand, 6),
            'expired' => $r->expiry_date !== null && $r->expiry_date < $today,
            'days_left' => $r->expiry_date !== null ? (int) floor((strtotime($r->expiry_date) - strtotime($today)) / 86400) : null,
        ]));

        return $page;
    }

    /**
     * Rules whose item is currently below its minimum.
     *
     * @return list<array<string, mixed>>
     */
    public function belowReorder(): array
    {
        $rules = ReorderRule::query()->with('warehouse')->where('is_active', true)->get();
        $info = $this->items->findMany($rules->pluck('item_id')->all());
        $out = [];

        foreach ($rules as $rule) {
            $available = $this->reorder->available($rule->item_id, $rule->warehouse_id);
            if (Decimal::compare($available, $rule->min_qty) >= 0) {
                continue;
            }
            /** @var ItemInfo|null $item */
            $item = $info[$rule->item_id] ?? null;
            $out[] = [
                'rule_id' => $rule->id, 'item_id' => $rule->item_id, 'item_code' => $item?->code, 'item_name' => $item?->name, 'uom' => $item?->baseUomCode,
                'warehouse' => $rule->warehouse?->code, 'available' => $available, 'min_qty' => $rule->min_qty, 'reorder_qty' => $rule->reorder_qty,
                'shortfall' => Decimal::round(Decimal::sub($rule->min_qty, $available), 6),
            ];
        }

        usort($out, fn ($a, $b) => strcmp((string) $a['item_code'], (string) $b['item_code']));

        return $out;
    }
}
