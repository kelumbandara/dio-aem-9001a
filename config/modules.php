<?php

use App\Modules\Approvals\ApprovalsServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Finance\FinanceServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Licensing\LicensingServiceProvider;
use App\Modules\Manufacturing\ManufacturingServiceProvider;
use App\Modules\MasterData\MasterDataServiceProvider;
use App\Modules\Procurement\ProcurementServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Sales\SalesServiceProvider;

/*
|--------------------------------------------------------------------------
| Module registry (see docs/architecture/modules.md)
|--------------------------------------------------------------------------
|
| `depends_on` lists the modules *below* this one in the dependency map. A
| module may import Contracts/DTOs/Events of itself and of those modules only
| (enforced by tests/Architecture). `optional_depends_on` lists modules whose Contracts a module may also import but that it can run without. `enabled` is the default; the
| `module_settings` table overrides it per deployment. `required` modules can
| never be disabled.
|
| New modules are added here when their sprint starts, not before.
*/

return [
    'modules' => [
        'core' => [
            'name' => 'Core',
            'provider' => CoreServiceProvider::class,
            'depends_on' => [],
            'enabled' => true,
            'required' => true,
        ],
        'approvals' => [
            'name' => 'Approvals',
            'provider' => ApprovalsServiceProvider::class,
            'depends_on' => ['core'],
            'enabled' => true,
            'required' => false,
        ],
        'masterdata' => [
            'name' => 'Master Data',
            'provider' => MasterDataServiceProvider::class,
            'depends_on' => ['core', 'approvals'],
            'enabled' => true,
            'required' => false,
        ],
        'inventory' => [
            'name' => 'Inventory',
            'provider' => InventoryServiceProvider::class,
            'depends_on' => ['core', 'approvals', 'masterdata'],
            'enabled' => true,
            'required' => false,
        ],
        'finance' => [
            'name' => 'Finance',
            'provider' => FinanceServiceProvider::class,
            'depends_on' => ['core', 'approvals', 'masterdata'],
            'enabled' => true,
            'required' => false,
        ],
        'procurement' => [
            'name' => 'Procurement',
            'provider' => ProcurementServiceProvider::class,
            'depends_on' => ['core', 'approvals', 'masterdata', 'inventory', 'finance'],
            'enabled' => true,
            'required' => false,
        ],
        'licensing' => [
            'name' => 'Licensing',
            'provider' => LicensingServiceProvider::class,
            'depends_on' => ['core'],
            'enabled' => true,
            'required' => true,
        ],
        'sales' => [
            'name' => 'Sales',
            'provider' => SalesServiceProvider::class,
            'depends_on' => ['core', 'approvals', 'masterdata', 'inventory', 'finance'],
            'enabled' => true,
            'required' => false,
        ],
        'manufacturing' => [
            'name' => 'Manufacturing',
            'provider' => ManufacturingServiceProvider::class,
            'depends_on' => ['core', 'approvals', 'masterdata', 'inventory', 'finance'],
            'enabled' => true,
            'required' => false,
        ],
        'reporting' => [
            'name' => 'Reporting',
            'provider' => ReportingServiceProvider::class,
            'depends_on' => ['core'],
            // May read these modules' Contracts/DTOs when they are switched on, but works without any of them (each dashboard simply goes missing).
            'optional_depends_on' => ['approvals', 'masterdata', 'inventory', 'finance', 'sales', 'procurement', 'manufacturing'],
            'enabled' => true,
            'required' => false,
        ],
    ],

    // Namespaces other modules may import from a module (everything else is private).
    'public_namespaces' => ['Contracts', 'DTOs', 'Events', 'Concerns'],
];
