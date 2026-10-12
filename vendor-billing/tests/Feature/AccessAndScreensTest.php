<?php

use App\Mail\ReminderMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\IssuedLicence;
use App\Models\Payment;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\MfaService;
use App\Services\PaymentService;
use App\Support\LicenceToken;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

function staff(): User
{
    return User::query()->create(['name' => 'Staff', 'email' => 'staff@vendor.test', 'password' => 'a-long-password-2', 'role' => User::STAFF]);
}

it('keeps every staff page behind sign-in', function () {
    $customer = newCustomer();
    $invoice = firstInvoice($customer);
    foreach (['/dashboard', '/customers', '/customers/create', "/customers/{$customer->id}", '/invoices', "/invoices/{$invoice->id}", "/invoices/{$invoice->id}/print", '/settings', '/activity'] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    $this->post("/invoices/{$invoice->id}/payments", ['amount' => '1.00', 'received_on' => '2026-11-01'])->assertRedirect('/login');
});

it('signs in with password then authenticator code, and a code cannot be used twice', function () {
    $user = owner();
    $google = app(Google2FA::class);

    $this->post('/login', ['email' => $user->email, 'password' => 'a-long-password-1'])->assertRedirect('/mfa/setup');
    $this->get('/dashboard')->assertRedirect('/login');                         // password alone is not a session
    $this->get('/mfa/setup')->assertOk()->assertSee('<svg', false);
    $secret = $user->fresh()->mfa_secret;

    $this->post('/mfa/setup', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post('/mfa/setup', ['code' => $google->getCurrentOtp($secret)])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();

    // a new browser session, same code within the same 30-second window: refused
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'a-long-password-1'])->assertRedirect('/mfa');
    $this->post('/mfa', ['code' => $google->getCurrentOtp($secret)])->assertSessionHasErrors('code');
    $this->get('/dashboard')->assertRedirect('/login');
    // and an old code is no better: one step back is older than the last one used
    $this->post('/mfa', ['code' => $google->oathTotp($secret, $google->getTimestamp() - 1)])->assertSessionHasErrors('code');

    // the library reads the real clock, so take the code of the next 30-second step (inside the accepted window)
    $this->post('/mfa', ['code' => $google->oathTotp($secret, $google->getTimestamp() + 1)])->assertSessionHasNoErrors();
    $this->get('/dashboard')->assertOk();
});

it('refuses a wrong password, throttles repeated attempts and keeps the secret out of the page', function () {
    $user = owner();
    RateLimiter::clear('login|'.strtolower($user->email).'|127.0.0.1');

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }
    $this->post('/login', ['email' => $user->email, 'password' => 'a-long-password-1'])->assertSessionHasErrors('email');   // locked out even with the right one
    expect(session('errors')->first('email'))->toContain('Too many attempts');
    expect(ActivityLog::where('action', 'auth.failed')->count())->toBe(5);
});

it('does not let a deactivated user in', function () {
    $user = owner();
    $user->update(['active' => false]);
    $this->post('/login', ['email' => $user->email, 'password' => 'a-long-password-1'])->assertSessionHasErrors('email');
});

it('stores the authenticator secret encrypted and resets it from the console', function () {
    $user = owner();
    app(MfaService::class)->pending($user);
    expect(DB::table('users')->where('id', $user->id)->value('mfa_secret'))->not->toBe($user->fresh()->mfa_secret);

    $this->artisan('billing:mfa-reset', ['email' => $user->email])->assertSuccessful();
    expect($user->fresh()->mfa_secret)->toBeNull()->and($user->fresh()->mfa_confirmed_at)->toBeNull();
    $this->artisan('billing:mfa-reset', ['email' => 'nobody@x.test'])->assertFailed();
});

it('creates an owner from the console, asking for the password', function () {
    $this->artisan('billing:owner', ['email' => 'new@vendor.test', '--name' => 'New Owner'])
        ->expectsQuestion('Password (at least 12 characters)', 'correct-horse-battery')
        ->expectsQuestion('Repeat the password', 'correct-horse-battery')
        ->assertSuccessful();
    $user = User::where('email', 'new@vendor.test')->sole();
    expect($user->role)->toBe('owner')->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();

    $this->artisan('billing:owner', ['email' => 'short@vendor.test'])
        ->expectsQuestion('Password (at least 12 characters)', 'short')->assertFailed();
    expect(User::where('email', 'short@vendor.test')->exists())->toBeFalse();
});

it('lets staff add customers and record payments but keeps settings, void, reverse and suspend for the owner', function () {
    useKeys();
    seedSettings();
    signIn(staff());

    $this->get('/customers')->assertOk();
    $this->post('/customers', ['name' => 'New Co', 'email' => 'n@co.test', 'installation_id' => 'inst_new'])->assertRedirect();
    $customer = Customer::where('name', 'New Co')->sole();
    expect($customer->code)->toMatch('/^C\d{4}$/');
    $invoice = firstInvoice($customer);

    $this->post("/invoices/{$invoice->id}/payments", ['amount' => '11800.00', 'received_on' => today()->toDateString(), 'reference' => 'BANK-77'])->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe('paid')->and(Payment::sole()->entered_by)->toBe(User::where('role', 'staff')->value('id'));

    foreach (['get /settings', 'put /settings', 'get /activity'] as $call) {
        [$method, $url] = explode(' ', $call);
        $this->{$method}($url)->assertForbidden();
    }
    $this->post("/invoices/{$invoice->id}/void", ['reason' => 'x'])->assertForbidden();
    $this->post("/invoices/{$invoice->id}/payments/".Payment::sole()->id.'/reverse', ['reason' => 'x'])->assertForbidden();
    $this->post("/customers/{$customer->id}/suspend")->assertForbidden();
    $this->post("/customers/{$customer->id}/token")->assertForbidden();
    expect($invoice->fresh()->status)->toBe('paid');
});

it('validates a bank transfer entry: amount shape, future dates', function () {
    signIn(staff());
    $invoice = firstInvoice(newCustomer());
    foreach ([['amount' => '0', 'received_on' => '2026-11-01'], ['amount' => '12.345', 'received_on' => '2026-11-01'], ['amount' => 'abc', 'received_on' => '2026-11-01'], ['amount' => '10', 'received_on' => '2099-01-01']] as $bad) {
        $this->post("/invoices/{$invoice->id}/payments", $bad)->assertSessionHasErrors();
    }
    expect(Payment::count())->toBe(0);
});

it('renders every screen for the owner and shows the right numbers', function () {
    useKeys();
    signIn(owner());
    $customer = newCustomer('2026-11-01');
    $invoice = firstInvoice($customer, '2026-11-24');
    app(PaymentService::class)->record($invoice, 'bank_transfer', '11800.00', day('2026-12-01'));

    $this->get('/dashboard')->assertOk()->assertSee('Monthly recurring')->assertSee('10,000.00');
    $this->get('/customers?q=customer')->assertOk()->assertSee('Customer Ltd');
    $this->get("/customers/{$customer->id}")->assertOk()->assertSee('Show licence text')->assertSee('31 Dec 2026');
    $this->get("/customers/{$customer->id}/edit")->assertOk();
    $this->get('/invoices?status=paid')->assertOk()->assertSee($invoice->number);
    $this->get("/invoices/{$invoice->id}")->assertOk()->assertSee('11,800.00');
    $this->get("/invoices/{$invoice->id}/print")->assertOk()->assertSee('PAID');
    $this->get('/settings')->assertOk()->assertSee('Monthly price');
    $this->get('/activity')->assertOk()->assertSee('payment.recorded');
});

it('flags customers who have paid but have no licence on the dashboard', function () {
    config(['billing.licence_private_key' => null]);
    signIn(owner());
    $customer = newCustomer('2026-11-01', ['installation_id' => null]);
    app(PaymentService::class)->record(firstInvoice($customer), 'bank_transfer', '11800.00');

    $this->get('/dashboard')->assertSee('Paid, but no licence sent')->assertSee('no installation ID');
});

it('saves settings, logs what changed, and applies a new price to new customers only', function () {
    signIn(owner());
    $old = newCustomer();
    $payload = ['plan_name' => 'Standard', 'monthly_price' => '12500.50', 'vat_rate' => '18', 'trial_days' => '30', 'invoice_lead_days' => '7', 'issuer_name' => 'Acme'];

    $this->put('/settings', $payload)->assertSessionHasNoErrors();
    $new = app(CustomerService::class)->create(['name' => 'Newer', 'email' => 'n@x.test']);

    expect((string) $old->subscription->fresh()->monthly_price)->toBe('10000.00')->and((string) $new->subscription->monthly_price)->toBe('12500.50');
    expect(ActivityLog::where('action', 'settings.updated')->first()->details['changed'])->toContain('monthly_price');
    foreach ([['monthly_price' => '0'], ['monthly_price' => '10.999'], ['vat_rate' => '120'], ['trial_days' => '-1'], ['issuer_name' => '']] as $bad) {
        $this->put('/settings', array_merge($payload, $bad))->assertSessionHasErrors();
    }
});

it('serves an installation its newest licence only with its own token', function () {
    $public = useKeys();
    $customer = newCustomer();
    $token = app(CustomerService::class)->rotateApiToken($customer);
    expect(DB::table('customers')->where('id', $customer->id)->value('api_token_hash'))->not->toBe($token);   // only a hash is kept
    $url = '/api/v1/installations/inst_abc123/licence';

    $this->getJson($url)->assertStatus(401);
    $this->withToken('wrong')->getJson($url)->assertStatus(401);
    $this->withToken($token)->getJson('/api/v1/installations/inst_other/licence')->assertStatus(401);       // same answer: IDs cannot be probed
    $this->withToken($token)->getJson($url)->assertStatus(404)->assertJsonPath('error.code', 'no_licence');

    app(PaymentService::class)->record(firstInvoice($customer), 'bank_transfer', '11800.00');
    $response = $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.valid_until', '2026-12-31');
    expect(LicenceToken::verify($response->json('data.licence'), $public)['installation'])->toBe('inst_abc123');

    // another customer's token does not open this installation
    $other = app(CustomerService::class)->create(['name' => 'Other', 'email' => 'o@x.test', 'installation_id' => 'inst_zzz']);
    $this->withToken(app(CustomerService::class)->rotateApiToken($other))->getJson($url)->assertStatus(401);
    // a rotated token replaces the old one
    $newToken = app(CustomerService::class)->rotateApiToken($customer);
    $this->withToken($token)->getJson($url)->assertStatus(401);
    $this->withToken($newToken)->getJson($url)->assertOk();
});

it('lets the owner issue a licence by hand, suspend and resume from the customer page', function () {
    useKeys();
    signIn(owner());
    $customer = newCustomer();
    $invoice = firstInvoice($customer);
    $invoice->forceFill(['status' => 'paid'])->save();                       // paid before a key existed, say

    $this->post("/customers/{$customer->id}/licence")->assertSessionHasNoErrors();
    expect(IssuedLicence::count())->toBe(1);
    $this->post("/customers/{$customer->id}/suspend")->assertSessionHasNoErrors();
    expect($customer->subscription->fresh()->status)->toBe('suspended')->and(IssuedLicence::orderByDesc('id')->first()->kind)->toBe('suspension');
    $this->post("/customers/{$customer->id}/resume")->assertSessionHasNoErrors();
    expect($customer->subscription->fresh()->status)->toBe('active')->and(IssuedLicence::orderByDesc('id')->first()->kind)->toBe('renewal');
});

it('sends one reminder per stage, only the latest reached, and none for paid or suspended customers', function () {
    Mail::fake();
    $customer = newCustomer('2026-11-01');                     // first invoice due 1 Dec 2026
    $invoice = firstInvoice($customer, '2026-11-24');
    $remind = fn (string $today) => tap($this->travelTo(day($today)->setTime(8, 0)), fn () => $this->artisan('billing:remind')->assertSuccessful());

    $remind('2026-11-27');                                    // 4 days before: stage -3 not reached
    Mail::assertNothingSent();
    $remind('2026-11-28');                                    // 3 days before
    Mail::assertSent(ReminderMail::class, 1);
    $remind('2026-11-29');
    Mail::assertSent(ReminderMail::class, 1);                 // same stage: nothing new
    $remind('2026-12-01');                                    // due today
    Mail::assertSent(ReminderMail::class, 2);
    $remind('2026-12-25');                                    // 24 days late: one message, not one per skipped stage
    Mail::assertSent(ReminderMail::class, 3);
    $remind('2026-12-26');
    Mail::assertSent(ReminderMail::class, 3);
    expect($invoice->fresh()->reminders_sent)->toEqualCanonicalizing([-3, 0, 3, 7, 21]);

    $other = newCustomerNamed('Paid Co');
    $paid = firstInvoice($other, '2026-11-24');
    $paid->forceFill(['status' => 'paid'])->save();
    $susp = newCustomerNamed('Susp Co');
    firstInvoice($susp, '2026-11-24');
    $susp->subscription->update(['status' => 'suspended']);
    $remind('2027-02-01');
    Mail::assertSent(ReminderMail::class, fn (ReminderMail $m) => $m->invoice->is($invoice) || false);
    Mail::assertNotSent(ReminderMail::class, fn (ReminderMail $m) => $m->invoice->customer_id === $other->id || $m->invoice->customer_id === $susp->id);
});

function newCustomerNamed(string $name): Customer
{
    return app(CustomerService::class)->create(['name' => $name, 'email' => str($name)->slug().'@x.test', 'installation_id' => 'inst_'.str($name)->slug()], day('2026-11-01'));
}

it('schedules the daily jobs', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('billing:invoice')->expectsOutputToContain('billing:remind')->assertSuccessful();
});

it('runs billing:invoice from the console', function () {
    Mail::fake();
    newCustomer('2026-11-01');
    $this->travelTo(day('2026-11-25')->setTime(6, 0));
    $this->artisan('billing:invoice')->expectsOutputToContain('1 invoice(s) issued.')->assertSuccessful();
    expect(Invoice::count())->toBe(1);
});
