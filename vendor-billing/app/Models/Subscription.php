<?php

namespace App\Models;

use App\Support\Billing;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $plan
 * @property string $monthly_price
 * @property Carbon $started_on
 * @property Carbon $trial_ends_on
 * @property Carbon $billing_anchor
 * @property string $status
 */
class Subscription extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * First day of billing period n (0 = the first paid month, the day the trial ends). Always counted from the anchor,
     * not from the previous period, so a subscription anchored on the 31st bills 31 Jan, 28 Feb, 31 Mar (no drift).
     */
    public function periodStart(int $n): CarbonImmutable
    {
        return Billing::day($this->billing_anchor->toDateString())->addMonthsNoOverflow($n);
    }

    /** Last day of period n: the day before the next period starts, so periods are continuous. */
    public function periodEnd(int $n): CarbonImmutable
    {
        return $this->periodStart($n + 1)->subDay();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['started_on' => 'date', 'trial_ends_on' => 'date', 'billing_anchor' => 'date'];
    }
}
