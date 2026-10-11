<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;

/** A minimum stock level for an item in one warehouse (or company-wide). Quantities are in the item's base unit. */
final class SaveReorderRule
{
    public function __construct(private readonly ItemCatalog $items) {}

    /**
     * @param  array<string, mixed>  $data  item_id (create only), warehouse_id (create only), min_qty, reorder_qty, is_active
     */
    public function __invoke(array $data, ?ReorderRule $rule = null): ReorderRule
    {
        $creating = $rule === null;

        if ($creating) {
            $item = $this->items->requireActive((int) ($data['item_id'] ?? 0));
            if (! $item->isStocked()) {
                throw InventoryException::make('inventory.item_not_stocked', "{$item->code} is not a stock item.", 422, 'item');
            }
            $warehouseId = $data['warehouse_id'] ?? null;
            if ($warehouseId !== null && ! Warehouse::query()->whereKey($warehouseId)->exists()) {
                throw InventoryException::make('inventory.warehouse_not_found', 'Warehouse not found.', 422, 'warehouse');
            }
            if (ReorderRule::query()->where('item_id', $item->id)->where('warehouse_id', $warehouseId)->exists()) {
                throw InventoryException::make('inventory.rule_exists', 'This item already has a reorder rule for that place.', 422, 'item');
            }
        } else {
            $item = $this->items->find($rule->item_id);
        }

        $decimals = $item->baseUomDecimals ?? 6;
        $min = (string) ($data['min_qty'] ?? $rule->min_qty ?? '');
        if (! Decimal::isDecimal($min, $decimals) || Decimal::compare($min, '0') < 0) {
            throw InventoryException::make('inventory.qty_invalid', "The minimum must be zero or more, with at most {$decimals} decimal place(s).", 422, 'min_qty');
        }
        $order = array_key_exists('reorder_qty', $data) ? $data['reorder_qty'] : $rule->reorder_qty ?? null;
        $order = ($order === null || $order === '') ? null : (string) $order;
        if ($order !== null && (! Decimal::isDecimal($order, $decimals) || Decimal::compare($order, '0') <= 0)) {
            throw InventoryException::make('inventory.qty_invalid', 'The order quantity must be above zero.', 422, 'reorder_qty');
        }

        $rule ??= new ReorderRule(['item_id' => $item->id, 'warehouse_id' => $warehouseId]);
        $rule->fill(['min_qty' => $min, 'reorder_qty' => $order, 'is_active' => (bool) ($data['is_active'] ?? $rule->is_active ?? true)])->save();

        return $rule;
    }
}
