<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $category_id
 * @property string $item_type stock|service|non_stock
 * @property int $base_uom_id
 * @property bool $is_sellable
 * @property bool $is_purchasable
 * @property string $tracking none|lot
 * @property int|null $shelf_life_days
 * @property string|null $barcode
 * @property int|null $default_tax_code_id
 * @property bool $is_active
 * @property int $version
 */
#[RouteKey('public_id')]
#[Fillable([
    'code', 'name', 'description', 'category_id', 'item_type', 'base_uom_id', 'is_sellable', 'is_purchasable',
    'tracking', 'shelf_life_days', 'barcode', 'default_tax_code_id', 'is_active', 'version',
])]
class Item extends Model
{
    use Auditable, HasPublicId;

    public const TYPES = ['stock', 'service', 'non_stock'];

    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean', 'is_purchasable' => 'boolean', 'is_active' => 'boolean',
            'shelf_life_days' => 'integer', 'version' => 'integer',
        ];
    }

    /** @return BelongsTo<ItemCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /** @return BelongsTo<UnitOfMeasure, $this> */
    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    /** @return BelongsTo<TaxCode, $this> */
    public function defaultTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'default_tax_code_id');
    }

    /** @return HasMany<ItemUom, $this> */
    public function uoms(): HasMany
    {
        return $this->hasMany(ItemUom::class)->orderBy('id');
    }
}
