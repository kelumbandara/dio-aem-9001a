<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Contracts\PriceResolver;
use App\Modules\MasterData\DTOs\PriceQuote;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemUom;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\PriceListItem;
use App\Modules\MasterData\Models\Supplier;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class DatabasePriceResolver implements PriceResolver
{
    public function quote(
        int $itemId,
        string $qty,
        ?int $uomId = null,
        string $type = 'sales',
        ?int $priceListId = null,
        ?int $partyId = null,
        ?CarbonInterface $on = null,
    ): ?PriceQuote {
        $item = Item::query()->find($itemId);
        if ($item === null) {
            return null;
        }
        $uomId ??= $item->base_uom_id;
        $date = CarbonImmutable::instance($on ?? now())->setTimezone(config('core.display_timezone'))->toDateString();

        $list = $this->priceList($type, $priceListId, $partyId, $date);
        if ($list === null) {
            return null;
        }

        $row = $this->rowFor($list->id, $itemId, $uomId, $qty);
        if ($row !== null) {
            return $this->quote_($list, $row->unit_price, $uomId, $row->min_qty);
        }

        // No price in the requested unit: derive it from the base-unit price.
        if ($uomId !== $item->base_uom_id) {
            $factor = ItemUom::query()->where('item_id', $itemId)->where('uom_id', $uomId)->value('factor');
            $baseQty = $factor !== null ? Decimal::mul($qty, $factor) : null;
            $baseRow = $baseQty !== null ? $this->rowFor($list->id, $itemId, $item->base_uom_id, $baseQty) : null;

            if ($baseRow !== null) {
                return $this->quote_($list, Decimal::round(Decimal::mul($baseRow->unit_price, $factor), 6), $uomId, '0');
            }
        }

        return null;
    }

    private function priceList(string $type, ?int $explicitId, ?int $partyId, string $date): ?PriceList
    {
        if ($explicitId !== null) {
            $list = PriceList::query()->find($explicitId);

            return $list !== null && $list->type === $type && $this->usable($list, $date) ? $list : null;
        }

        if ($partyId !== null) {
            $own = $type === PriceList::SALES
                ? Customer::query()->whereKey($partyId)->value('price_list_id')
                : Supplier::query()->whereKey($partyId)->value('price_list_id');
            $list = $own !== null ? PriceList::query()->find($own) : null;
            if ($list !== null && $list->type === $type && $this->usable($list, $date)) {
                return $list;
            }
        }

        $default = PriceList::query()->where('type', $type)->where('is_default', true)->first();

        return $default !== null && $this->usable($default, $date) ? $default : null;
    }

    private function usable(PriceList $list, string $date): bool
    {
        return $list->is_active
            && ($list->valid_from === null || $list->valid_from->toDateString() <= $date)
            && ($list->valid_to === null || $list->valid_to->toDateString() >= $date);
    }

    private function rowFor(int $listId, int $itemId, int $uomId, string $qty): ?PriceListItem
    {
        return PriceListItem::query()
            ->where('price_list_id', $listId)->where('item_id', $itemId)->where('uom_id', $uomId)
            ->where('min_qty', '<=', Decimal::round($qty, 6))
            ->orderByDesc('min_qty')
            ->first();
    }

    private function quote_(PriceList $list, string $unitPrice, int $uomId, string $minQty): PriceQuote
    {
        return new PriceQuote($unitPrice, $uomId, $list->id, $list->code, $list->currency->code, $list->tax_inclusive, $minQty);
    }
}
