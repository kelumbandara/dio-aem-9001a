<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemUom;
use App\Modules\MasterData\Models\PriceList;

/**
 * Checks and normalises a set of price changes. Used before a request is made AND again right before it is
 * executed, because items, units and the list may have changed while it waited for approval.
 */
final class PriceChangeValidator
{
    public const MAX_CHANGES = 1000;

    /**
     * @param  list<array<string, mixed>>  $changes  each {item_id, uom_id?, min_qty?, unit_price} – unit_price null removes the row
     * @return list<array{item_id: int, uom_id: int, min_qty: string, unit_price: string|null}>
     */
    public function validate(PriceList $list, array $changes): array
    {
        if (! $list->is_active) {
            throw MasterDataException::make('masterdata.price_list_inactive', 'This price list is inactive.', 422);
        }
        if ($changes === []) {
            throw MasterDataException::make('masterdata.no_changes', 'There is nothing to change.', 422, 'changes');
        }
        if (count($changes) > self::MAX_CHANGES) {
            throw MasterDataException::make('masterdata.too_many_changes', 'At most '.self::MAX_CHANGES.' price changes per request.', 422, 'changes');
        }

        $items = Item::query()->whereIn('id', array_unique(array_map(fn ($c) => (int) ($c['item_id'] ?? 0), $changes)))->get()->keyBy('id');
        $units = ItemUom::query()->whereIn('item_id', $items->keys())->get()->groupBy('item_id');

        $seen = [];
        $clean = [];

        foreach ($changes as $i => $change) {
            $itemId = (int) ($change['item_id'] ?? 0);
            $item = $items->get($itemId) ?? throw MasterDataException::make('masterdata.item_not_found', 'Unknown item.', 422, "changes.{$i}.item_id");

            $wantsPurchase = $list->type === PriceList::PURCHASE;
            if (! $item->is_active || ($wantsPurchase ? ! $item->is_purchasable : ! $item->is_sellable)) {
                throw MasterDataException::make('masterdata.item_not_usable', "{$item->code} cannot be on a {$list->type} price list (inactive, or not {$list->type}able).", 422, "changes.{$i}.item_id");
            }

            $uomId = (int) ($change['uom_id'] ?? $item->base_uom_id);
            $knownUom = $uomId === $item->base_uom_id || ($units->get($itemId)?->contains('uom_id', $uomId) ?? false);
            if (! $knownUom) {
                throw MasterDataException::make('masterdata.uom_not_convertible', "{$item->code} is not defined in that unit.", 422, "changes.{$i}.uom_id");
            }

            $minQty = (string) ($change['min_qty'] ?? '0');
            if (preg_match('/^\d{1,12}(\.\d{1,6})?$/', $minQty) !== 1) {
                throw MasterDataException::make('masterdata.qty_invalid', 'Minimum quantity must be zero or positive.', 422, "changes.{$i}.min_qty");
            }

            $price = $change['unit_price'] ?? null;
            if ($price !== null && (preg_match('/^\d{1,12}(\.\d{1,6})?$/', (string) $price) !== 1)) {
                throw MasterDataException::make('masterdata.price_invalid', 'A price must be zero or positive with up to 6 decimals.', 422, "changes.{$i}.unit_price");
            }

            $key = "{$itemId}|{$uomId}|".bcadd($minQty, '0', 6);
            if (isset($seen[$key])) {
                throw MasterDataException::make('masterdata.duplicate_change', 'The same item, unit and minimum quantity appears twice.', 422, "changes.{$i}.item_id");
            }
            $seen[$key] = true;

            $clean[] = ['item_id' => $itemId, 'uom_id' => $uomId, 'min_qty' => bcadd($minQty, '0', 6), 'unit_price' => $price !== null ? bcadd((string) $price, '0', 6) : null];
        }

        return $clean;
    }
}
