<?php

namespace App\Services;

use App\Mail\LicenceMail;
use App\Models\Customer;
use App\Models\IssuedLicence;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing;
use App\Support\LicenceException;
use App\Support\LicenceToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Signs licences with the vendor's private key (environment only). A licence covers every period paid so far *without a
 * gap*: paying month 3 before month 2 does not extend the licence until month 2 is paid. The product always uses the
 * newest licence issued, so a licence is only ever issued when it extends what the last one covered.
 */
final class LicenceIssuer
{
    public function __construct(private readonly Settings $settings, private readonly Activity $activity) {}

    /** Last day of the unbroken run of paid (or waived) periods, from the first; null when the first period is unpaid. */
    public function paidThrough(Subscription $subscription): ?CarbonImmutable
    {
        $through = null;
        foreach ($subscription->invoices()->orderBy('period_start')->get() as $invoice) {
            if ($invoice->status === 'unpaid') {
                break;
            }
            $through = Billing::day($invoice->period_end->toDateString());
        }

        return $through;
    }

    /**
     * Issue the licence for everything paid so far, if it extends the last one. Returns null when there is nothing new to
     * issue, no installation ID yet, or the subscription is suspended.
     *
     * @throws LicenceException when the signing key is not configured
     */
    public function issueCoverage(Customer $customer, bool $email = true): ?IssuedLicence
    {
        $subscription = $customer->subscription;
        if ($subscription === null || $subscription->status !== 'active' || blank($customer->installation_id)) {
            return null;
        }
        $through = $this->paidThrough($subscription);
        if ($through === null) {
            return null;
        }

        $last = $customer->licences()->orderByDesc('id')->first();
        if ($last !== null && $last->kind === IssuedLicence::RENEWAL && $last->valid_until->toDateString() >= $through->toDateString()) {
            return null;
        }

        $previous = $customer->licences()->where('kind', IssuedLicence::RENEWAL)->orderByDesc('valid_until')->first();
        $from = $previous !== null && $previous->valid_until->toDateString() < $through->toDateString()
            ? Billing::day($previous->valid_until->toDateString())->addDay()
            : $subscription->periodStart(0);

        $licence = $this->sign($customer, $subscription, IssuedLicence::RENEWAL, $from, $through);
        if ($email) {
            $this->email($licence, $customer);
        }

        return $licence;
    }

    /** Shut an installation out now: a newer, already-expired licence replaces the paid one (the product locks at once). */
    public function suspend(Customer $customer, ?User $by = null): void
    {
        $subscription = $customer->subscription;
        DB::transaction(function () use ($customer, $subscription, $by): void {
            $subscription?->forceFill(['status' => 'suspended'])->save();
            $this->activity->log('subscription.suspended', $customer, [], $by?->id);
        });

        if ($subscription !== null && filled($customer->installation_id)) {
            $day = Billing::today()->subDays((int) config('billing.grace_days') + (int) config('billing.readonly_days') + 1);
            $this->sign($customer, $subscription, IssuedLicence::SUSPENSION, $day, $day);
        }
    }

    public function resume(Customer $customer, ?User $by = null): ?IssuedLicence
    {
        $customer->subscription?->forceFill(['status' => 'active'])->save();
        $this->activity->log('subscription.resumed', $customer, [], $by?->id);

        return $this->issueCoverage($customer->refresh());
    }

    /** Sign and keep a licence of any kind. */
    private function sign(Customer $customer, Subscription $subscription, string $kind, CarbonImmutable $from, CarbonImmutable $until): IssuedLicence
    {
        $key = (string) config('billing.licence_private_key');
        if ($key === '') {
            throw LicenceException::make('no_key', 'The licence signing key (LICENCE_PRIVATE_KEY) is not set in this environment.');
        }
        $now = CarbonImmutable::now();
        $maxUsers = $this->settings->get('max_users');
        $payload = [
            'v' => 1, 'serial' => 'L-'.$now->format('Ymd').'-'.Str::upper(Str::random(6)), 'installation' => $customer->installation_id,
            'company' => $customer->name, 'plan' => $subscription->plan, 'valid_from' => $from->toDateString(), 'valid_until' => $until->toDateString(),
            'issued_at' => $now->toIso8601String(),
            'max_users' => ctype_digit($maxUsers) && (int) $maxUsers > 0 ? (int) $maxUsers : null,
            'modules' => null,
        ];

        $licence = IssuedLicence::query()->create([
            'serial' => $payload['serial'], 'customer_id' => $customer->id, 'installation_id' => $payload['installation'], 'kind' => $kind,
            'valid_from' => $payload['valid_from'], 'valid_until' => $payload['valid_until'], 'token' => LicenceToken::sign($payload, $key), 'issued_at' => $now,
        ]);
        $this->activity->log('licence.issued', $licence, ['serial' => $licence->serial, 'kind' => $kind, 'valid_until' => $payload['valid_until']]);

        return $licence;
    }

    private function email(IssuedLicence $licence, Customer $customer): void
    {
        try {
            Mail::to($customer->email)->send(new LicenceMail($licence, $customer));
            $licence->forceFill(['emailed_at' => now()])->save();
        } catch (Throwable $e) {
            $this->activity->log('licence.email_failed', $licence, ['error' => $e->getMessage()]);
        }
    }
}
