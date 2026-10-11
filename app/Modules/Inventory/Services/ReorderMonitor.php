<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Inventory\Models\ReorderRule;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Compares available stock with the reorder rules, and announces the moment an item drops below its minimum. */
final class ReorderMonitor
{
    public function __construct(private readonly EventOutbox $outbox) {}

    /**
     * Available quantity (on hand minus reserved) for every active rule of the given items.
     *
     * @param  list<int>  $itemIds
     * @return array<int, string> rule id => available
     */
    public function snapshot(array $itemIds): array
    {
        $out = [];
        foreach (ReorderRule::query()->where('is_active', true)->whereIn('item_id', $itemIds)->get() as $rule) {
            $out[$rule->id] = $this->available($rule->item_id, $rule->warehouse_id);
        }

        return $out;
    }

    /**
     * Emit `inventory.stock.below_reorder` for each rule that went from at-or-above its minimum to below it.
     *
     * @param  array<int, string>  $before  result of snapshot() taken before the movement
     * @param  list<int>  $itemIds
     */
    public function announceCrossings(array $before, array $itemIds): void
    {
        foreach (ReorderRule::query()->where('is_active', true)->whereIn('item_id', $itemIds)->get() as $rule) {
            if (! isset($before[$rule->id])) {
                continue;
            }
            $now = $this->available($rule->item_id, $rule->warehouse_id);
            if (Decimal::compare($before[$rule->id], $rule->min_qty) >= 0 && Decimal::compare($now, $rule->min_qty) < 0) {
                $this->outbox->record('inventory.stock.below_reorder', [
                    'item_id' => $rule->item_id, 'warehouse_id' => $rule->warehouse_id, 'available' => $now,
                    'min_qty' => $rule->min_qty, 'reorder_qty' => $rule->reorder_qty,
                ], 'item', $rule->item_id);
            }
        }
    }

    public function available(int $itemId, ?int $warehouseId): string
    {
        $onHand = DB::table('stock_balances as b')->join('locations as l', 'l.id', '=', 'b.location_id')
            ->where('b.item_id', $itemId)->when($warehouseId, fn ($q) => $q->where('l.warehouse_id', $warehouseId))->sum('b.on_hand');
        $reserved = DB::table('stock_reservations')->where('item_id', $itemId)->where('status', 'active')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->selectRaw('coalesce(sum(qty - consumed_qty), 0) as r')->value('r');

        return Decimal::round(Decimal::sub((string) $onHand, (string) $reserved), 6);
    }
}
