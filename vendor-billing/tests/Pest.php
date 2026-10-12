<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\InvoiceService;
use App\Services\Settings;
use App\Support\Billing;
use App\Support\LicenceToken;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** A fresh signing key pair for the test; the secret goes where production keeps it: the environment (config). */
function useKeys(): string
{
    [$public, $secret] = LicenceToken::generateKeys();
    config(['billing.licence_private_key' => $secret]);

    return $public;
}

function seedSettings(array $over = []): void
{
    app(Settings::class)->save(array_merge([
        'plan_name' => 'Standard', 'monthly_price' => '10000.00', 'vat_rate' => '18.00', 'trial_days' => '30', 'invoice_lead_days' => '7',
        'issuer_name' => 'Acme Hosting (Pvt) Ltd', 'issuer_address' => "1 Galle Road\nColombo", 'issuer_vat_number' => '123456789-7000',
        'bank_details' => "Bank X\nAcc 1234", 'issuer_email' => 'billing@acme.test',
    ], $over));
}

/** @param  array<string, mixed>  $over */
function newCustomer(string $startedOn = '2026-11-01', array $over = []): Customer
{
    seedSettings();

    return app(CustomerService::class)->create(array_merge(
        ['name' => 'Customer Ltd', 'email' => 'accounts@customer.test', 'installation_id' => 'inst_abc123'],
        $over,
    ), Billing::day($startedOn));
}

function firstInvoice(Customer $customer, ?string $today = null): Invoice
{
    return app(InvoiceService::class)->issueForPeriod($customer->subscription, 0, $today ? Billing::day($today) : null);
}

function owner(): User
{
    return User::query()->create(['name' => 'Owner', 'email' => 'owner@vendor.test', 'password' => 'a-long-password-1', 'role' => User::OWNER]);
}

/** Sign a user in, past the authenticator step, the way the app does. */
function signIn(User $user): void
{
    test()->actingAs($user);
}

function day(string $date): CarbonImmutable
{
    return Billing::day($date);
}

/** Run a statement the database is expected to refuse without poisoning the test's own transaction. */
function inSavepoint(Closure $statement): mixed
{
    return DB::transaction($statement);
}
