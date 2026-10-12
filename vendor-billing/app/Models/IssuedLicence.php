<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $serial
 * @property int $customer_id
 * @property string $installation_id
 * @property string $kind
 * @property Carbon $valid_from
 * @property Carbon $valid_until
 * @property string $token
 * @property Carbon $issued_at
 * @property Carbon|null $emailed_at
 */
class IssuedLicence extends Model
{
    public const RENEWAL = 'renewal';

    public const SUSPENSION = 'suspension';

    protected $guarded = [];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'issued_at' => 'datetime', 'emailed_at' => 'datetime'];
    }
}
