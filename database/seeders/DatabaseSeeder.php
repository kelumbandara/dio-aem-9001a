<?php

namespace Database\Seeders;

use App\Modules\Approvals\Database\Seeders\DemoApprovalSeeder;
use App\Modules\Approvals\Database\Seeders\PurchaseOrderPolicySeeder;
use App\Modules\Core\Database\Seeders\CompanyProfileSeeder;
use App\Modules\Core\Database\Seeders\DemoAdminSeeder;
use App\Modules\Core\Database\Seeders\DemoStaffSeeder;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\MasterData\Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Each module contributes its own seeders here as it is built.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CompanyProfileSeeder::class,
            ReferenceDataSeeder::class, // units, LKR + currencies, VAT, payment terms (idempotent)
            FinanceSeeder::class, // chart of accounts, account mapping, bank/cash accounts, current fiscal year (idempotent)
            PurchaseOrderPolicySeeder::class, // starting approval rules for purchase orders (creates only what is missing)
            DemoAdminSeeder::class, // no-op outside local/testing
            DemoStaffSeeder::class, // no-op outside local
            DemoApprovalSeeder::class, // no-op outside local (needs the demo staff's role)
        ]);
    }
}
