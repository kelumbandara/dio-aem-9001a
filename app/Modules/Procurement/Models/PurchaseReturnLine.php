<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $return_id
 * @property int $order_line_id
 * @property int|null $bill_line_id
 * @property int $item_id
 * @property int $uom_id
 * @property string $qty
 * @property bool $goods_returned
 * @property string $grni_value
 * @property string $net_amount
 * @property string $grni_amount
 * @property string $variance_amount
 * @property string $vat_amount
 */
#[Fillable(['return_id', 'order_line_id', 'bill_line_id', 'item_id', 'uom_id', 'qty', 'goods_returned', 'grni_value', 'net_amount', 'grni_amount', 'variance_amount', 'vat_amount'])]
class PurchaseReturnLine extends Model
{
    public $timestamps = false;

    protected $table = 'purchase_return_lines';

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'goods_returned' => 'boolean', 'grni_value' => 'decimal:4', 'net_amount' => 'decimal:4', 'grni_amount' => 'decimal:4', 'variance_amount' => 'decimal:4', 'vat_amount' => 'decimal:4'];
    }
}
