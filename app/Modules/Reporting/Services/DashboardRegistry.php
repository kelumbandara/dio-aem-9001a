<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Reporting\Services\Dashboards\CeoDashboard;
use App\Modules\Reporting\Services\Dashboards\DashboardBuilder;
use App\Modules\Reporting\Services\Dashboards\FinanceDashboard;
use App\Modules\Reporting\Services\Dashboards\ProductionDashboard;
use App\Modules\Reporting\Services\Dashboards\PurchasingDashboard;
use App\Modules\Reporting\Services\Dashboards\QualityDashboard;
use App\Modules\Reporting\Services\Dashboards\SalesDashboard;
use App\Modules\Reporting\Services\Dashboards\StoresDashboard;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Container\Container;

/** Which dashboards exist, and which of them a person may open (module switched on and the module's own permission held). */
class DashboardRegistry
{
    private const ALL = [CeoDashboard::class, SalesDashboard::class, PurchasingDashboard::class, StoresDashboard::class, FinanceDashboard::class, ProductionDashboard::class, QualityDashboard::class];

    public function __construct(private readonly Container $container, private readonly ModuleRegistry $modules) {}

    /** @return list<DashboardBuilder> every dashboard whose modules are on, whoever asks */
    public function all(): array
    {
        $out = [];
        foreach (self::ALL as $class) {
            /** @var DashboardBuilder $b */
            $b = $this->container->make($class);
            if (array_filter($b->modules(), fn (string $m) => ! $this->modules->isEnabled($m)) === []) {
                $out[$b->key()] = $b;
            }
        }

        return array_values($out);
    }

    /** @return list<DashboardBuilder> */
    public function availableTo(Authorizable $user): array
    {
        return array_values(array_filter($this->all(), fn (DashboardBuilder $b) => $user->can($b->permission())));
    }

    public function find(string $key, Authorizable $user): ?DashboardBuilder
    {
        foreach ($this->availableTo($user) as $b) {
            if ($b->key() === $key) {
                return $b;
            }
        }

        return null;
    }
}
