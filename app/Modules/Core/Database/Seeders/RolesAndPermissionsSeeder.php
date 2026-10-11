<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\CoreServiceProvider;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs permissions declared by modules and the base roles. Safe to run repeatedly
 * (e.g. after a module is added). Roles for business functions are added with their modules.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(ModuleRegistry $modules): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = $modules->permissions(onlyEnabled: false);
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // super-admin bypasses gates (Gate::before) and therefore holds no explicit permissions.
        Role::findOrCreate(CoreServiceProvider::SUPER_ADMIN_ROLE, 'web');
        Role::findOrCreate('admin', 'web')->syncPermissions($names);
        Role::findOrCreate('user', 'web');

        // The accountant runs Finance (every finance.* permission, including locking a period) and can read what Finance
        // reports on: master data, stock and warehouses. It cannot change them. Permissions that do not exist yet are skipped.
        $readable = ['masterdata.reference.view', 'masterdata.item.view', 'masterdata.customer.view', 'masterdata.supplier.view', 'masterdata.price_list.view', 'inventory.stock.view', 'inventory.warehouse.view'];
        Role::findOrCreate('accountant', 'web')->syncPermissions(array_values(array_filter(
            $names,
            fn (string $name) => str_starts_with($name, 'finance.') || in_array($name, $readable, true),
        )));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
