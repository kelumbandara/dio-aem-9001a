<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $receipt_id
 * @property int $order_line_id
 * @property int $item_id
 * @property int $uom_id
 * @property string $qty
 * @property string $unit_cost
 * @property string $value
 * @property string|null $lot_no
 * @property string|null $expiry_date
 */
#[Fillable(['receipt_id', 'order_line_id', 'item_id', 'uom_id', 'qty', 'unit_cost', 'value', 'lot_no', 'expiry_date'])]
class GoodsReceiptLine extends Model
{
    public $timestamps = false;

    protected $table = 'goods_receipt_lines';

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'unit_cost' => 'decimal:6', 'value' => 'decimal:4'];
    }
}
