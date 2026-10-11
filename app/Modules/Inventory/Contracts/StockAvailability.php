<?php

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\DTOs\StockLevel;

/** Read access to stock levels and cost for other modules. Quantities are in the item's base unit. */
interface StockAvailability
{
    /** On hand, reserved and available for an item in one warehouse, or in all of them when $warehouseId is null. */
    public function level(int $itemId, ?int $warehouseId = null): StockLevel;

    /** Current weighted-average cost of one base unit in LKR (6 decimals); "0.000000" when the item has no stock. */
    public function averageCost(int $itemId): string;
}
