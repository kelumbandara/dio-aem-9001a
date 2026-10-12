<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $invoice_id
 * @property string $method
 * @property string $amount
 * @property Carbon $received_on
 * @property string|null $reference
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 */
class Payment extends Model
{
    public const BANK = 'bank_transfer';

    public const PAYHERE = 'payhere';

    protected $guarded = [];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['received_on' => 'date', 'details' => 'array', 'reversed_at' => 'datetime'];
    }
}
