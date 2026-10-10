<?php

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

function loginPayload(array $overrides = []): array
{
    return array_merge(['email' => 'amal@example.test', 'password' => 'secret-pass-1', 'device_name' => 'pest'], $overrides);
}

it('issues a bearer token for valid credentials and the token works', function () {
    User::factory()->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);

    $response = $this->postJson('/api/v1/auth/login', loginPayload())->assertOk()
        ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'user' => ['id', 'email', 'roles', 'permissions']]]);

    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'amal@example.test');

    expect(AuditLog::where('event', 'auth.login')->count())->toBe(1);
});

it('rejects wrong credentials without saying which part was wrong', function () {
    User::factory()->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);

    $this->postJson('/api/v1/auth/login', loginPayload(['password' => 'nope']))
        ->assertUnauthorized()->assertJsonPath('error.code', 'auth.invalid_credentials');
    $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'ghost@example.test']))
        ->assertUnauthorized()->assertJsonPath('error.code', 'auth.invalid_credentials');

    expect(AuditLog::where('event', 'auth.login_failed')->count())->toBe(2);
});

it('blocks inactive and external accounts', function () {
    User::factory()->inactive()->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);
    $this->postJson('/api/v1/auth/login', loginPayload())->assertForbidden()->assertJsonPath('error.code', 'auth.account_disabled');

    User::factory()->create(['email' => 'cust@example.test', 'password' => 'secret-pass-1', 'user_type' => User::TYPE_CUSTOMER]);
    $this->postJson('/api/v1/auth/login', loginPayload(['email' => 'cust@example.test']))
        ->assertForbidden()->assertJsonPath('error.code', 'auth.account_disabled');
});

it('throttles repeated login attempts with Retry-After', function () {
    RateLimiter::clear('login');
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', loginPayload())->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', loginPayload())
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limit.exceeded')
        ->assertHeader('Retry-After');
});

it('requires the TOTP code when MFA is enabled', function () {
    $user = User::factory()->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);
    $secret = enrolMfa($user);

    $this->postJson('/api/v1/auth/login', loginPayload())->assertUnauthorized()->assertJsonPath('error.code', 'auth.mfa_required');
    $this->postJson('/api/v1/auth/login', loginPayload(['mfa_code' => '000000']))->assertUnauthorized()->assertJsonPath('error.code', 'auth.mfa_invalid');

    $code = app(Google2FA::class)->getCurrentOtp($secret);
    $this->postJson('/api/v1/auth/login', loginPayload(['mfa_code' => $code]))->assertOk();
});

it('does not accept the same TOTP code twice', function () {
    $user = User::factory()->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);
    $code = app(Google2FA::class)->getCurrentOtp(enrolMfa($user));

    $this->postJson('/api/v1/auth/login', loginPayload(['mfa_code' => $code]))->assertOk();
    $this->postJson('/api/v1/auth/login', loginPayload(['mfa_code' => $code]))->assertUnauthorized()->assertJsonPath('error.code', 'auth.mfa_invalid');
});

it('revokes the token on logout', function () {
    User::factory()->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);
    $token = $this->postJson('/api/v1/auth/login', loginPayload())->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('forces privileged roles through MFA enrolment before anything else', function () {
    $admin = User::factory()->withRole('admin')->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);
    $token = $this->postJson('/api/v1/auth/login', loginPayload())->json('data.token');

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertForbidden()->assertJsonPath('error.code', 'auth.mfa_enrollment_required');
    $this->withToken($token)->getJson('/api/v1/branches')
        ->assertForbidden()->assertJsonPath('error.code', 'auth.mfa_enrollment_required');

    $secret = $this->withToken($token)->postJson('/api/v1/auth/mfa/setup')->assertOk()->json('data.secret');
    $this->withToken($token)->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])->assertStatus(422);

    $codes = $this->withToken($token)
        ->postJson('/api/v1/auth/mfa/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertOk()->json('data.recovery_codes');
    expect($codes)->toHaveCount(8);

    $this->app['auth']->forgetGuards(); // a real request would load the user afresh
    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa_enabled', true);
    expect($admin->fresh()->hasMfaEnabled())->toBeTrue();
});

it('accepts a recovery code once', function () {
    $user = User::factory()->withRole('admin')->create(['email' => 'amal@example.test', 'password' => 'secret-pass-1']);
    $token = $this->postJson('/api/v1/auth/login', loginPayload())->json('data.token');
    $secret = $this->withToken($token)->postJson('/api/v1/auth/mfa/setup')->json('data.secret');
    $codes = $this->withToken($token)->postJson('/api/v1/auth/mfa/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->json('data.recovery_codes');

    $this->postJson('/api/v1/auth/login', loginPayload(['mfa_code' => $codes[0]]))->assertOk();
    $this->postJson('/api/v1/auth/login', loginPayload(['mfa_code' => $codes[0]]))->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', loginPayload(['mfa_code' => $codes[1]]))->assertOk();
});

it('never stores the plain TOTP secret', function () {
    $user = User::factory()->create();
    $secret = enrolMfa($user);

    $raw = DB::table('mfa_secrets')->where('user_id', $user->id)->value('secret');
    expect($raw)->not->toContain($secret);
});
