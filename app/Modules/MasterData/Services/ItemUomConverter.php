<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Contracts\UomConverter;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemUom;
use App\Modules\MasterData\Models\UnitOfMeasure;
use App\Support\Decimal;

final class ItemUomConverter implements UomConverter
{
    public function convert(string $qty, int $itemId, int $fromUomId, int $toUomId): string
    {
        $target = UnitOfMeasure::query()->findOrFail($toUomId);

        if ($fromUomId === $toUomId) {
            return Decimal::round($qty, $target->decimals);
        }

        $baseQty = Decimal::mul($qty, $this->factor($itemId, $fromUomId));

        return Decimal::round(Decimal::div($baseQty, $this->factor($itemId, $toUomId)), $target->decimals);
    }

    public function toBase(string $qty, int $itemId, int $uomId): string
    {
        $base = $this->baseUom($itemId);

        return Decimal::round(Decimal::mul($qty, $this->factor($itemId, $uomId)), $base->decimals);
    }

    public function factor(int $itemId, int $uomId): string
    {
        $item = Item::query()->findOrFail($itemId);

        if ($item->base_uom_id === $uomId) {
            return '1';
        }

        $factor = ItemUom::query()->where('item_id', $itemId)->where('uom_id', $uomId)->value('factor');

        return $factor ?? throw MasterDataException::make(
            'masterdata.uom_not_convertible',
            'This unit is not defined for the item, so it cannot be converted.',
        );
    }

    private function baseUom(int $itemId): UnitOfMeasure
    {
        return Item::query()->findOrFail($itemId)->baseUom;
    }
}
