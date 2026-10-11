<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit an item is also bought/sold in. `factor` = base units contained in one of this unit.
 *
 * @property int $id
 * @property int $item_id
 * @property int $uom_id
 * @property string $factor decimal string, 6 places
 * @property bool $for_purchase
 * @property bool $for_sale
 */
#[Table(name: 'item_uoms')]
#[Fillable(['item_id', 'uom_id', 'factor', 'for_purchase', 'for_sale'])]
class ItemUom extends Model
{
    // Changes are audited through the owning Item's explicit audit entry (SaveItem).

    protected function casts(): array
    {
        return ['factor' => 'decimal:6', 'for_purchase' => 'boolean', 'for_sale' => 'boolean'];
    }

    /** @return BelongsTo<UnitOfMeasure, $this> */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
