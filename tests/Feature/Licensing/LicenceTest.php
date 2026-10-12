<?php

use App\Modules\Core\Contracts\Appearance;
use App\Modules\Core\Contracts\LicenceGate;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Licensing\Models\Licence;
use App\Modules\Licensing\Services\LicenceService;
use App\Modules\Licensing\Support\LicenceToken;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

function licenceFails(Closure $fn, string $code): void
{
    try {
        $fn();
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected business error {$code}.");
}

beforeEach(function () {
    [$this->public, $this->secret] = LicenceToken::generateKeys();
    config(['licensing.public_key' => $this->public, 'licensing.enforce' => true, 'licensing.trial_days' => 30, 'licensing.grace_days' => 7, 'licensing.readonly_days' => 14, 'licensing.warn_days' => 7]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', 'Asia/Colombo'));
    $this->service = fn () => app(LicenceService::class);
    $this->installationId = app(LicenceService::class)->installationId();
    /** A signed licence for this installation; $o overrides payload fields. */
    $this->issue = fn (array $o = []) => LicenceToken::sign(array_merge([
        'v' => 1, 'serial' => 'L-'.bin2hex(random_bytes(4)), 'installation' => $this->installationId, 'company' => 'Acme Trading', 'plan' => 'standard',
        'valid_from' => '2026-11-01', 'valid_until' => '2026-11-30', 'issued_at' => now()->toIso8601String(), 'max_users' => null, 'modules' => null,
    ], $o), $this->secret);
    $this->manager = userWithPermissions('licensing.manage');
});

describe('the signed licence', function () {
    it('signs and verifies, and refuses any change, another vendor, or nonsense', function () {
        $token = ($this->issue)();
        expect(LicenceToken::verify($token, $this->public))->toMatchArray(['company' => 'Acme Trading', 'plan' => 'standard', 'valid_until' => '2026-11-30']);

        [$prefix, $payload, $signature] = explode('.', $token);
        $tampered = $prefix.'.'.rtrim(strtr(base64_encode(str_replace('2026-11-30', '2099-11-30', base64_decode(strtr($payload, '-_', '+/')))), '+/', '-_'), '=').'.'.$signature;
        licenceFails(fn () => LicenceToken::verify($tampered, $this->public), 'licensing.bad_signature');
        licenceFails(fn () => LicenceToken::verify($token, LicenceToken::generateKeys()[0]), 'licensing.bad_signature');
        licenceFails(fn () => LicenceToken::verify('hello', $this->public), 'licensing.invalid_format');
        licenceFails(fn () => LicenceToken::verify('DIO1.abc.def', $this->public), 'licensing.invalid_format');
        licenceFails(fn () => LicenceToken::verify($token, null), 'licensing.no_public_key');
        licenceFails(fn () => LicenceToken::verify(($this->issue)(['valid_until' => '2026-10-01']), $this->public), 'licensing.invalid_format');   // ends before it starts
        licenceFails(fn () => LicenceToken::verify(($this->issue)(['valid_from' => '2026-13-01']), $this->public), 'licensing.invalid_format');
    });

    it('survives being pasted with line breaks', function () {
        $token = ($this->issue)();
        expect(LicenceToken::verify(chunk_split($token, 40, "\n"), $this->public))->toHaveKey('serial');
    });
});

describe('installing', function () {
    it('installs a licence for this installation and keeps it as history', function () {
        $licence = ($this->service)()->install(($this->issue)(['max_users' => 25, 'modules' => ['sales', 'finance']]), $this->manager->id);

        expect($licence->valid_until->toDateString())->toBe('2026-11-30')->and($licence->max_users)->toBe(25)->and($licence->modules)->toBe(['sales', 'finance'])->and($licence->entered_by)->toBe($this->manager->id);
        expect(($this->service)()->install($licence->token)->id)->toBe($licence->id)->and(Licence::count())->toBe(1);   // the same licence again changes nothing
    });

    it('refuses a licence for another installation, a forged one, a future-dated one and an older one', function () {
        licenceFails(fn () => ($this->service)()->install(($this->issue)(['installation' => 'inst_someoneelse'])), 'licensing.wrong_installation');
        licenceFails(fn () => ($this->service)()->install(LicenceToken::sign(['v' => 1, 'serial' => 'X', 'installation' => $this->installationId, 'company' => 'x', 'plan' => 'x', 'valid_from' => '2026-11-01', 'valid_until' => '2030-01-01', 'issued_at' => now()->toIso8601String()], LicenceToken::generateKeys()[1])), 'licensing.bad_signature');
        licenceFails(fn () => ($this->service)()->install(($this->issue)(['issued_at' => now()->addDays(10)->toIso8601String()])), 'licensing.from_the_future');

        ($this->service)()->install(($this->issue)(['issued_at' => now()->toIso8601String()]));
        licenceFails(fn () => ($this->service)()->install(($this->issue)(['issued_at' => now()->subDay()->toIso8601String(), 'valid_until' => '2030-01-01'])), 'licensing.not_newer');
        expect(Licence::count())->toBe(1);
    });

    it('lets a newer licence replace the one in force, even a shorter one (that is how a vendor suspends)', function () {
        ($this->service)()->install(($this->issue)(['valid_until' => '2027-11-30', 'issued_at' => now()->subHour()->toIso8601String()]));
        expect(($this->service)()->status()->validUntil)->toBe('2027-11-30');

        ($this->service)()->install(($this->issue)(['valid_until' => '2026-11-01', 'issued_at' => now()->toIso8601String()]));
        expect(($this->service)()->status()->validUntil)->toBe('2026-11-01')->and(Licence::count())->toBe(2);
    });

    it('keeps installed licences permanent: the database refuses changes and deletions', function () {
        $licence = ($this->service)()->install(($this->issue)());
        foreach (["UPDATE licences SET valid_until = '2099-01-01' WHERE id = {$licence->id}", "DELETE FROM licences WHERE id = {$licence->id}"] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                test()->fail('Expected the database to refuse '.$sql);
            } catch (QueryException $e) {
                expect($e->getMessage())->toContain('history');
            }
        }
    });
});

describe('where today stands', function () {
    it('walks through active, grace, read-only and locked day by day', function () {
        ($this->service)()->install(($this->issue)(['valid_until' => '2026-12-31']));
        $state = function (string $date) {
            $this->travelTo(CarbonImmutable::parse($date.' 10:00', 'Asia/Colombo'));
            $s = app(LicenceService::class)->status();

            return [$s->state, $s->daysLeft, $s->daysOver];
        };

        expect($state('2026-12-01'))->toBe(['active', 30, 0])->and($state('2026-12-31'))->toBe(['active', 0, 0])
            ->and($state('2027-01-01'))->toBe(['grace', 0, 1])->and($state('2027-01-07'))->toBe(['grace', 0, 7])
            ->and($state('2027-01-08'))->toBe(['readonly', 0, 8])->and($state('2027-01-21'))->toBe(['readonly', 0, 21])
            ->and($state('2027-01-22'))->toBe(['locked', 0, 22]);
        $s = app(LicenceService::class)->status();
        expect($s->readonlyFrom)->toBe('2027-01-08')->and($s->lockedFrom)->toBe('2027-01-22');
    });

    it('gives a new installation a free trial with the same phases after it', function () {
        $s = ($this->service)()->status();
        expect([$s->state, $s->source, $s->daysLeft, $s->validUntil])->toBe(['active', 'trial', 30, '2026-12-10']);

        $this->travelTo(CarbonImmutable::parse('2026-12-11 10:00', 'Asia/Colombo'));
        expect(app(LicenceService::class)->status()->state)->toBe('grace');
        $this->travelTo(CarbonImmutable::parse('2027-01-20 10:00', 'Asia/Colombo'));
        expect(app(LicenceService::class)->status()->state)->toBe('locked');
    });

    it('ignores a licence that has not started yet while another is running', function () {
        ($this->service)()->install(($this->issue)(['valid_until' => '2026-11-30', 'issued_at' => now()->subHour()->toIso8601String()]));
        ($this->service)()->install(($this->issue)(['valid_from' => '2026-12-01', 'valid_until' => '2027-12-31', 'issued_at' => now()->toIso8601String()]));
        expect(($this->service)()->status()->validUntil)->toBe('2026-11-30');

        $this->travelTo(CarbonImmutable::parse('2026-12-02 10:00', 'Asia/Colombo'));
        expect(app(LicenceService::class)->status()->validUntil)->toBe('2027-12-31');
    });
});

describe('enforcement on the screens and the API', function () {
    beforeEach(function () {
        ($this->service)()->install(($this->issue)(['valid_until' => '2026-12-31']));
        $this->at = function (string $date) {
            $this->travelTo(CarbonImmutable::parse($date.' 10:00', 'Asia/Colombo'));
        };
        $this->branch = ['code' => 'HQ', 'name' => 'Head office'];
    });

    it('works as normal while running and in the grace period, with a warning only when it matters', function () {
        $this->actingAs($this->manager)->get('/admin/licence')->assertOk();
        ($this->at)('2026-12-10');
        $this->actingAs($this->manager)->get('/admin/licence')->assertOk()->assertDontSee('role="alert"', false);
        ($this->at)('2026-12-28');
        $this->actingAs($this->manager)->get('/admin/licence')->assertOk()->assertSee('subscription ends in 3 day(s)');
        ($this->at)('2027-01-03');
        $this->actingAs($this->manager)->post('/admin/appearance/reset')->assertForbidden();   // permission, not licence
        $this->actingAs($this->manager)->get('/admin/licence')->assertSee('ended on 2026-12-31')->assertSee('5 more day(s)');
        asApi($this->manager)->getJson('/api/v1/licence')->assertOk()->assertJsonPath('data.state', 'grace');
    });

    it('is read-only: looking and printing work, anything that changes data is refused', function () {
        ($this->at)('2027-01-10');
        $user = userWithPermissions('licensing.manage', 'core.appearance.manage', 'core.branch.view', 'core.branch.create');

        $this->actingAs($user)->get('/admin/appearance')->assertOk()->assertSee('read-only');
        $this->actingAs($user)->from('/admin/appearance')->put('/admin/appearance', ['primary' => 'rose'])->assertRedirect('/admin/appearance')->assertSessionHasErrors('licence');
        expect(app(Appearance::class)->themeCss())->toContain('#4f46e5');

        asApi($user)->getJson('/api/v1/branches')->assertOk();
        asApi($user)->postJson('/api/v1/branches', $this->branch, idemKey())->assertStatus(402)->assertJsonPath('error.code', 'licensing.read_only');
        asApi($user)->patchJson('/api/v1/appearance', ['primary' => 'rose'])->assertStatus(402);
        asApi($user)->getJson('/api/v1/licence')->assertOk()->assertJsonPath('data.state', 'readonly');
    });

    it('is locked: only the licence screen, signing in and out remain; a new licence unlocks at once', function () {
        ($this->at)('2027-02-01');
        $user = userWithPermissions('licensing.manage', 'core.branch.view');

        $this->actingAs($user)->get('/')->assertRedirect('/admin/licence');
        $this->actingAs($user)->get('/admin/licence')->assertOk()->assertSee('Locked');
        asApi($user)->getJson('/api/v1/branches')->assertStatus(402)->assertJsonPath('error.code', 'licensing.locked');
        $this->actingAs($user)->post('/logout')->assertRedirect();

        $renewal = ($this->issue)(['valid_from' => '2027-02-01', 'valid_until' => '2027-03-31', 'issued_at' => now()->toIso8601String()]);
        $this->actingAs($user)->post('/admin/licence', ['licence' => $renewal])->assertRedirect('/admin/licence')->assertSessionHas('status');
        $this->actingAs($user)->get('/')->assertOk();
        asApi($user)->getJson('/api/v1/branches')->assertOk();
    });

    it('still lets people reach the sign-in page when locked', function () {
        ($this->at)('2027-02-01');
        $this->get('/login')->assertOk();
        $this->get('/branding/logo')->assertNotFound();   // reachable (no logo set), not redirected to the licence screen
    });

    it('restricts nothing when checking is switched off', function () {
        config(['licensing.enforce' => false]);
        ($this->at)('2027-06-01');
        $user = userWithPermissions('core.branch.view');
        $this->actingAs($user)->get('/')->assertOk()->assertDontSee('role="alert"', false);
        asApi($user)->getJson('/api/v1/branches')->assertOk();
        expect(app(LicenceGate::class)->banner())->toBeNull();
    });

    it('is off by default outside production, on in production', function () {
        config(['licensing.enforce' => null]);
        expect(app(LicenceService::class)->enforcing())->toBeFalse();
        app()->detectEnvironment(fn () => 'production');
        expect(app(LicenceService::class)->enforcing())->toBeTrue();
    });
});

describe('the plan', function () {
    it('limits the modules a licence includes', function () {
        ($this->service)()->install(($this->issue)(['modules' => ['masterdata', 'sales']]));
        $user = userWithPermissions('sales.view', 'procurement.view');

        $this->actingAs($user)->get('/sales/orders')->assertOk();
        $this->actingAs($user)->get('/procurement/orders')->assertForbidden();
        asApi($user)->getJson('/api/v1/purchase-orders')->assertForbidden()->assertJsonPath('error.code', 'core.module_not_licensed');
        expect(app(LicenceGate::class)->allowsModule('core'))->toBeTrue()->and(app(LicenceGate::class)->allowsModule('sales'))->toBeTrue()->and(app(LicenceGate::class)->allowsModule('finance'))->toBeFalse();
    });

    it('includes every module when the licence names none', function () {
        ($this->service)()->install(($this->issue)());
        $this->actingAs(userWithPermissions('procurement.view'))->get('/procurement/orders')->assertOk();
    });
});

describe('the licence screen and API', function () {
    it('shows the installation ID and status to anyone signed in, but only managers can install', function () {
        $reader = userWithPermissions('core.branch.view');
        $this->actingAs($reader)->get('/admin/licence')->assertOk()->assertSee($this->installationId)->assertDontSee('Install licence');
        $this->actingAs($reader)->post('/admin/licence', ['licence' => ($this->issue)()])->assertForbidden();
        asApi($reader)->postJson('/api/v1/licence', ['licence' => ($this->issue)()])->assertForbidden();

        $this->actingAs($this->manager)->get('/admin/licence')->assertOk()->assertSee('Install licence');
        $this->actingAs($this->manager)->from('/admin/licence')->post('/admin/licence', ['licence' => 'rubbish'])->assertRedirect('/admin/licence')->assertSessionHasErrors('licence');
        asApi($this->manager)->postJson('/api/v1/licence', ['licence' => ($this->issue)(['plan' => 'gold'])], idemKey())->assertCreated()->assertJsonPath('data.plan', 'gold')->assertJsonPath('data.installation_id', $this->installationId);
        asApi($this->manager)->postJson('/api/v1/licence', ['licence' => ($this->issue)(['installation' => 'inst_other'])])->assertStatus(422)->assertJsonPath('error.code', 'licensing.wrong_installation');
    });

    it('is open to the admin role by default', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        expect(Role::findByName('admin', 'web')->permissions->pluck('name'))->toContain('licensing.manage');
    });

    it('opens in every language without raw translation keys', function (string $locale) {
        ($this->service)()->install(($this->issue)());
        $this->manager->forceFill(['locale' => $locale])->save();
        $body = $this->actingAs($this->manager->fresh())->get('/admin/licence')->assertOk()->getContent();
        expect($body)->not->toContain('licensing::')->not->toContain('licensing.ui.');
    })->with(['en', 'si', 'ta']);

    it('has identical translation keys in every language', function () {
        foreach (['ui', 'notifications'] as $file) {
            $keys = fn (string $locale) => collect(array_keys(Arr::dot(require app_path("Modules/Licensing/Lang/{$locale}/{$file}.php"))))->sort()->values()->all();
            foreach (['si', 'ta'] as $locale) {
                expect($keys($locale))->toBe($keys('en'), "{$file}: {$locale} differs from en");
            }
        }
    });
});

describe('the vendor commands and the daily check', function () {
    it('makes keys, issues a licence from a key file and the licence installs', function () {
        Artisan::call('licence:keys');
        $out = Artisan::output();
        expect($out)->toContain('LICENCE_PUBLIC_KEY')->toContain('LICENCE_PRIVATE_KEY');

        $file = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($file, $this->secret);
        try {
            expect(Artisan::call('licence:issue', ['installation' => $this->installationId, '--company' => 'Acme Trading', '--months' => 1, '--users' => 10, '--modules' => 'sales,finance', '--key-file' => $file]))->toBe(0);
            $token = collect(explode("\n", trim(Artisan::output())))->first(fn ($l) => str_starts_with(trim($l), 'DIO1.'));
            $licence = ($this->service)()->install(trim($token));
            expect($licence->valid_from->toDateString())->toBe('2026-11-10')->and($licence->valid_until->toDateString())->toBe('2026-12-09')->and($licence->max_users)->toBe(10)->and($licence->modules)->toBe(['sales', 'finance']);

            expect(Artisan::call('licence:issue', ['installation' => $this->installationId, '--key-file' => $file]))->toBe(1);   // no end date
            expect(Artisan::call('licence:issue', ['installation' => $this->installationId, '--until' => '2026-01-01', '--from' => '2026-06-01', '--key-file' => $file]))->toBe(1);
        } finally {
            @unlink($file);
        }
        expect(Artisan::call('licence:issue', ['installation' => $this->installationId, '--months' => 1, '--key-file' => '/nonexistent']))->toBe(1);   // no key
    });

    it('tells the administrators once when the end is near and when each stricter phase begins', function () {
        $admin = userWithRole('admin');
        ($this->service)()->install(($this->issue)(['valid_until' => '2026-12-31']));
        $count = fn () => DB::table('notifications')->where('notifiable_id', $admin->id)->count();

        $this->travelTo(CarbonImmutable::parse('2026-12-10 10:00', 'Asia/Colombo'));
        Artisan::call('licence:check');
        expect($count())->toBe(0);   // far from the end

        $this->travelTo(CarbonImmutable::parse('2026-12-24 10:00', 'Asia/Colombo'));
        Artisan::call('licence:check');
        Artisan::call('licence:check');
        expect($count())->toBe(1);   // 7 days left: told once, not twice

        $this->travelTo(CarbonImmutable::parse('2027-01-02 10:00', 'Asia/Colombo'));
        Artisan::call('licence:check');
        $this->travelTo(CarbonImmutable::parse('2027-01-09 10:00', 'Asia/Colombo'));
        Artisan::call('licence:check');
        $this->travelTo(CarbonImmutable::parse('2027-01-23 10:00', 'Asia/Colombo'));
        Artisan::call('licence:check');
        expect($count())->toBe(4);   // grace, read-only, locked
    });

    it('does nothing where licensing is not enforced', function () {
        config(['licensing.enforce' => false]);
        userWithRole('admin');
        $this->travelTo(CarbonImmutable::parse('2027-06-01 10:00', 'Asia/Colombo'));
        Artisan::call('licence:check');
        expect(DB::table('notifications')->count())->toBe(0);
    });
});
