<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Item;

final class DatabaseItemCatalog implements ItemCatalog
{
    public function find(int $itemId): ?ItemInfo
    {
        $item = Item::query()->with('baseUom')->find($itemId);

        return $item === null ? null : $this->info($item);
    }

    public function findByCode(string $code): ?ItemInfo
    {
        $item = Item::query()->with('baseUom')->where('code', $code)->first();

        return $item === null ? null : $this->info($item);
    }

    public function requireActive(int $itemId): ItemInfo
    {
        $info = $this->find($itemId) ?? throw MasterDataException::notFound('item');

        return $info->isActive ? $info : throw MasterDataException::inactive('item');
    }

    private function info(Item $item): ItemInfo
    {
        return new ItemInfo(
            id: $item->id,
            publicId: $item->public_id,
            code: $item->code,
            name: $item->name,
            itemType: $item->item_type,
            baseUomId: $item->base_uom_id,
            baseUomCode: $item->baseUom->code,
            baseUomDecimals: $item->baseUom->decimals,
            isSellable: $item->is_sellable,
            isPurchasable: $item->is_purchasable,
            tracksLots: $item->tracking === 'lot',
            shelfLifeDays: $item->shelf_life_days,
            defaultTaxCodeId: $item->default_tax_code_id,
            isActive: $item->is_active,
        );
    }
}
