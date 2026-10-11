<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\DTOs\MenuItem;
use App\Modules\Core\DTOs\ModuleDefinition;
use App\Modules\Core\Exceptions\ModuleException;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\ConfiguredModuleRegistry;
use Illuminate\Support\Facades\Route;

/** Core plus a small fake chain: inventory -> core, sales -> inventory, finance -> core (not default-enabled). */
function fakeRegistry(): ConfiguredModuleRegistry
{
    $registry = new ConfiguredModuleRegistry([
        'core' => new ModuleDefinition('core', 'Core', 'x', [], true, true),
        'inventory' => new ModuleDefinition('inventory', 'Inventory', 'x', ['core']),
        'sales' => new ModuleDefinition('sales', 'Sales', 'x', ['inventory']),
        'finance' => new ModuleDefinition('finance', 'Finance', 'x', ['core'], defaultEnabled: false),
    ], app(AuditLogger::class));
    app()->instance(ModuleRegistry::class, $registry);

    return $registry;
}

it('reports defaults, with required modules always on', function () {
    $registry = fakeRegistry();

    expect($registry->isEnabled('core'))->toBeTrue()
        ->and($registry->isEnabled('sales'))->toBeTrue()
        ->and($registry->isEnabled('finance'))->toBeFalse()
        ->and($registry->isEnabled('unknown'))->toBeFalse();
});

it('persists a toggle and audits it', function () {
    $registry = fakeRegistry();

    $registry->enable('finance');
    expect(fakeRegistry()->isEnabled('finance'))->toBeTrue(); // a brand new instance reads it from the database

    $registry->disable('finance');
    expect(AuditLog::whereIn('event', ['module.enabled', 'module.disabled'])->orderBy('id')->pluck('event')->all())->toBe(['module.enabled', 'module.disabled']);
});

it('cannot disable a required module', function () {
    expect(fn () => fakeRegistry()->disable('core'))->toThrow(ModuleException::class, 'cannot be disabled');
});

it('cannot disable a module while an enabled module depends on it', function () {
    $registry = fakeRegistry();

    expect(fn () => $registry->disable('inventory'))->toThrow(ModuleException::class, 'still required by sales');

    $registry->disable('sales');
    $registry->disable('inventory');
    expect($registry->isEnabled('inventory'))->toBeFalse();
});

it('cannot enable a module whose dependency is disabled', function () {
    $registry = fakeRegistry();
    $registry->disable('sales');
    $registry->disable('inventory');

    expect(fn () => $registry->enable('sales'))->toThrow(ModuleException::class, 'requires inventory');

    $registry->enable('inventory');
    $registry->enable('sales');
    expect($registry->isEnabled('sales'))->toBeTrue();
});

it('rejects unknown modules', function () {
    expect(fn () => fakeRegistry()->enable('nope'))->toThrow(ModuleException::class)
        ->and(fn () => fakeRegistry()->assertEnabled('nope'))->toThrow(ModuleException::class);
});

it('blocks routes of a disabled module and lets them through when enabled', function () {
    $registry = fakeRegistry();
    Route::middleware(['api', 'module:finance'])->get('/api/v1/_finance-probe', fn () => ['ok' => true]);
    Route::middleware(['web', 'module:finance'])->get('/_finance-probe', fn () => 'ok');

    $this->getJson('/api/v1/_finance-probe')->assertForbidden()->assertJsonPath('error.code', 'core.module_disabled');
    $this->get('/_finance-probe')->assertForbidden();

    $registry->enable('finance');
    $this->getJson('/api/v1/_finance-probe')->assertOk();
    $this->get('/_finance-probe')->assertOk();
});

it('hides menu items and permissions of disabled modules', function () {
    $registry = fakeRegistry();
    $registry->declarePermissions('core', ['core.a']);
    $registry->declarePermissions('finance', ['finance.b']);
    $registry->declareMenu('core', [new MenuItem('k.home', 'dashboard', null, 'home', 1)]);
    $registry->declareMenu('finance', [new MenuItem('k.fin', 'dashboard', 'finance.b', 'home', 2)]);
    $user = userWithPermissions('finance.b');

    expect($registry->permissions())->toBe(['core.a'])
        ->and($registry->permissions(onlyEnabled: false))->toBe(['core.a', 'finance.b'])
        ->and(array_map(fn ($i) => $i->labelKey, $registry->menuFor($user)))->toBe(['k.home']);

    $registry->enable('finance');
    expect(array_map(fn ($i) => $i->labelKey, $registry->menuFor($user)))->toBe(['k.home', 'k.fin']);
    expect($registry->menuFor(userWithPermissions('other')))->toHaveCount(1) // lacks finance.b
        ->and($registry->menuFor(null))->toBe([]);
});

it('manages modules from the admin page', function () {
    $registry = fakeRegistry();
    $admin = adminUser();

    $this->actingAs($admin)->get('/admin/modules')->assertOk()->assertSee('Inventory');

    $this->actingAs($admin)->post('/admin/modules/finance/enable')->assertRedirect()->assertSessionHas('status');
    expect(fakeRegistry()->isEnabled('finance'))->toBeTrue();

    $this->actingAs($admin)->post('/admin/modules/core/disable')->assertSessionHasErrors('module');
    $this->actingAs($admin)->post('/admin/modules/inventory/disable')->assertSessionHasErrors('module'); // sales depends on it

    $this->actingAs(userWithPermissions('core.branch.view'))->get('/admin/modules')->assertForbidden();
    $this->actingAs(userWithPermissions('core.branch.view'))->post('/admin/modules/finance/disable')->assertForbidden();
});

it('registers the shipped modules, each below the ones that use it', function () {
    $keys = array_keys(config('modules.modules'));

    expect($keys)->toBe(['core', 'approvals', 'masterdata', 'inventory']);
});
