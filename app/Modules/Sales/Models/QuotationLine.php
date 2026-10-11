<?php

namespace App\Modules\Sales\Models;

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
 * @property string $unit_price net of tax, per unit
 * @property string $discount_pct
 * @property int|null $tax_code_id
 * @property string $net_amount
 */
#[Fillable(['document_id', 'line_no', 'item_id', 'description', 'uom_id', 'qty', 'unit_price', 'discount_pct', 'tax_code_id', 'net_amount'])]
class QuotationLine extends Model
{
    public $timestamps = false;

    protected $table = 'sales_quotation_lines';

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'unit_price' => 'decimal:4', 'discount_pct' => 'decimal:4', 'net_amount' => 'decimal:4'];
    }
}
