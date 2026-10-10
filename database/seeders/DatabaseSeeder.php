<?php

namespace Database\Seeders;

use App\Modules\Core\Database\Seeders\CompanyProfileSeeder;
use App\Modules\Core\Database\Seeders\DemoAdminSeeder;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
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
            DemoAdminSeeder::class, // no-op outside local/testing
        ]);
    }
}
