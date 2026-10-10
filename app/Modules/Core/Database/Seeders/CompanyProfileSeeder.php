<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\CompanyProfile;
use Illuminate\Database\Seeder;

/**
 * Creates the placeholder company and head-office branch on a fresh install only.
 * Existing data is never overwritten; the owner edits the real details in the app.
 */
class CompanyProfileSeeder extends Seeder
{
    public function run(): void
    {
        if (CompanyProfile::current() === null) {
            CompanyProfile::query()->forceCreate([
                'id' => CompanyProfile::ID,
                'legal_name' => 'My Company (Pvt) Ltd',
                'country' => 'LK',
                'base_currency' => 'LKR',
                'timezone' => 'Asia/Colombo',
                'fiscal_year_start_month' => 4,
            ]);
        }

        if (! Branch::query()->exists()) {
            Branch::query()->create(['code' => 'HO', 'name' => 'Head Office', 'is_head_office' => true]);
        }
    }
}
