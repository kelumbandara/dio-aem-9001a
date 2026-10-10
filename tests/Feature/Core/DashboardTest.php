<?php

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;

it('shows real counts on the dashboard', function () {
    Branch::factory()->count(3)->create();
    User::factory()->count(2)->create();
    User::factory()->inactive()->create();

    $this->actingAs(adminUser())->get('/')
        ->assertOk()
        ->assertSee(__('core::ui.dashboard.users'))
        ->assertSeeInOrder([__('core::ui.dashboard.users'), '3'])       // 2 + the admin; inactive excluded
        ->assertSeeInOrder([__('core::ui.dashboard.branches'), '3']);
});

it('shows recent activity only to users who may view the audit log', function () {
    $this->actingAs(userWithPermissions('core.branch.view'))->get('/')
        ->assertOk()->assertDontSee(__('core::ui.dashboard.recent_activity'));

    $this->actingAs(userWithPermissions('core.audit.view'))->get('/')
        ->assertOk()->assertSee(__('core::ui.dashboard.recent_activity'));
});

it('builds the sidebar from permissions', function () {
    $this->actingAs(userWithPermissions('core.branch.view'))->get('/')
        ->assertOk()->assertSee(__('core::ui.menu.dashboard'))
        ->assertDontSee(route('modules.index'), false);

    $this->actingAs(adminUser())->get('/')->assertSee(route('modules.index'), false)->assertSee(route('audit-log.index'), false);
});

it('displays timestamps in Colombo time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-01 20:00:00', 'UTC')); // 01:30 on 2 May in Sri Lanka
    $admin = adminUser();

    $this->actingAs($admin)->get('/admin/audit-log')->assertOk()->assertSee('2026-05-02 01:30');
});

it('renders every module-provided page in all three languages', function (string $locale) {
    $admin = adminUser();
    $admin->update(['locale' => $locale]);

    foreach (['/', '/admin/modules', '/admin/audit-log'] as $path) {
        $this->actingAs($admin->fresh())->get($path)->assertOk()->assertSee(__('core::ui.app_name'));
    }
})->with(['en', 'si', 'ta']);

it('has the same translation keys in every language', function () {
    $keys = fn (string $locale, string $file) => collect(array_keys(Arr::dot(require app_path("Modules/Core/Lang/{$locale}/{$file}.php"))))->sort()->values()->all();

    foreach (['ui', 'auth'] as $file) {
        foreach (['si', 'ta'] as $locale) {
            expect($keys($locale, $file))->toBe($keys('en', $file), "{$locale}/{$file} keys differ from en");
        }
    }
});
