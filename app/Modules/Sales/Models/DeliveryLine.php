<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $delivery_id
 * @property int $order_line_id
 * @property int $item_id
 * @property int $uom_id
 * @property string $qty
 */
#[Fillable(['delivery_id', 'order_line_id', 'item_id', 'uom_id', 'qty'])]
class DeliveryLine extends Model
{
    public $timestamps = false;

    protected $table = 'sales_delivery_lines';

    protected function casts(): array
    {
        return ['qty' => 'decimal:6'];
    }
}
