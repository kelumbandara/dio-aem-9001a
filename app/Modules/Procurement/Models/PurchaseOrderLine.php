<?php

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $document_id
 * @property int $line_no
 * @property int $item_id
 * @property string|null $description
 * @property int $uom_id
 * @property string $qty
 * @property string $unit_price net of VAT, before discount
 * @property string $discount_pct
 * @property int|null $tax_code_id
 * @property string $net_amount
 * @property string $vat_amount input VAT on the line
 * @property string $qty_received
 * @property string $qty_returned
 * @property string $qty_billed
 */
#[Fillable(['document_id', 'line_no', 'item_id', 'description', 'uom_id', 'qty', 'unit_price', 'discount_pct', 'tax_code_id', 'net_amount', 'vat_amount', 'qty_received', 'qty_returned', 'qty_billed'])]
class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $table = 'purchase_order_lines';

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'unit_price' => 'decimal:4', 'discount_pct' => 'decimal:4', 'net_amount' => 'decimal:4', 'vat_amount' => 'decimal:4', 'qty_received' => 'decimal:6', 'qty_returned' => 'decimal:6', 'qty_billed' => 'decimal:6'];
    }
}
