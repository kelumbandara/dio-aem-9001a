<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $bill_id
 * @property int $line_no
 * @property int $order_line_id
 * @property int $item_id
 * @property string|null $description
 * @property int $uom_id
 * @property string $qty
 * @property string $unit_price
 * @property string $net_amount
 * @property string $grni_amount
 * @property string $variance_amount
 * @property int|null $tax_code_id
 * @property string $vat_rate
 * @property string $vat_amount
 * @property bool $stocked
 */
#[Fillable(['bill_id', 'line_no', 'order_line_id', 'item_id', 'description', 'uom_id', 'qty', 'unit_price', 'net_amount', 'grni_amount', 'variance_amount', 'tax_code_id', 'vat_rate', 'vat_amount', 'stocked'])]
class SupplierBillLine extends Model
{
    public $timestamps = false;

    protected $table = 'supplier_bill_lines';

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'unit_price' => 'decimal:6', 'net_amount' => 'decimal:4', 'grni_amount' => 'decimal:4', 'variance_amount' => 'decimal:4', 'vat_rate' => 'decimal:4', 'vat_amount' => 'decimal:4', 'stocked' => 'boolean'];
    }
}
