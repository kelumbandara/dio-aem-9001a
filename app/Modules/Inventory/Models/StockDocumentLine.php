<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property int $line_no
 * @property int $item_id
 * @property int $uom_id
 * @property string $uom_code
 * @property string $qty
 * @property string $base_qty
 * @property int|null $from_location_id
 * @property int|null $to_location_id
 * @property int|null $lot_id
 * @property string|null $unit_cost
 * @property string $value
 * @property string|null $note
 */
#[Fillable(['document_id', 'line_no', 'item_id', 'uom_id', 'uom_code', 'qty', 'base_qty', 'from_location_id', 'to_location_id', 'lot_id', 'unit_cost', 'value', 'note'])]
class StockDocumentLine extends Model
{
    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'base_qty' => 'decimal:6', 'unit_cost' => 'decimal:6', 'value' => 'decimal:4'];
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }
}
