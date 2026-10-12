<?php

namespace App\Models;

use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $number
 * @property int $customer_id
 * @property int $subscription_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property string $net
 * @property string $vat_rate
 * @property string $vat
 * @property string $total
 * @property string $status
 * @property Carbon|null $paid_at
 * @property string|null $void_reason
 * @property string $public_token
 * @property array<string, mixed> $billed_to
 * @property array<string, mixed> $issuer
 * @property list<int> $reminders_sent
 */
class Invoice extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Money received for this invoice (reversed payments do not count). */
    public function paidAmount(): string
    {
        $sum = '0';
        foreach ($this->payments()->whereNull('reversed_at')->pluck('amount') as $amount) {
            $sum = Decimal::add($sum, (string) $amount);
        }

        return Decimal::round($sum, 2);
    }

    /** What is still to pay; never below zero. */
    public function balance(): string
    {
        $left = Decimal::sub((string) $this->total, $this->paidAmount());

        return Decimal::compare($left, '0') > 0 ? Decimal::round($left, 2) : '0.00';
    }

    /** The reference sent to PayHere: the invoice number with dashes (slashes are not safe in a payment reference). */
    public function payhereOrderId(): string
    {
        return str_replace('/', '-', $this->number);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'issue_date' => 'date', 'due_date' => 'date', 'paid_at' => 'datetime',
            'billed_to' => 'array', 'issuer' => 'array', 'reminders_sent' => 'array',
        ];
    }
}
