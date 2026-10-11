<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price for an item in a unit from a minimum quantity (quantity breaks). Changes go through
 * ApplyPriceChanges, which writes one audit entry per change set.
 *
 * @property int $id
 * @property int $price_list_id
 * @property int $item_id
 * @property int $uom_id
 * @property string $min_qty decimal string
 * @property string $unit_price decimal string, 6 places
 */
#[Fillable(['price_list_id', 'item_id', 'uom_id', 'min_qty', 'unit_price'])]
class PriceListItem extends Model
{
    protected function casts(): array
    {
        return ['min_qty' => 'decimal:6', 'unit_price' => 'decimal:6'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<UnitOfMeasure, $this> */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
