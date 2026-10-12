<?php

namespace App\Modules\Reporting\Services\Dashboards;

use App\Modules\Manufacturing\Contracts\ProductionMetrics;
use App\Modules\Reporting\Support\Period;

class QualityDashboard extends DashboardBuilder
{
    public function __construct(private readonly ProductionMetrics $production) {}

    public function key(): string
    {
        return 'quality';
    }

    public function permission(): string
    {
        return 'manufacturing.view';
    }

    public function modules(): array
    {
        return ['manufacturing'];
    }

    public function build(Period $period, ?Period $compare): array
    {
        $top = (int) config('reporting.top_rows');
        $now = $this->production->summary($period->from, $period->to);
        $was = $compare === null ? null : $this->production->summary($compare->from, $compare->to);
        $q = $this->production->quality($period->from, $period->to, $top);
        $qWas = $compare === null ? null : $this->production->quality($compare->from, $compare->to, 1);

        return [
            'kpis' => [
                $this->kpi('first_pass_yield', 'pct', $now['first_pass_yield'], $was['first_pass_yield'] ?? null, true),
                $this->kpi('reject_rate', 'pct', $now['reject_rate'], $was['reject_rate'] ?? null, false),
                $this->kpi('rework_rate', 'pct', $now['rework_rate'], $was['rework_rate'] ?? null, false),
                $this->kpi('rejected_units', 'qty', $now['rejected'], $was['rejected'] ?? null, false),
                $this->kpi('rework_units', 'qty', $now['rework'], $was['rework'] ?? null, false),
                $this->kpi('accepted_units', 'qty', $q['accepted'], $qWas['accepted'] ?? null, true, null, $this->text('h.inspection')),
            ],
            'charts' => [
                $this->bars('reject_reasons', 'qty', array_map(fn (array $r) => ['label' => $r['reason'], 'value' => $r['qty'], 'url' => null], $q['reasons'])),
                $this->bars('reject_products', 'pct', array_map(fn (array $r) => ['label' => $r['name'], 'value' => $r['reject_rate'], 'url' => null], $q['products'])),
            ],
            'tables' => [
                $this->table('products_by_reject', [['key' => 'item'], ['key' => 'produced', 'type' => 'qty'], ['key' => 'rejected', 'type' => 'qty'], ['key' => 'reject_rate', 'type' => 'pct']],
                    array_map(fn (array $r) => ['cells' => ['item' => $r['code'].' '.$r['name'], 'produced' => $r['produced'], 'rejected' => $r['rejected'], 'reject_rate' => $r['reject_rate']], 'link' => $this->link('items.show', $r['code'])], $q['products'])),
            ],
        ];
    }
}
