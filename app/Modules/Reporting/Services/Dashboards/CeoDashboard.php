<?php

namespace App\Modules\Reporting\Services\Dashboards;

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Reporting\Support\Period;

/**
 * The owner's one-page view: the few numbers that matter from every module that is switched on, and the lists that need a decision.
 * It reuses the other dashboards, so a figure here is always the same figure as on its own dashboard.
 */
class CeoDashboard extends DashboardBuilder
{
    private const PICK = [
        'sales' => ['net_sales', 'orders_overdue'],
        'finance' => ['net_profit', 'receivable_overdue', 'payable_overdue', 'cash_total'],
        'purchasing' => ['net_purchases'],
        'stores' => ['stock_value', 'below_reorder'],
        'production' => ['good_units', 'target_achievement', 'late_orders'],
    ];

    private const LISTS = ['open_orders', 'open_purchase_orders', 'below_reorder', 'late_orders'];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly SalesDashboard $sales,
        private readonly PurchasingDashboard $purchasing,
        private readonly StoresDashboard $stores,
        private readonly FinanceDashboard $finance,
        private readonly ProductionDashboard $production,
    ) {}

    public function key(): string
    {
        return 'ceo';
    }

    public function permission(): string
    {
        return 'reporting.executive';
    }

    public function modules(): array
    {
        return [];
    }

    public function build(Period $period, ?Period $compare): array
    {
        $kpis = $charts = $tables = [];
        foreach (['sales' => $this->sales, 'finance' => $this->finance, 'purchasing' => $this->purchasing, 'stores' => $this->stores, 'production' => $this->production] as $name => $dash) {
            if (! $this->enabled($dash)) {
                continue;
            }
            $built = $dash->build($period, $compare);
            foreach (self::PICK[$name] as $key) {
                foreach ($built['kpis'] as $k) {
                    if ($k['key'] === $key) {
                        $kpis[] = $k;
                    }
                }
            }
            foreach ($built['charts'] as $c) {
                if (in_array($c['id'], ['sales_trend', 'production_trend'], true)) {
                    $charts[] = $c;
                }
            }
            foreach ($built['tables'] as $t) {
                if (in_array($t['id'], self::LISTS, true)) {
                    $tables[] = $t;
                }
            }
        }

        return ['kpis' => $kpis, 'charts' => $charts, 'tables' => $tables];
    }

    private function enabled(DashboardBuilder $d): bool
    {
        foreach ($d->modules() as $m) {
            if (! $this->modules->isEnabled($m)) {
                return false;
            }
        }

        return true;
    }
}
