<?php

namespace App\Modules\MasterData\Http\Resources;

use App\Modules\MasterData\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Item */
class ItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => ['code' => $this->category->code, 'name' => $this->category->name],
            'item_type' => $this->item_type,
            'base_uom' => ['code' => $this->baseUom->code, 'name' => $this->baseUom->name, 'decimals' => $this->baseUom->decimals],
            'is_sellable' => $this->is_sellable,
            'is_purchasable' => $this->is_purchasable,
            'tracking' => $this->tracking,
            'shelf_life_days' => $this->shelf_life_days,
            'barcode' => $this->barcode,
            'default_tax_code' => $this->defaultTaxCode?->code,
            'is_active' => $this->is_active,
            'version' => $this->version,
            'uoms' => $this->uoms->map(fn ($u) => [
                'uom' => $u->uom->code, 'factor' => $u->factor, 'for_purchase' => $u->for_purchase, 'for_sale' => $u->for_sale,
            ])->values(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
