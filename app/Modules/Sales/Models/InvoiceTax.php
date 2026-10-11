<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One tax code on a posted invoice: the base it was charged on, the rate then in force and the amount.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $tax_code_id
 * @property string $kind vat|sscl|nbt
 * @property string $rate
 * @property string $base_amount
 * @property string $amount
 */
#[Fillable(['invoice_id', 'tax_code_id', 'kind', 'rate', 'base_amount', 'amount'])]
class InvoiceTax extends Model
{
    public $timestamps = false;

    protected $table = 'sales_invoice_taxes';

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'base_amount' => 'decimal:4', 'amount' => 'decimal:4'];
    }
}
