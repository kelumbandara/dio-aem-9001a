<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\CustomerService;
use App\Services\InvoiceService;
use App\Services\Settings;
use App\Support\Billing;
use Illuminate\Support\Facades\DB;

it('computes VAT on the plan price and rounds to the cent', function () {
    seedSettings(['monthly_price' => '9999.99', 'vat_rate' => '18.00']);
    $customer = app(CustomerService::class)->create(['name' => 'A', 'email' => 'a@x.test'], day('2026-11-01'));
    $invoice = app(InvoiceService::class)->issueForPeriod($customer->subscription, 0, day('2026-11-20'));

    // 9999.99 x 18% = 1799.9982 -> 1800.00
    expect((string) $invoice->net)->toBe('9999.99')->and((string) $invoice->vat)->toBe('1800.00')->and((string) $invoice->total)->toBe('11799.99')->and((string) $invoice->vat_rate)->toBe('18.00');
});

it('adds nothing when the VAT rate is zero', function () {
    seedSettings(['vat_rate' => '0']);
    $customer = app(CustomerService::class)->create(['name' => 'A', 'email' => 'a@x.test'], day('2026-11-01'));
    $invoice = app(InvoiceService::class)->issueForPeriod($customer->subscription, 0);
    expect((string) $invoice->vat)->toBe('0.00')->and((string) $invoice->total)->toBe((string) $invoice->net);
});

it('keeps total = net + VAT for random prices and rates', function () {
    mt_srand(11);
    for ($i = 0; $i < 60; $i++) {
        $price = mt_rand(1, 9999999).'.'.str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
        $rate = mt_rand(0, 25).'.'.str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
        seedSettings(['monthly_price' => $price, 'vat_rate' => $rate]);
        $customer = app(CustomerService::class)->create(['name' => "C{$i}", 'email' => "c{$i}@x.test"], day('2026-11-01'));
        $invoice = app(InvoiceService::class)->issueForPeriod($customer->subscription, 0);

        // half-up to the cent, worked out independently of the code under test
        $exact = bcdiv(bcmul($price, $rate, 8), '100', 8);
        $half = bcadd($exact, '0.005', 8);
        expect((string) $invoice->vat)->toBe(substr($half, 0, strpos($half, '.') + 3))
            ->and(bcadd((string) $invoice->net, (string) $invoice->vat, 2))->toBe((string) $invoice->total);
    }
});

it('numbers invoices SUB/<financial year>/<5 digits>, gapless, restarting each 1 April', function () {
    $a = newCustomer();
    seedSettings();
    $b = app(CustomerService::class)->create(['name' => 'B', 'email' => 'b@x.test'], day('2026-11-01'));
    $c = app(CustomerService::class)->create(['name' => 'C', 'email' => 'c@x.test'], day('2026-11-01'));
    $svc = app(InvoiceService::class);

    expect($svc->issueForPeriod($a->subscription, 0, day('2027-03-31'))->number)->toBe('SUB/2026-27/00001')
        ->and($svc->issueForPeriod($b->subscription, 0, day('2027-03-31'))->number)->toBe('SUB/2026-27/00002')
        ->and($svc->issueForPeriod($c->subscription, 0, day('2027-04-01'))->number)->toBe('SUB/2027-28/00001')
        ->and($svc->issueForPeriod($a->subscription, 1, day('2027-04-02'))->number)->toBe('SUB/2027-28/00002');
});

it('gives a failed issue no number (no gaps)', function () {
    $a = newCustomer();
    $svc = app(InvoiceService::class);
    $svc->issueForPeriod($a->subscription, 0, day('2026-11-20'));
    DB::table('invoice_counters')->update(['last_number' => 1]);

    // A second issue of the same period is refused as already existing, and must not consume a number.
    expect($svc->issueForPeriod($a->subscription, 0, day('2026-11-21')))->toBeNull()
        ->and((int) DB::table('invoice_counters')->value('last_number'))->toBe(1);
});

it('derives the financial year from the Colombo date', function () {
    expect(Billing::financialYear(day('2027-03-31')))->toBe('2026-27')
        ->and(Billing::financialYear(day('2027-04-01')))->toBe('2027-28')
        ->and(Billing::financialYear(day('2026-04-01')))->toBe('2026-27')
        ->and(Billing::financialYear(day('2026-03-31')))->toBe('2025-26');
});

it('starts billing the day the trial ends and keeps periods continuous without drift', function () {
    $customer = newCustomer('2026-12-02');            // 30 days free: trial ends 1 Jan 2027
    $sub = $customer->subscription;
    expect($sub->trial_ends_on->toDateString())->toBe('2027-01-01')->and($sub->billing_anchor->toDateString())->toBe('2027-01-01');

    $starts = array_map(fn ($n) => $sub->periodStart($n)->toDateString(), range(0, 3));
    expect($starts)->toBe(['2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01']);
    foreach (range(0, 5) as $n) {
        expect($sub->periodEnd($n)->addDay()->toDateString())->toBe($sub->periodStart($n + 1)->toDateString());
    }
});

it('handles an anchor on the 31st by counting from the anchor, not the previous month', function () {
    $customer = newCustomer('2026-12-01');            // trial ends 31 Dec 2026
    $sub = $customer->subscription;
    expect($sub->billing_anchor->toDateString())->toBe('2026-12-31');
    expect(array_map(fn ($n) => $sub->periodStart($n)->toDateString(), range(0, 4)))->toBe(['2026-12-31', '2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30']);
    expect($sub->periodEnd(1)->toDateString())->toBe('2027-02-27')->and($sub->periodEnd(2)->toDateString())->toBe('2027-03-30');
});

it('issues the first invoice only from 7 days before the trial ends, and only once', function () {
    $customer = newCustomer('2026-11-01');            // trial ends 1 Dec
    $svc = app(InvoiceService::class);

    expect($svc->generateDue(day('2026-11-23')))->toBe(0)->and(Invoice::count())->toBe(0);
    expect($svc->generateDue(day('2026-11-24')))->toBe(1);
    expect($svc->generateDue(day('2026-11-25')))->toBe(0)->and(Invoice::count())->toBe(1);

    $invoice = Invoice::first();
    expect($invoice->period_start->toDateString())->toBe('2026-12-01')->and($invoice->period_end->toDateString())->toBe('2026-12-31')
        ->and($invoice->due_date->toDateString())->toBe('2026-12-01')->and($invoice->issue_date->toDateString())->toBe('2026-11-24');

    expect($svc->generateDue(day('2026-12-25')))->toBe(1);   // January's invoice
    expect(Invoice::orderBy('id')->get()->pluck('period_start')->map->toDateString()->all())->toBe(['2026-12-01', '2027-01-01']);
});

it('catches up every missed month when the schedule did not run', function () {
    newCustomer('2026-11-01');
    expect(app(InvoiceService::class)->generateDue(day('2027-02-10')))->toBe(3);
});

it('does not bill suspended customers', function () {
    $customer = newCustomer('2026-11-01');
    $customer->subscription->update(['status' => 'suspended']);
    expect(app(InvoiceService::class)->generateDue(day('2027-02-10')))->toBe(0);
});

it('requires the plan price before a customer can be added', function () {
    app(Settings::class)->save(['monthly_price' => '']);
    expect(fn () => app(CustomerService::class)->create(['name' => 'A', 'email' => 'a@x.test']))->toThrow(DomainException::class);
    expect(Customer::count())->toBe(0);
});

it('freezes the price on the subscription and the company details on the invoice', function () {
    $customer = newCustomer('2026-11-01', ['address' => 'Old address']);
    seedSettings(['monthly_price' => '15000.00']);
    $invoice = firstInvoice($customer);
    expect((string) $invoice->net)->toBe('10000.00');

    $customer->update(['address' => 'New address']);
    expect($invoice->fresh()->billed_to['address'])->toBe('Old address');
});

it('is refused by the database when an issued invoice is altered or deleted', function () {
    $invoice = firstInvoice(newCustomer());

    expect(fn () => inSavepoint(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['total' => '1.00'])))->toThrow(Exception::class, 'cannot be changed');
    expect(fn () => inSavepoint(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['number' => 'SUB/X/1'])))->toThrow(Exception::class, 'cannot be changed');
    expect(fn () => inSavepoint(fn () => DB::table('invoices')->where('id', $invoice->id)->delete()))->toThrow(Exception::class, 'not allowed');
});

it('voids only an unpaid invoice and never reopens it', function () {
    $invoice = firstInvoice(newCustomer());
    app(InvoiceService::class)->void($invoice, 'issued by mistake');
    expect($invoice->fresh()->status)->toBe('void')->and($invoice->fresh()->void_reason)->toBe('issued by mistake');
    expect(fn () => inSavepoint(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'unpaid'])))->toThrow(Exception::class, 'cannot be reopened');
    expect(fn () => app(InvoiceService::class)->void($invoice->fresh(), 'again'))->toThrow(DomainException::class);
});
