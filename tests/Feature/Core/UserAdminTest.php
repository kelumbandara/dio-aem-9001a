<?php

use App\Modules\Core\Actions\AuthenticateUser;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Core\Models\MfaSecret;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PasswordPolicy;
use App\Support\Exceptions\BusinessException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Notification::fake();
    config(['core.auth.check_breached' => false]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = adminUser('admin');
    $this->super = adminUser('super-admin');
});

describe('the password rule', function () {
    $check = fn (string $pw, ?string $email = null) => Validator::make(['p' => $pw], ['p' => app(PasswordPolicy::class)->rules($email)])->passes();

    it('wants 12 or more characters with upper case, lower case and a digit', function () use ($check) {
        expect($check('Short1a'))->toBeFalse()->and($check('alllowercase123'))->toBeFalse()->and($check('ALLUPPERCASE123'))->toBeFalse()
            ->and($check('NoDigitsInHereAtAll'))->toBeFalse()->and($check('Correct-Horse-42'))->toBeTrue()->and($check(str_repeat('a', 201)))->toBeFalse();
    });

    it('refuses a password that contains the person\'s e-mail name', function () use ($check) {
        expect($check('Kamal-Perera-2026', 'kamal.perera@dio.test'))->toBeTrue()->and($check('xxkamal.pereraXX1', 'kamal.perera@dio.test'))->toBeFalse()
            ->and($check('Abc-123-Abc-123', 'ab@dio.test'))->toBeTrue();       // very short names are not compared
    });

    it('can refuse passwords found in known breaches when switched on (the check is on in production)', function () {
        config(['core.auth.check_breached' => true]);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr(strtoupper(sha1('Password-12345')), 5).":9999\r\n", 200)]);
        expect(Validator::make(['p' => 'Password-12345'], ['p' => app(PasswordPolicy::class)->rules()])->passes())->toBeFalse();
        expect(config('core.auth.check_breached'))->toBeTrue();
    });
});

describe('administering users', function () {
    it('lets an administrator add a person, who is mailed a link and has no usable password yet', function () {
        $this->actingAs($this->admin)->post('/admin/users', ['name' => 'Nimal Silva', 'email' => 'Nimal@Dio.Test', 'locale' => 'si', 'roles' => ['sales']])->assertRedirect();
        $new = User::where('email', 'nimal@dio.test')->firstOrFail();
        expect($new->hasRole('sales'))->toBeTrue()->and($new->is_active)->toBeTrue()->and($new->user_type)->toBe('internal')->and($new->locale)->toBe('si');
        Notification::assertSentTo($new, ResetPassword::class);
        expect(fn () => app(AuthenticateUser::class)('nimal@dio.test', ''))->toThrow(BusinessException::class);
        expect(DB::table('audit_logs')->where('event', 'user.roles_changed')->exists())->toBeTrue();
    });

    it('refuses a duplicate e-mail, a bad language and unknown roles', function () {
        $this->actingAs($this->admin)->post('/admin/users', ['name' => 'X', 'email' => $this->admin->email])->assertSessionHasErrors('email');
        $this->actingAs($this->admin)->post('/admin/users', ['name' => 'X', 'email' => 'x@dio.test', 'locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->actingAs($this->admin)->post('/admin/users', ['name' => 'X', 'email' => 'x@dio.test', 'roles' => ['no-such-role']])->assertSessionHasErrors('roles');
        expect(User::where('email', 'x@dio.test')->exists())->toBeFalse();
    });

    it('does not let an administrator hand out more than they hold: no super-admin, nothing beyond their own permissions', function () {
        $limited = userWithPermissions('core.user.view', 'core.user.manage', 'core.branch.view');
        $this->actingAs($limited)->post('/admin/users', ['name' => 'A', 'email' => 'a@dio.test', 'roles' => ['super-admin']])->assertSessionHasErrors('roles');
        $this->actingAs($limited)->post('/admin/users', ['name' => 'A', 'email' => 'a@dio.test', 'roles' => ['accountant']])->assertSessionHasErrors('roles');
        $this->actingAs($this->admin)->post('/admin/users', ['name' => 'A', 'email' => 'a@dio.test', 'roles' => ['super-admin']])->assertSessionHasErrors('roles');
        expect(User::where('email', 'a@dio.test')->exists())->toBeFalse();
        $this->actingAs($this->super)->post('/admin/users', ['name' => 'A', 'email' => 'a@dio.test', 'roles' => ['super-admin']])->assertRedirect();
        expect(User::where('email', 'a@dio.test')->first()->hasRole('super-admin'))->toBeTrue();
    });

    it('does not offer roles the person could not give', function () {
        $limited = userWithPermissions('core.user.view', 'core.user.manage', 'core.branch.view');
        $this->actingAs($limited)->get('/admin/users/create')->assertOk()->assertDontSee('value="super-admin"', false)->assertDontSee('value="accountant"', false);
        $this->actingAs($this->super)->get('/admin/users/create')->assertOk()->assertSee('value="super-admin"', false);
    });

    it('protects a super-admin from an administrator, and everyone from changing their own roles or switching themselves off', function () {
        $this->actingAs($this->admin)->put("/admin/users/{$this->super->public_id}", ['name' => 'Renamed', 'email' => $this->super->email, 'roles' => []])->assertSessionHasErrors('form');
        expect($this->super->refresh()->name)->not->toBe('Renamed');
        $this->actingAs($this->admin)->post("/admin/users/{$this->super->public_id}/reset-mfa")->assertSessionHasErrors('form');

        $this->actingAs($this->admin)->put("/admin/users/{$this->admin->public_id}", ['name' => $this->admin->name, 'email' => $this->admin->email, 'roles' => ['super-admin']])->assertSessionHasErrors('form');
        $this->actingAs($this->admin)->put("/admin/users/{$this->admin->public_id}", ['name' => $this->admin->name, 'email' => $this->admin->email, 'roles' => ['admin']])->assertSessionHasErrors('form');
        expect($this->admin->refresh()->hasRole('admin'))->toBeTrue()->and($this->admin->is_active)->toBeTrue();
    });

    it('switching a person off ends their API tokens and browser sessions at once', function () {
        $worker = userWithRole('sales');
        $worker->createToken('phone');
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $worker->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
        config(['session.driver' => 'database']);

        $this->actingAs($this->admin)->put("/admin/users/{$worker->public_id}", ['name' => $worker->name, 'email' => $worker->email, 'roles' => ['sales']])->assertRedirect();   // is_active left out = off
        expect($worker->refresh()->is_active)->toBeFalse()->and($worker->tokens()->count())->toBe(0)->and(DB::table('sessions')->where('user_id', $worker->id)->count())->toBe(0);
        expect(DB::table('audit_logs')->where('event', 'user.deactivated')->exists())->toBeTrue();
    });

    it('needs the user permissions: viewing is not changing', function () {
        $viewer = userWithPermissions('core.user.view');
        $worker = userWithRole('sales');
        $this->actingAs($viewer)->get('/admin/users')->assertOk()->assertSee($worker->email);
        $this->actingAs($viewer)->get("/admin/users/{$worker->public_id}")->assertOk();
        $this->actingAs($viewer)->post('/admin/users', ['name' => 'A', 'email' => 'a@dio.test'])->assertForbidden();
        $this->actingAs($viewer)->put("/admin/users/{$worker->public_id}", ['name' => 'A', 'email' => 'a@dio.test'])->assertForbidden();
        $this->actingAs($viewer)->post("/admin/users/{$worker->public_id}/password-link")->assertForbidden();
        $this->actingAs($viewer)->post("/admin/users/{$worker->public_id}/reset-mfa")->assertForbidden();
        $this->actingAs(userWithPermissions('core.branch.view'))->get('/admin/users')->assertForbidden();
    });

    it('resets a lost authenticator: removed, sign-ins ended, audited', function () {
        $worker = userWithRole('sales');
        MfaSecret::create(['user_id' => $worker->id, 'secret' => 'JBSWY3DPEHPK3PXP', 'recovery_codes' => [], 'confirmed_at' => now()]);
        $worker->createToken('phone');
        $this->actingAs($this->admin)->post("/admin/users/{$worker->public_id}/reset-mfa")->assertRedirect();
        expect(MfaSecret::where('user_id', $worker->id)->exists())->toBeFalse()->and($worker->tokens()->count())->toBe(0)
            ->and(DB::table('audit_logs')->where('event', 'user.mfa_reset')->exists())->toBeTrue();
    });

    it('lists people by name or e-mail and shows only internal users', function () {
        userWithRole('sales', ['name' => 'Chaminda Fernando', 'email' => 'chaminda@dio.test']);
        User::factory()->create(['name' => 'Customer Portal', 'email' => 'portal@x.test', 'user_type' => 'customer']);
        $this->actingAs($this->admin)->get('/admin/users?q=chamin')->assertSee('chaminda@dio.test')->assertDontSee($this->admin->email);
        $this->actingAs($this->admin)->get('/admin/users')->assertDontSee('portal@x.test');
        $customer = User::where('email', 'portal@x.test')->first();
        $this->actingAs($this->admin)->get("/admin/users/{$customer->public_id}")->assertNotFound();
    });
});

describe('forgot password and the mailed link', function () {
    it('answers the same whether or not the address has an account, and mails only real active accounts', function () {
        $active = userWithRole('sales');
        $off = userWithRole('sales', ['is_active' => false]);
        $external = User::factory()->create(['user_type' => 'customer']);
        $answers = [];
        foreach ([$active->email, 'nobody@dio.test', $off->email, $external->email] as $email) {
            $answers[] = $this->post('/forgot-password', ['email' => $email])->assertSessionHas('status')->getSession()->get('status');
        }
        expect(array_unique($answers))->toHaveCount(1);
        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($off, ResetPassword::class);
        Notification::assertNotSentTo($external, ResetPassword::class);
        Notification::assertCount(1);
    });

    it('sets a new password from the link once, applies the rules, and ends old sign-ins', function () {
        $worker = userWithRole('sales', ['password' => 'Old-Password-123']);
        $worker->createToken('phone');
        $token = Password::broker()->createToken($worker);

        $this->post('/reset-password', ['token' => $token, 'email' => $worker->email, 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => $worker->email, 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => 'wrong-token', 'email' => $worker->email, 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77'])->assertSessionHasErrors('email');
        expect(Hash::check('Old-Password-123', $worker->refresh()->password))->toBeTrue();

        $this->post('/reset-password', ['token' => $token, 'email' => $worker->email, 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77'])->assertRedirect('/login');
        expect(Hash::check('Brand-New-Pass-77', $worker->refresh()->password))->toBeTrue()->and($worker->tokens()->count())->toBe(0);

        $this->post('/reset-password', ['token' => $token, 'email' => $worker->email, 'password' => 'Another-New-Pass-88', 'password_confirmation' => 'Another-New-Pass-88'])->assertSessionHasErrors('email');   // once only
        expect(Hash::check('Brand-New-Pass-77', $worker->refresh()->password))->toBeTrue();
    });

    it('lets a link run out', function () {
        $worker = userWithRole('sales');
        $token = Password::broker()->createToken($worker);
        $this->travel(181)->minutes();
        $this->post('/reset-password', ['token' => $token, 'email' => $worker->email, 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77'])->assertSessionHasErrors('email');
    });

    it('is throttled', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => 'a@dio.test']);
        }
        $this->post('/forgot-password', ['email' => 'a@dio.test'])->assertStatus(429);
    });
});

describe('changing your own password', function () {
    it('needs the current password and a password that follows the rule', function () {
        $me = userWithRole('sales', ['password' => 'Old-Password-123']);
        $url = '/account/password';
        $this->actingAs($me)->get($url)->assertOk();
        $this->actingAs($me)->put($url, ['current_password' => 'nope', 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77'])->assertSessionHasErrors('current_password');
        $this->actingAs($me)->put($url, ['current_password' => 'Old-Password-123', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->actingAs($me)->put($url, ['current_password' => 'Old-Password-123', 'password' => 'Old-Password-123', 'password_confirmation' => 'Old-Password-123'])->assertSessionHasErrors('password');
        expect(Hash::check('Old-Password-123', $me->refresh()->password))->toBeTrue();
    });

    it('changes it, signs out the phones and other browsers but not this one, and records it', function () {
        $me = userWithRole('sales', ['password' => 'Old-Password-123']);
        $me->createToken('phone');
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'other-browser', 'user_id' => $me->id, 'ip_address' => '1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAs($me)->put('/account/password', ['current_password' => 'Old-Password-123', 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77'])->assertRedirect('/account/password');
        expect(Hash::check('Brand-New-Pass-77', $me->refresh()->password))->toBeTrue()->and($me->tokens()->count())->toBe(0)
            ->and(DB::table('sessions')->where('id', 'other-browser')->exists())->toBeFalse()
            ->and(DB::table('audit_logs')->where('event', 'user.password_changed')->exists())->toBeTrue();
    });

    it('keeps the new password out of the audit log', function () {
        $me = userWithRole('sales', ['password' => 'Old-Password-123']);
        $this->actingAs($me)->put('/account/password', ['current_password' => 'Old-Password-123', 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77']);
        expect(json_encode(DB::table('audit_logs')->get()))->not->toContain('Brand-New-Pass-77')->and(json_encode(DB::table('audit_logs')->get()))->not->toContain('Old-Password-123');
    });

    it('is not open without signing in', function () {
        $this->get('/account/password')->assertRedirect('/login');
        $this->put('/account/password', [])->assertRedirect('/login');
    });
});

it('has the new permissions for the administrator role and not for others', function () {
    expect(Role::findByName('admin')->hasPermissionTo('core.user.manage'))->toBeTrue()->and(Role::findByName('sales')->hasPermissionTo('core.user.manage'))->toBeFalse();
});

describe('the first administrator of a new installation', function () {
    it('is created without a password, and must choose one through a one-time link', function () {
        $this->artisan('app:create-admin', ['email' => 'Owner@Company.LK', 'name' => 'The Owner', '--print-link' => true])->assertSuccessful()->expectsOutputToContain('/reset-password/');
        $owner = User::where('email', 'owner@company.lk')->firstOrFail();
        expect($owner->hasRole('admin'))->toBeTrue()->and($owner->is_active)->toBeTrue()->and(DB::table('audit_logs')->where('event', 'user.created_by_console')->exists())->toBeTrue();
        expect(fn () => app(AuthenticateUser::class)('owner@company.lk', ''))->toThrow(BusinessException::class);

        // the link works once
        $token = Password::broker()->createToken($owner);
        $this->post('/reset-password', ['token' => $token, 'email' => $owner->email, 'password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77'])->assertRedirect('/login');
        expect(Hash::check('Brand-New-Pass-77', $owner->refresh()->password))->toBeTrue();
    });

    it('mails the link by default', function () {
        $this->artisan('app:create-admin', ['email' => 'owner@company.lk', 'name' => 'The Owner'])->assertSuccessful();
        Notification::assertSentTo(User::where('email', 'owner@company.lk')->first(), ResetPassword::class);
    });

    it('refuses a duplicate, a bad address and an unknown role, and creates nothing', function () {
        $this->artisan('app:create-admin', ['email' => $this->admin->email, 'name' => 'X'])->assertFailed();
        $this->artisan('app:create-admin', ['email' => 'not-an-email', 'name' => 'X'])->assertFailed();
        $this->artisan('app:create-admin', ['email' => 'x@company.lk', 'name' => 'X', '--role' => 'accountant'])->assertFailed();
        expect(User::where('email', 'x@company.lk')->exists())->toBeFalse();
    });
});
