<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current quantity of an item in a location (and lot). Only the posting service changes it.
 *
 * @property int $id
 * @property int $item_id
 * @property int $location_id
 * @property int|null $lot_id
 * @property string $on_hand base units
 */
#[Table(name: 'stock_balances', timestamps: false)]
#[Fillable(['item_id', 'location_id', 'lot_id', 'on_hand'])]
class StockBalance extends Model
{
    protected function casts(): array
    {
        return ['on_hand' => 'decimal:6'];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
