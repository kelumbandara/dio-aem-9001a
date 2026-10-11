<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Modules\MasterData\DTOs\ItemUnit;
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

    public function findMany(array $itemIds): array
    {
        $out = [];
        foreach (Item::query()->with('baseUom')->whereIn('id', array_unique($itemIds))->get() as $item) {
            $out[$item->id] = $this->info($item);
        }

        return $out;
    }

    public function search(string $term, int $limit = 200): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($term))).'%';
        $out = [];
        $query = Item::query()->with('baseUom')->orderBy('code')->limit($limit);
        $query->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like])->orWhereRaw("lower(coalesce(barcode, '')) like ?", [$like]));
        foreach ($query->get() as $item) {
            $out[$item->id] = $this->info($item);
        }

        return $out;
    }

    public function units(int $itemId): array
    {
        $item = Item::query()->with(['baseUom', 'uoms.uom'])->find($itemId);
        if ($item === null) {
            return [];
        }

        $units = [new ItemUnit($item->base_uom_id, $item->baseUom->code, '1', $item->baseUom->decimals, true, true, true)];
        foreach ($item->uoms as $u) {
            $units[] = new ItemUnit($u->uom_id, $u->uom->code, $u->factor, $u->uom->decimals, false, $u->for_purchase, $u->for_sale);
        }

        return $units;
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
