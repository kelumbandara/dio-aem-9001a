<?php

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Database\Seeders\DemoAdminSeeder;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

it('exposes liveness and readiness without details', function () {
    $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
    $this->getJson('/api/v1/health/ready')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('stamps every API response with a request id and the API version', function () {
    $this->getJson('/api/v1/health')->assertHeader('X-API-Version', '1')->assertHeader('X-Request-Id');

    $this->getJson('/api/v1/health', ['X-Request-Id' => 'client-supplied-1'])->assertHeader('X-Request-Id', 'client-supplied-1');
    $generated = $this->getJson('/api/v1/health', ['X-Request-Id' => "bad id with spaces\n"])->headers->get('X-Request-Id');
    expect($generated)->toMatch('/^[0-9a-f-]{36}$/');
});

it('puts the request id into error bodies too', function () {
    $response = $this->getJson('/api/v1/branches', ['X-Request-Id' => 'trace-abc-123'])->assertUnauthorized();

    expect($response->json('error.request_id'))->toBe('trace-abc-123');
});

it('never leaks internals on unexpected errors', function () {
    Route::middleware('api')->get('/api/v1/_boom', fn () => throw new RuntimeException('SQLSTATE secret table name'));

    $this->getJson('/api/v1/_boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server.error')
        ->assertJsonMissing(['message' => 'SQLSTATE secret table name']);
});

it('answers unknown API routes and wrong methods in the error format', function () {
    $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertJsonPath('error.code', 'resource.not_found');
    $this->putJson('/api/v1/health')->assertStatus(405)->assertJsonPath('error.code', 'http.method_not_allowed');
});

/** Reports the locale in effect while the request is handled (it is restored afterwards). */
function localeProbe(): void
{
    Route::middleware(['api', 'auth:sanctum'])->get('/api/v1/_locale', fn () => ['locale' => app()->getLocale()]);
}

it('picks the API locale from Accept-Language and ignores unsupported ones', function () {
    localeProbe();
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/_locale', ['Accept-Language' => 'si'])->assertJsonPath('locale', 'si');
    $this->getJson('/api/v1/_locale', ['Accept-Language' => 'fr'])->assertJsonPath('locale', 'en');
    $this->getJson('/api/v1/_locale', ['Accept-Language' => 'fr, ta;q=0.8'])->assertJsonPath('locale', 'ta');
});

it('uses the saved user locale for API calls without Accept-Language, and lets the header win', function () {
    localeProbe();
    Sanctum::actingAs(User::factory()->create(['locale' => 'ta']));

    // The test client adds a default "en-us" Accept-Language; real clients without a preference send none.
    $this->getJson('/api/v1/_locale', ['Accept-Language' => ''])->assertJsonPath('locale', 'ta');
    $this->getJson('/api/v1/_locale', ['Accept-Language' => 'fr'])->assertJsonPath('locale', 'ta'); // unsupported -> saved setting
    $this->getJson('/api/v1/_locale', ['Accept-Language' => 'si'])->assertJsonPath('locale', 'si');
});

it('returns the current user with roles and permissions', function () {
    Sanctum::actingAs(userWithPermissions('core.branch.view'));

    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.permissions', ['core.branch.view'])
        ->assertJsonPath('data.mfa_enabled', false);
});

it('lets a super admin through every permission check', function () {
    Sanctum::actingAs(adminUser('super-admin'));

    $this->getJson('/api/v1/branches')->assertOk();
});

it('gives the admin role every declared permission and the plain user role none', function () {
    $admin = Role::findByName('admin', 'web');
    $declared = app(ModuleRegistry::class)->permissions(onlyEnabled: false);

    expect($admin->permissions->pluck('name')->sort()->values()->all())->toBe(collect($declared)->sort()->values()->all())
        ->and(Role::findByName('user', 'web')->permissions)->toHaveCount(0);
});

it('has an idempotent seeder', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::count())->toBe(6);   // super-admin, admin, accountant, sales, purchasing, user
});

it('only seeds the demo admin in local or testing environments', function () {
    (new DemoAdminSeeder)->run();
    $demo = User::where('email', 'admin@dio.test')->first();
    expect($demo)->not->toBeNull()->and($demo->hasRole('super-admin'))->toBeTrue();

    $demo->forceDelete();
    $this->app['env'] = 'production';
    (new DemoAdminSeeder)->run();
    expect(User::where('email', 'admin@dio.test')->exists())->toBeFalse();
});

it('gives the accountant Finance in full, read access to what Finance reports on, and nothing else', function () {
    $granted = Role::findByName('accountant', 'web')->permissions->pluck('name');
    $finance = collect(app(ModuleRegistry::class)->permissions(onlyEnabled: false))->filter(fn ($p) => str_starts_with($p, 'finance.'));

    expect($finance->diff($granted)->all())->toBe([])
        ->and($granted->contains('finance.period.lock'))->toBeTrue()->and($granted->contains('inventory.stock.view'))->toBeTrue()->and($granted->contains('masterdata.customer.view'))->toBeTrue()
        ->and($granted->contains('inventory.adjust'))->toBeFalse()->and($granted->contains('masterdata.item.manage'))->toBeFalse()
        ->and(config('core.mfa.required_roles'))->toContain('accountant');
});
