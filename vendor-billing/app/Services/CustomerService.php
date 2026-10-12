<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Support\Billing;
use App\Support\Decimal;
use App\Support\LicenceException;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CustomerService
{
    public function __construct(private readonly Settings $settings, private readonly Activity $activity, private readonly LicenceIssuer $licences) {}

    /**
     * New customer with a subscription: free trial from `started_on` for the configured days, billing from the day the
     * trial ends. The plan price is copied now, so later price changes apply to new customers only.
     *
     * @param  array{name: string, email: string, contact_name?: ?string, phone?: ?string, address?: ?string, vat_number?: ?string, installation_id?: ?string}  $data
     */
    public function create(array $data, ?CarbonImmutable $startedOn = null, ?User $by = null): Customer
    {
        $price = $this->settings->get('monthly_price');
        if ($price === '' || ! Decimal::isDecimal($price, 2) || Decimal::compare($price, '0') <= 0) {
            throw new DomainException('Set the plan price on the Settings screen first.');
        }
        $startedOn ??= Billing::today();
        $trial = max(0, (int) $this->settings->get('trial_days'));

        return DB::transaction(function () use ($data, $startedOn, $trial, $price, $by): Customer {
            $customer = Customer::query()->create(array_merge($data, ['code' => 'tmp-'.Str::random(12)]));
            $customer->forceFill(['code' => 'C'.str_pad((string) $customer->id, 4, '0', STR_PAD_LEFT)])->save();
            $endsOn = $startedOn->addDays($trial);
            $customer->subscription()->create([
                'plan' => $this->settings->get('plan_name'), 'monthly_price' => Decimal::round($price, 2),
                'started_on' => $startedOn->toDateString(), 'trial_ends_on' => $endsOn->toDateString(), 'billing_anchor' => $endsOn->toDateString(), 'status' => 'active',
            ]);
            $this->activity->log('customer.created', $customer, ['code' => $customer->code], $by?->id);

            return $customer;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Customer $customer, array $data, ?User $by = null): Customer
    {
        $customer->fill($data)->save();
        $this->activity->log('customer.updated', $customer, ['changed' => array_keys($data)], $by?->id);

        // A customer whose installation ID was missing when they paid gets their licence as soon as it is known.
        if (filled($customer->installation_id)) {
            try {
                $this->licences->issueCoverage($customer->refresh());
            } catch (LicenceException $e) {
                $this->activity->log('licence.failed', $customer, ['error' => $e->getMessage()], $by?->id);
            }
        }

        return $customer;
    }

    /** A new API token for the installation to fetch its licence with. Shown once; only its hash is kept. */
    public function rotateApiToken(Customer $customer, ?User $by = null): string
    {
        $token = 'bt_'.Str::random(48);
        $customer->forceFill(['api_token_hash' => hash('sha256', $token)])->save();
        $this->activity->log('customer.token_rotated', $customer, [], $by?->id);

        return $token;
    }
}
