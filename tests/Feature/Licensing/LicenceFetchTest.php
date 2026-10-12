<?php

use App\Modules\Licensing\Models\Licence;
use App\Modules\Licensing\Services\LicenceService;
use App\Modules\Licensing\Support\LicenceToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    [$this->public, $this->secret] = LicenceToken::generateKeys();
    config([
        'licensing.public_key' => $this->public, 'licensing.vendor_url' => 'https://billing.vendor.test', 'licensing.vendor_token' => 'bt_secret-token',
    ]);
    $this->installationId = app(LicenceService::class)->installationId();
    $this->issue = fn (array $o = []) => LicenceToken::sign(array_merge([
        'v' => 1, 'serial' => 'L-'.bin2hex(random_bytes(4)), 'installation' => $this->installationId, 'company' => 'Acme Trading', 'plan' => 'Standard',
        'valid_from' => '2026-12-01', 'valid_until' => '2026-12-31', 'issued_at' => now()->toIso8601String(), 'max_users' => null, 'modules' => null,
    ], $o), $this->secret);
    $this->vendorUrl = fn () => 'https://billing.vendor.test/api/v1/installations/'.rawurlencode($this->installationId).'/licence';
});

it('does nothing, quietly, when automatic renewal is not set up', function () {
    config(['licensing.vendor_url' => null]);
    Http::fake();

    $this->artisan('licence:fetch')->expectsOutputToContain('not set up')->assertSuccessful();
    Http::assertNothingSent();
});

it('installs the licence the vendor system returns, sending only this installation\'s token', function () {
    $token = ($this->issue)();
    Http::fake([($this->vendorUrl)() => Http::response(['data' => ['licence' => $token, 'serial' => 'x']])]);

    $this->artisan('licence:fetch')->expectsOutputToContain('Installed licence')->assertSuccessful();

    expect(Licence::query()->where('token', $token)->exists())->toBeTrue();
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer bt_secret-token') && $request->method() === 'GET');
});

it('is a no-op when the licence is already installed', function () {
    $token = ($this->issue)();
    Http::fake([($this->vendorUrl)() => Http::response(['data' => ['licence' => $token]])]);

    $this->artisan('licence:fetch')->assertSuccessful();
    $this->artisan('licence:fetch')->expectsOutputToContain('Already up to date')->assertSuccessful();
    expect(Licence::count())->toBe(1);
});

it('still verifies the licence itself: a forged or foreign one from the vendor system is refused', function () {
    [, $otherSecret] = LicenceToken::generateKeys();
    $forged = LicenceToken::sign(['v' => 1, 'serial' => 'L-FORGED', 'installation' => $this->installationId, 'company' => 'X', 'plan' => 'Standard', 'valid_from' => '2026-12-01', 'valid_until' => '2099-12-31', 'issued_at' => now()->toIso8601String()], $otherSecret);
    $foreign = ($this->issue)(['installation' => 'inst_someone_else']);

    foreach ([$forged, $foreign] as $bad) {
        Http::fake([($this->vendorUrl)() => Http::response(['data' => ['licence' => $bad]])]);
        $this->artisan('licence:fetch')->assertFailed();
    }
    expect(Licence::count())->toBe(0);
});

it('reports a refused token', function () {
    Http::fake([($this->vendorUrl)() => Http::response(['error' => ['code' => 'unauthorized']], 401)]);
    $this->artisan('licence:fetch')->expectsOutputToContain('Check VENDOR_BILLING_TOKEN')->assertFailed();
    expect(Licence::count())->toBe(0);
});

it('treats "no licence issued yet" as nothing to do', function () {
    Http::fake([($this->vendorUrl)() => Http::response(['error' => ['code' => 'no_licence']], 404)]);
    $this->artisan('licence:fetch')->expectsOutputToContain('No licence has been issued')->assertSuccessful();
    expect(Licence::count())->toBe(0);
});

it('reports an unreachable vendor system without installing anything', function () {
    Http::fake([($this->vendorUrl)() => fn () => throw new ConnectionException('timeout')]);
    $this->artisan('licence:fetch')->expectsOutputToContain('could not be reached')->assertFailed();
    expect(Licence::count())->toBe(0);
});

it('is scheduled and the token is never printed', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('licence:fetch')->assertSuccessful();

    Http::fake([($this->vendorUrl)() => Http::response([], 500)]);
    $this->artisan('licence:fetch')->doesntExpectOutputToContain('bt_secret-token')->assertFailed();
});
