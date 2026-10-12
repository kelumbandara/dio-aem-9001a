<?php

use App\Mail\InvoiceMail;
use App\Mail\LicenceMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\IssuedLicence;
use App\Models\Payment;
use App\Services\CustomerService;
use App\Services\InvoiceService;
use App\Services\LicenceIssuer;
use App\Services\PaymentService;
use App\Support\LicenceException;
use App\Support\LicenceToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function payFull(Invoice $invoice, string $method = Payment::BANK): Payment
{
    return app(PaymentService::class)->record($invoice->fresh(), $method, (string) $invoice->fresh()->balance(), day('2026-12-01'), 'REF-1');
}

/** The next period's invoice, created the way the schedule would. */
function nextInvoice(Customer $customer, int $n): Invoice
{
    return app(InvoiceService::class)->issueForPeriod($customer->subscription->fresh(), $n, day('2026-11-24'));
}

it('marks an invoice paid and sends a licence the product can verify, when a bank transfer covers the total', function () {
    Mail::fake();
    $public = useKeys();
    $customer = newCustomer('2026-11-01');       // trial ends 1 Dec; first period 1–31 Dec
    $invoice = firstInvoice($customer, '2026-11-24');

    payFull($invoice);

    expect($invoice->fresh()->status)->toBe('paid')->and($invoice->fresh()->paid_at)->not->toBeNull();
    $licence = IssuedLicence::sole();
    $payload = LicenceToken::verify($licence->token, $public);       // the same check the product runs, with the public key only
    expect($payload['installation'])->toBe('inst_abc123')->and($payload['company'])->toBe('Customer Ltd')->and($payload['plan'])->toBe('Standard')
        ->and($payload['valid_from'])->toBe('2026-12-01')->and($payload['valid_until'])->toBe('2026-12-31')->and($payload['serial'])->toBe($licence->serial);
    Mail::assertSent(LicenceMail::class, fn (LicenceMail $m) => $m->hasTo('accounts@customer.test') && $m->licence->is($licence));
    expect($licence->fresh()->emailed_at)->not->toBeNull();
});

it('does not sign a licence another key could have made', function () {
    $public = useKeys();
    $customer = newCustomer();
    payFull(firstInvoice($customer));
    $token = IssuedLicence::sole()->token;

    [$otherPublic] = LicenceToken::generateKeys();
    expect(fn () => LicenceToken::verify($token, $otherPublic))->toThrow(RuntimeException::class);
    expect(LicenceToken::verify($token, $public)['installation'])->toBe('inst_abc123');
});

it('keeps a partial payment unpaid and pays the invoice when the rest arrives', function () {
    useKeys();
    $invoice = firstInvoice(newCustomer());      // total 11,800.00
    $svc = app(PaymentService::class);

    $svc->record($invoice, Payment::BANK, '5000.00', day('2026-12-01'));
    expect($invoice->fresh()->status)->toBe('unpaid')->and($invoice->fresh()->balance())->toBe('6800.00')->and(IssuedLicence::count())->toBe(0);

    $svc->record($invoice, Payment::BANK, '6800.00', day('2026-12-02'));
    expect($invoice->fresh()->status)->toBe('paid')->and(IssuedLicence::count())->toBe(1);
});

it('records an overpayment without losing it and flags it', function () {
    useKeys();
    $invoice = firstInvoice(newCustomer());
    app(PaymentService::class)->record($invoice, Payment::BANK, '12000.00', day('2026-12-01'));

    expect($invoice->fresh()->status)->toBe('paid')->and($invoice->fresh()->balance())->toBe('0.00');
    expect(ActivityLog::where('action', 'payment.recorded')->first()->details['overpaid'])->toBeTrue();
});

it('refuses a zero, negative, over-precise or non-numeric amount and a payment on a void invoice', function () {
    $invoice = firstInvoice(newCustomer());
    $svc = app(PaymentService::class);
    foreach (['0', '0.00', '-5.00', '10.001', 'abc', '1e3', ''] as $bad) {
        expect(fn () => $svc->record($invoice, Payment::BANK, $bad))->toThrow(DomainException::class);
    }
    app(InvoiceService::class)->void($invoice, 'mistake');
    expect(fn () => $svc->record($invoice->fresh(), Payment::BANK, '100.00'))->toThrow(DomainException::class, 'void');
    expect(Payment::count())->toBe(0);
});

it('extends the licence month by month and never overlaps or leaves a gap', function () {
    useKeys();
    $customer = newCustomer('2026-11-01');
    payFull(firstInvoice($customer));                       // Dec
    payFull(nextInvoice($customer, 1));                     // Jan
    payFull(nextInvoice($customer, 2));                     // Feb

    $ranges = IssuedLicence::orderBy('id')->get()->map(fn ($l) => $l->valid_from->toDateString().'→'.$l->valid_until->toDateString())->all();
    expect($ranges)->toBe(['2026-12-01→2026-12-31', '2027-01-01→2027-01-31', '2027-02-01→2027-02-28']);
});

it('does not extend the licence when a later month is paid before an earlier one', function () {
    useKeys();
    $customer = newCustomer('2026-11-01');
    $dec = firstInvoice($customer);
    $jan = nextInvoice($customer, 1);
    $feb = nextInvoice($customer, 2);

    payFull($feb);
    payFull($jan);
    expect(IssuedLicence::count())->toBe(0);                 // December is still unpaid: nothing can be proven yet

    payFull($dec);
    $licence = IssuedLicence::sole();                        // one licence now covers all three paid months
    expect($licence->valid_from->toDateString())->toBe('2026-12-01')->and($licence->valid_until->toDateString())->toBe('2027-02-28');
});

it('treats a voided month as waived, so it does not hold back the licence', function () {
    useKeys();
    $customer = newCustomer('2026-11-01');
    $dec = firstInvoice($customer);
    $jan = nextInvoice($customer, 1);
    app(InvoiceService::class)->void($dec, 'goodwill');
    payFull($jan);

    $licence = IssuedLicence::sole();
    expect($licence->valid_from->toDateString())->toBe('2026-12-01')->and($licence->valid_until->toDateString())->toBe('2027-01-31');
});

it('keeps the payment and warns when the signing key is missing, then issues once the key is set', function () {
    config(['billing.licence_private_key' => null]);
    $customer = newCustomer();
    $invoice = firstInvoice($customer);

    payFull($invoice);

    expect($invoice->fresh()->status)->toBe('paid')->and(IssuedLicence::count())->toBe(0)
        ->and(ActivityLog::where('action', 'licence.failed')->exists())->toBeTrue();

    $public = useKeys();
    $licence = app(LicenceIssuer::class)->issueCoverage($customer->fresh());
    expect(LicenceToken::verify($licence->token, $public)['valid_until'])->toBe('2026-12-31');
});

it('issues the licence as soon as the installation ID becomes known', function () {
    useKeys();
    $customer = newCustomer('2026-11-01', ['installation_id' => null]);
    payFull(firstInvoice($customer));
    expect(IssuedLicence::count())->toBe(0);

    app(CustomerService::class)->update($customer, ['installation_id' => 'inst_late']);

    $licence = IssuedLicence::sole();
    expect($licence->installation_id)->toBe('inst_late');
});

it('does not issue the same coverage twice', function () {
    useKeys();
    $customer = newCustomer();
    payFull(firstInvoice($customer));
    expect(app(LicenceIssuer::class)->issueCoverage($customer->fresh()))->toBeNull()->and(IssuedLicence::count())->toBe(1);
});

it('reverses a payment: invoice unpaid again, money no longer counted, licence kept and the record stays', function () {
    useKeys();
    $invoice = firstInvoice(newCustomer());
    $payment = payFull($invoice);

    app(PaymentService::class)->reverse($payment, 'cheque bounced');

    expect($invoice->fresh()->status)->toBe('unpaid')->and($invoice->fresh()->paidAmount())->toBe('0.00')->and($invoice->fresh()->balance())->toBe('11800.00');
    expect($payment->fresh()->reversal_reason)->toBe('cheque bounced')->and(IssuedLicence::count())->toBe(1);
    expect(fn () => app(PaymentService::class)->reverse($payment->fresh(), 'again'))->toThrow(DomainException::class);
});

it('is refused by the database when a payment is changed or deleted, or a licence or the activity log is edited', function () {
    useKeys();
    $payment = payFull(firstInvoice(newCustomer()));

    expect(fn () => inSavepoint(fn () => DB::table('payments')->where('id', $payment->id)->update(['amount' => '1.00'])))->toThrow(Exception::class, 'cannot be changed');
    expect(fn () => inSavepoint(fn () => DB::table('payments')->where('id', $payment->id)->delete()))->toThrow(Exception::class, 'not allowed');
    expect(fn () => inSavepoint(fn () => DB::table('issued_licences')->update(['valid_until' => '2099-01-01'])))->toThrow(Exception::class, 'cannot be changed');
    expect(fn () => inSavepoint(fn () => DB::table('issued_licences')->delete()))->toThrow(Exception::class, 'not allowed');
    expect(fn () => inSavepoint(fn () => DB::table('activity_log')->update(['action' => 'x'])))->toThrow(Exception::class, 'not allowed');
    expect(fn () => inSavepoint(fn () => DB::table('activity_log')->delete()))->toThrow(Exception::class, 'not allowed');
});

it('suspends with an already-expired licence that locks the product at once, and resumes by re-issuing what is paid', function () {
    $public = useKeys();
    $customer = newCustomer('2026-11-01');
    payFull(firstInvoice($customer));
    $this->travelTo(now()->setDate(2026, 12, 15)->setTime(10, 0));

    app(LicenceIssuer::class)->suspend($customer->fresh());
    $suspension = IssuedLicence::orderByDesc('id')->first();
    expect($suspension->kind)->toBe('suspension')->and($customer->fresh()->subscription->status)->toBe('suspended');
    // 7 grace + 14 read-only + 1 day before today = locked on the day it is issued
    expect(LicenceToken::verify($suspension->token, $public)['valid_until'])->toBe('2026-11-23');
    expect(app(InvoiceService::class)->generateDue(day('2027-03-01')))->toBe(0);

    $this->travel(5)->seconds();
    app(LicenceIssuer::class)->resume($customer->fresh());
    $restored = IssuedLicence::orderByDesc('id')->first();
    expect($restored->kind)->toBe('renewal')->and($restored->valid_until->toDateString())->toBe('2026-12-31')->and($customer->fresh()->subscription->status)->toBe('active');
});

it('emails the invoice when the schedule issues it', function () {
    Mail::fake();
    newCustomer('2026-11-01');
    expect(app(InvoiceService::class)->generateDue(day('2026-11-25')))->toBe(1);
    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $m) => $m->hasTo('accounts@customer.test'));
});

it('signs the compatibility licence the product test uses (fixed key, fixed values)', function () {
    // A fixed key pair so the product's own test suite can embed this exact token and prove the two projects agree.
    $seed = str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES);
    $pair = sodium_crypto_sign_seed_keypair($seed);
    $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    config(['billing.licence_private_key' => $secret]);

    $token = LicenceToken::sign([
        'v' => 1, 'serial' => 'L-20261201-TESTAA', 'installation' => 'inst_compat', 'company' => 'Compat Ltd', 'plan' => 'Standard',
        'valid_from' => '2026-12-01', 'valid_until' => '2026-12-31', 'issued_at' => '2026-11-24T10:00:00+00:00', 'max_users' => null, 'modules' => null,
    ], $secret);

    expect(LicenceToken::verify($token, $public)['company'])->toBe('Compat Ltd');
    // The product keeps a copy of this text for its own test (tests/Feature/Licensing/VendorCompatibilityTest.php); both must stay identical.
    $product = __DIR__.'/../../../tests/Fixtures/vendor-billing-licence.txt';
    if (file_exists($product)) {
        expect(trim(file_get_contents($product)))->toBe($token);
    }
    expect(trim(file_get_contents(__DIR__.'/../fixtures/compat-licence.txt')))->toBe($token);
});

it('rejects a missing licence signing key with a clear error', function () {
    config(['billing.licence_private_key' => '']);
    $customer = newCustomer();
    $invoice = firstInvoice($customer);
    $invoice->forceFill(['status' => 'paid'])->save();

    expect(fn () => app(LicenceIssuer::class)->issueCoverage($customer->fresh()))->toThrow(LicenceException::class, 'LICENCE_PRIVATE_KEY');
});
