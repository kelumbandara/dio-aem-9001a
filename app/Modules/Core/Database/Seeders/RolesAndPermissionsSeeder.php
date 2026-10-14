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
        // The vendor-only permissions (the "Powered by" line) and restoring a database copy are held by nobody: only the super-admin passes the gate.
        Role::findOrCreate('admin', 'web')->syncPermissions(array_values(array_diff($names, ['core.appearance.vendor', 'core.backup.restore'])));
        Role::findOrCreate('user', 'web');

        // The accountant runs Finance (every finance.* permission, including locking a period), reads what Finance reports on
        // (master data, stock, warehouses) and issues sales invoices and credit notes. It cannot change master data or stock. Permissions that do not exist yet are skipped.
        $readable = ['masterdata.reference.view', 'masterdata.item.view', 'masterdata.customer.view', 'masterdata.supplier.view', 'masterdata.price_list.view', 'inventory.stock.view', 'inventory.warehouse.view',
            // Sales: sees every document and issues invoices and credit notes; quotations, orders, deliveries and credit-limit overrides stay with sales staff and admins.
            'sales.view', 'sales.invoice.post', 'sales.credit_note.post',
            // Procurement: sees every purchasing document and enters supplier bills (price differences beyond the tolerance stay with admin).
            'procurement.view', 'procurement.bill.post',
            // Manufacturing: sees production orders, bills of materials and what is in work in progress.
            'manufacturing.view',
            // The assistant answers from what the person may already see.
            'ai.use',
            // Messages to customers and suppliers: reads, prepares and sends them (sensitive ones still need their approval), and records who allowed or refused contact.
            'comm.message.view', 'comm.message.draft', 'comm.message.send', 'comm.consent.manage'];
        $salesStaff = ['ai.use', 'comm.message.view', 'comm.message.draft', 'sales.view', 'sales.quotation.manage', 'sales.order.manage', 'sales.order.confirm', 'sales.delivery.post',
            'masterdata.reference.view', 'masterdata.item.view', 'masterdata.customer.view', 'masterdata.price_list.view', 'inventory.stock.view', 'inventory.warehouse.view'];
        Role::findOrCreate('accountant', 'web')->syncPermissions(array_values(array_filter(
            $names,
            fn (string $name) => str_starts_with($name, 'finance.') || in_array($name, $readable, true),
        )));

        // Sales staff quote, take and confirm orders and send goods out; they see customers, items, prices and stock but change none of
        // them, do not invoice (the accountant does), cannot reverse a delivery and cannot override a credit limit (admin only).
        Role::findOrCreate('sales', 'web')->syncPermissions(array_values(array_filter($names, fn (string $name) => in_array($name, $salesStaff, true))));

        // Purchasing staff raise purchase orders, receive goods and send them back; they read suppliers, items, prices and stock but change none
        // of them, do not enter supplier bills (the accountant does) and cannot accept a price difference or reverse a receipt (admin only).
        $purchasing = ['ai.use', 'comm.message.view', 'comm.message.draft', 'procurement.view', 'procurement.order.manage', 'procurement.receipt.post', 'procurement.return.post',
            'masterdata.reference.view', 'masterdata.item.view', 'masterdata.supplier.view', 'masterdata.price_list.view', 'inventory.stock.view', 'inventory.warehouse.view'];
        Role::findOrCreate('purchasing', 'web')->syncPermissions(array_values(array_filter($names, fn (string $name) => in_array($name, $purchasing, true))));

        // Production staff draft bills of materials, plan, release and run production orders and issue/return materials; they read items,
        // warehouses and stock but change none of them. Activating a bill, issuing more than required and the work-centre/machine/shift
        // setup stay with admin.
        $production = ['ai.use', 'manufacturing.view', 'manufacturing.bom.manage', 'manufacturing.order.manage', 'manufacturing.order.release', 'manufacturing.issue.post',
            'manufacturing.output.report', 'manufacturing.downtime.report', 'manufacturing.qc.inspect', 'manufacturing.receipt.post', 'manufacturing.order.complete',
            'masterdata.reference.view', 'masterdata.item.view', 'inventory.stock.view', 'inventory.warehouse.view'];
        Role::findOrCreate('production', 'web')->syncPermissions(array_values(array_filter($names, fn (string $name) => in_array($name, $production, true))));

        // Machine operators only see the order list and report their shift's output and downtime; cancelling a report, inspecting and completing stay with supervisors.
        $operator = ['manufacturing.view', 'manufacturing.output.report', 'manufacturing.downtime.report'];
        Role::findOrCreate('operator', 'web')->syncPermissions(array_values(array_filter($names, fn (string $name) => in_array($name, $operator, true))));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
