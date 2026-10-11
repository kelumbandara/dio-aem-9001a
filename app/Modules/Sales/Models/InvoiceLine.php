<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $invoice_id
 * @property int $line_no
 * @property int $order_line_id
 * @property int $item_id
 * @property string|null $description
 * @property int $uom_id
 * @property string $qty
 * @property string $unit_price
 * @property string $discount_pct
 * @property string $net_amount
 * @property int|null $tax_code_id
 * @property string $vat_rate
 * @property string $vat_amount
 * @property bool $levy_liable
 */
#[Fillable(['invoice_id', 'line_no', 'order_line_id', 'item_id', 'description', 'uom_id', 'qty', 'unit_price', 'discount_pct', 'net_amount', 'tax_code_id', 'vat_rate', 'vat_amount', 'levy_liable'])]
class InvoiceLine extends Model
{
    public $timestamps = false;

    protected $table = 'sales_invoice_lines';

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'unit_price' => 'decimal:4', 'discount_pct' => 'decimal:4', 'net_amount' => 'decimal:4', 'vat_rate' => 'decimal:4', 'vat_amount' => 'decimal:4', 'levy_liable' => 'boolean'];
    }
}
