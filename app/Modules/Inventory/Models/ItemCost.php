<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Company-wide quantity and value of an item; average cost = value / qty. Locked first by every posting
 * that touches the item, which serialises concurrent movements of one item.
 *
 * @property int $item_id
 * @property string $qty
 * @property string $value
 */
#[Table(name: 'item_costs', key: 'item_id', incrementing: false, timestamps: false)]
#[Fillable(['item_id', 'qty', 'value'])]
class ItemCost extends Model
{
    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'value' => 'decimal:4'];
    }
}
