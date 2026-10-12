<?php

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\DTOs\MenuItem;
use Illuminate\Support\Facades\Lang;
use Spatie\Permission\Models\Permission;

/** The menu text between the headings, in the order it appears. */
function sidebar(string $html): string
{
    preg_match('/<nav [^>]*aria-label="Main".*?<\/nav>/s', $html, $m);

    return $m[0] ?? '';
}

it('groups the menu under headings, in a fixed order, with Dashboard first and no heading of its own', function () {
    $user = userWithPermissions('masterdata.item.view', 'sales.view', 'procurement.view', 'inventory.stock.view', 'finance.view', 'core.company.manage');
    $nav = sidebar($this->actingAs($user)->get('/')->assertOk()->getContent());

    $positions = [];
    foreach (['Dashboard', 'Sales', 'Purchasing', 'Inventory', 'Finance', 'Master data', 'Administration'] as $label) {
        $positions[$label] = strpos($nav, '>'.$label.'<');
        expect($positions[$label])->not->toBeFalse("missing {$label}");
    }
    expect(array_values($positions))->toBe(collect($positions)->sort()->values()->all());

    // an item sits under its own heading, not another one
    expect(strpos($nav, 'Company profile'))->toBeGreaterThan($positions['Administration']);
    expect(strpos($nav, 'Quotations'))->toBeGreaterThan($positions['Sales'])->toBeLessThan($positions['Purchasing']);
});

it('shows no heading for a section the person has nothing in', function () {
    $nav = sidebar($this->actingAs(userWithPermissions('sales.view'))->get('/')->assertOk()->getContent());

    expect($nav)->toContain('>Sales<')->and($nav)->not->toContain('>Finance<')->and($nav)->not->toContain('>Inventory<')->and($nav)->not->toContain('>Administration<');
});

it('keeps the section of the page you are on open, and offers a search box', function () {
    $user = userWithPermissions('sales.view', 'finance.view');
    $html = $this->actingAs($user)->get('/sales/quotations')->assertOk()->getContent();
    $nav = sidebar($html);

    expect($nav)->toContain("active: 'sales'")->and($nav)->toContain('init() { if (this.active)')->and($nav)->not->toContain('if (g === this.active) { return; }')->and($nav)->toContain('id="nav-search"')->and($nav)->toContain('aria-current="page"');
    // the menu can scroll when it is longer than the screen
    expect($nav)->toContain('overflow-y-auto');
});

it('has the group headings and search texts in all three languages', function () {
    foreach (['en', 'si', 'ta'] as $locale) {
        foreach (['approvals', 'sales', 'purchasing', 'inventory', 'manufacturing', 'finance', 'masterdata', 'admin', 'find', 'none'] as $key) {
            expect(Lang::get("core::ui.menu_groups.{$key}", [], $locale))->not->toBe("core::ui.menu_groups.{$key}");
        }
    }
});

it('puts every menu item in a known section', function () {
    $known = ['main', 'approvals', 'sales', 'purchasing', 'inventory', 'manufacturing', 'finance', 'masterdata', 'admin'];
    $user = userWithPermissions(...array_keys(array_flip(Permission::pluck('name')->all())));
    $menu = app(ModuleRegistry::class)->menuFor($user);

    expect($menu)->not->toBeEmpty();
    foreach ($menu as $item) {
        expect($known)->toContain($item->group);
    }
    expect((new MenuItem('k', 'dashboard'))->group)->toBe('main');
    expect((new MenuItem('k', 'dashboard', null, 'home', 1, group: 'sales'))->forModule('sales')->group)->toBe('sales');
});
