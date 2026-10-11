<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\StockReserver;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

final class DatabaseStockReserver implements StockReserver
{
    public function __construct(
        private readonly ItemCatalog $items,
        private readonly DatabaseStockAvailability $availability,
        private readonly LocationResolver $locations,
    ) {}

    public function reserve(int $itemId, int $warehouseId, string $qty, string $sourceType, string $sourceId, ?int $by = null): int
    {
        return DB::transaction(function () use ($itemId, $warehouseId, $qty, $sourceType, $sourceId, $by): int {
            $item = $this->items->requireActive($itemId);
            if (! $item->isStocked()) {
                throw InventoryException::make('inventory.item_not_stocked', "{$item->code} is not a stock item.", 422, 'item');
            }
            $this->locations->warehouse($warehouseId);
            if (! Decimal::isDecimal($qty, $item->baseUomDecimals) || Decimal::compare($qty, '0') <= 0) {
                throw InventoryException::make('inventory.qty_invalid', "Enter a quantity above zero with at most {$item->baseUomDecimals} decimal place(s).", 422, 'qty');
            }

            // The same per-item lock the posting service takes, so a reservation and an issue cannot both claim the last units.
            DB::table('item_costs')->insertOrIgnore(['item_id' => $itemId, 'qty' => '0', 'value' => '0']);
            ItemCost::query()->whereKey($itemId)->lockForUpdate()->first();

            $available = $this->availability->level($itemId, $warehouseId)->available;
            if (Decimal::compare($available, $qty) < 0) {
                throw InventoryException::make('inventory.insufficient_available', "Only {$available} of {$item->code} is available to reserve.", 422, 'qty');
            }

            return StockReservation::query()->create([
                'item_id' => $itemId, 'warehouse_id' => $warehouseId, 'qty' => $qty, 'consumed_qty' => '0', 'status' => StockReservation::ACTIVE,
                'source_type' => $sourceType, 'source_id' => $sourceId, 'created_by' => $by,
            ])->id;
        });
    }

    public function release(int $reservationId): void
    {
        $r = StockReservation::query()->whereKey($reservationId)->lockForUpdate()->first();
        if ($r !== null && $r->status === StockReservation::ACTIVE) {
            $r->forceFill(['status' => StockReservation::RELEASED])->save();
        }
    }

    public function releaseBySource(string $sourceType, string $sourceId): int
    {
        return StockReservation::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('status', StockReservation::ACTIVE)
            ->update(['status' => StockReservation::RELEASED, 'updated_at' => now()]);
    }
}
