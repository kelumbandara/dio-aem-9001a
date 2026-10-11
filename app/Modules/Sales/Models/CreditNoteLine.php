<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $credit_note_id
 * @property int $invoice_line_id
 * @property int $item_id
 * @property int $uom_id
 * @property string $qty
 * @property string $net_amount
 * @property string $vat_amount
 */
#[Fillable(['credit_note_id', 'invoice_line_id', 'item_id', 'uom_id', 'qty', 'net_amount', 'vat_amount'])]
class CreditNoteLine extends Model
{
    public $timestamps = false;

    protected $table = 'sales_credit_note_lines';

    protected function casts(): array
    {
        return ['qty' => 'decimal:6', 'net_amount' => 'decimal:4', 'vat_amount' => 'decimal:4'];
    }
}
