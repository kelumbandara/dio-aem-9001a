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

        // The accountant runs Finance (every finance.* permission, including locking a period), reads what Finance reports on
        // (master data, stock, warehouses) and issues sales invoices and credit notes. It cannot change master data or stock. Permissions that do not exist yet are skipped.
        $readable = ['masterdata.reference.view', 'masterdata.item.view', 'masterdata.customer.view', 'masterdata.supplier.view', 'masterdata.price_list.view', 'inventory.stock.view', 'inventory.warehouse.view',
            // Sales: sees every document and issues invoices and credit notes; quotations, orders, deliveries and credit-limit overrides stay with sales staff and admins.
            'sales.view', 'sales.invoice.post', 'sales.credit_note.post'];
        $salesStaff = ['sales.view', 'sales.quotation.manage', 'sales.order.manage', 'sales.order.confirm', 'sales.delivery.post',
            'masterdata.reference.view', 'masterdata.item.view', 'masterdata.customer.view', 'masterdata.price_list.view', 'inventory.stock.view', 'inventory.warehouse.view'];
        Role::findOrCreate('accountant', 'web')->syncPermissions(array_values(array_filter(
            $names,
            fn (string $name) => str_starts_with($name, 'finance.') || in_array($name, $readable, true),
        )));

        // Sales staff quote, take and confirm orders and send goods out; they see customers, items, prices and stock but change none of
        // them, do not invoice (the accountant does), cannot reverse a delivery and cannot override a credit limit (admin only).
        Role::findOrCreate('sales', 'web')->syncPermissions(array_values(array_filter($names, fn (string $name) => in_array($name, $salesStaff, true))));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
