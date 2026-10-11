<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\DTOs\StockLevel;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

final class DatabaseStockAvailability implements StockAvailability
{
    public function level(int $itemId, ?int $warehouseId = null): StockLevel
    {
        $onHand = (string) DB::table('stock_balances as b')->join('locations as l', 'l.id', '=', 'b.location_id')
            ->where('b.item_id', $itemId)->when($warehouseId !== null, fn ($q) => $q->where('l.warehouse_id', $warehouseId))->sum('b.on_hand');
        $reserved = (string) DB::table('stock_reservations')->where('item_id', $itemId)->where('status', 'active')
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('coalesce(sum(qty - consumed_qty), 0) as r')->value('r');

        $onHand = Decimal::round($onHand, 6);
        $reserved = Decimal::round($reserved, 6);
        $available = Decimal::sub($onHand, $reserved);

        return new StockLevel($itemId, $warehouseId, $onHand, $reserved, Decimal::compare($available, '0') < 0 ? '0.000000' : Decimal::round($available, 6));
    }

    public function averageCost(int $itemId): string
    {
        $row = DB::table('item_costs')->where('item_id', $itemId)->first(['qty', 'value']);
        if ($row === null || Decimal::compare((string) $row->qty, '0') <= 0) {
            return '0.000000';
        }

        return Decimal::round(Decimal::div((string) $row->value, (string) $row->qty), 6);
    }
}
