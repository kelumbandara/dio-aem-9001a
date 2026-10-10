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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
