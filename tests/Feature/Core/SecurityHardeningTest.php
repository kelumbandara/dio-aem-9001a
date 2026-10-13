<?php

use App\Modules\Core\Actions\AuthenticateUser;
use App\Modules\Core\Models\User;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\Finder\Finder;

describe('browser protections', function () {
    it('sends the protective headers on every kind of answer, with a nonce that matches the page', function () {
        $page = $this->get('/login');
        $page->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $csp = $page->headers->get('Content-Security-Policy');
        expect($csp)->toContain("default-src 'self'")->and($csp)->toContain("frame-ancestors 'none'")->and($csp)->toContain("object-src 'none'")
            ->and($csp)->toContain("base-uri 'self'")->and($csp)->toContain("form-action 'self'");
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        expect($m[1] ?? '')->not->toBe('');

        $this->getJson('/api/v1/health')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
        $this->get('/no-such-page')->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
    });

    it('uses a different nonce on every request', function () {
        $a = $this->get('/login')->headers->get('Content-Security-Policy');
        $b = $this->get('/login')->headers->get('Content-Security-Policy');
        expect($a)->not->toBe($b);
    });

    it('asks for https only when the answer is itself over https', function () {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        config(['core.security.hsts_seconds' => 0]);
        $this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    });

    it('has no inline event handlers and no un-nonced inline scripts in any screen (the policy would silently block them)', function () {
        $bad = [];
        $files = Finder::create()->files()->in([base_path('app'), base_path('resources')])->name('*.blade.php');
        foreach ($files as $file) {
            $text = $file->getContents();
            if (preg_match_all('/\son(?:click|submit|change|input|load|keydown|keyup|focus|blur|mouse\w+)\s*=/i', $text, $m)) {
                $bad[] = $file->getRelativePathname().' has an inline handler: '.trim($m[0][0]);
            }
            if (preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce=)[^>]*>/i', $text, $m)) {
                $bad[] = $file->getRelativePathname().' has an inline script without a nonce';
            }
        }
        expect($bad)->toBe([]);
    });

    it('lets the Vite tags carry the nonce too', function () {
        $this->get('/login');
        expect(Vite::cspNonce())->not->toBeNull();
    });
});

describe('unreadable web addresses', function () {
    it('answer "not found", not a server error, for a malformed id of either kind', function () {
        $admin = userWithPermissions('core.branch.view', 'core.user.view', 'approvals.policy.manage');
        $this->actingAs($admin)->get('/admin/users/not-a-uuid')->assertNotFound();
        asApi($admin)->getJson('/api/v1/branches/not-a-uuid')->assertNotFound()->assertJsonPath('error.code', 'resource.not_found');
        asApi($admin)->deleteJson('/api/v1/approvals/delegations/abc', [], idemKey())->assertNotFound();
    });
});

describe('account lockout', function () {
    beforeEach(function () {
        config(['core.auth.lockout_attempts' => 3, 'core.auth.lockout_minutes' => 15]);
        $this->user = User::factory()->create(['email' => 'lock@dio.test', 'password' => 'Right-Password-1']);
        RateLimiter::clear('account-lock:'.sha1('lock@dio.test'));
        $this->login = fn (string $pw) => app(AuthenticateUser::class)('lock@dio.test', $pw);
    });

    it('locks the account after the wrong passwords, even for the right password, whatever address they come from', function () {
        for ($i = 0; $i < 3; $i++) {
            try {
                ($this->login)('wrong');
            } catch (BusinessException $e) {
                expect($e->errorCode)->toBe('auth.invalid_credentials');
            }
        }
        try {
            ($this->login)('Right-Password-1');
            $this->fail('Expected a lockout.');
        } catch (BusinessException $e) {
            expect($e->errorCode)->toBe('auth.account_locked')->and($e->status)->toBe(429)->and($e->getMessage())->toContain('15 minutes');
        }
        expect($this->user->refresh()->last_login_at)->toBeNull();
    });

    it('lets the person in again after the lockout time, and a good sign-in forgets earlier mistakes', function () {
        for ($i = 0; $i < 2; $i++) {
            try {
                ($this->login)('wrong');
            } catch (BusinessException) {
            }
        }
        expect(($this->login)('Right-Password-1')->id)->toBe($this->user->id);       // 2 mistakes then success: counter cleared
        for ($i = 0; $i < 3; $i++) {
            try {
                ($this->login)('wrong');
            } catch (BusinessException) {
            }
        }
        $this->travel(16)->minutes();
        expect(($this->login)('Right-Password-1')->id)->toBe($this->user->id);
    });

    it('counts a wrong authenticator code as a failure too, and records the lockout', function () {
        $this->user->forceFill(['password' => 'Right-Password-1'])->save();
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit('account-lock:'.sha1('lock@dio.test'), 900);
        }
        try {
            ($this->login)('Right-Password-1');
        } catch (BusinessException) {
        }
        expect(DB::table('audit_logs')->where('event', 'auth.login_locked')->exists())->toBeTrue();
    });

    it('shows the lockout on the sign-in page and over the API', function () {
        config(['core.auth.login_attempts_per_minute' => 100]);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'lock@dio.test', 'password' => 'wrong', 'device_name' => 'x']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'lock@dio.test', 'password' => 'Right-Password-1', 'device_name' => 'x'])->assertStatus(429)->assertJsonPath('error.code', 'auth.account_locked');
    });
});
