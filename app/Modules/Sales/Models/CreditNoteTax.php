<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $credit_note_id
 * @property int $tax_code_id
 * @property string $kind vat|sscl|nbt
 * @property string $rate
 * @property string $base_amount
 * @property string $amount
 */
#[Fillable(['credit_note_id', 'tax_code_id', 'kind', 'rate', 'base_amount', 'amount'])]
class CreditNoteTax extends Model
{
    public $timestamps = false;

    protected $table = 'sales_credit_note_taxes';

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'base_amount' => 'decimal:4', 'amount' => 'decimal:4'];
    }
}
