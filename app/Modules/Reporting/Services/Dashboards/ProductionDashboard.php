<?php

namespace App\Modules\Reporting\Services\Dashboards;

use App\Modules\Manufacturing\Contracts\ProductionMetrics;
use App\Modules\Reporting\Support\Period;

class ProductionDashboard extends DashboardBuilder
{
    public function __construct(private readonly ProductionMetrics $production) {}

    public function key(): string
    {
        return 'production';
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
        $tg = $this->production->targets($period->from, $period->to, $top);
        $tgWas = $compare === null ? null : $this->production->targets($compare->from, $compare->to, 1);
        $down = $this->production->downtime($period->from, $period->to, $top);
        $downWas = $compare === null ? null : $this->production->downtime($compare->from, $compare->to, 1);
        $late = $this->production->lateOrders($top);

        return [
            'kpis' => [
                $this->kpi('good_units', 'qty', $now['good'], $was['good'] ?? null, true, $this->link('manufacturing.shifts.report')),
                $this->kpi('target_achievement', 'pct', $tg['achievement'], $tgWas['achievement'] ?? null, true, $this->link('manufacturing.shifts.report'), $this->text('h.target')),
                $this->kpi('on_time_rate', 'pct', $now['on_time_rate'], $was['on_time_rate'] ?? null, true, $this->link('manufacturing.orders.index'), $this->text('h.on_time')),
                $this->kpi('downtime_minutes', 'minutes', $down['minutes'], $downWas['minutes'] ?? null, false, $this->link('manufacturing.shifts.report')),
                $this->kpi('open_orders', 'count', (string) $now['open_orders'], null, null, $this->link('manufacturing.orders.index'), $this->text('h.open_snapshot')),
                $this->kpi('late_orders', 'count', (string) $now['late_orders'], null, false, $this->link('manufacturing.orders.index'), $this->text('h.open_snapshot')),
                $this->kpi('wip_value', 'lkr', $now['wip_value'], null, null, null, $this->text('h.open_snapshot')),
                $this->kpi('received_value', 'lkr', $now['received_value'], $was['received_value'] ?? null, true),
            ],
            'charts' => [
                $this->series('production_trend', 'qty', $this->production->dailyGood($period->from, $period->to), $compare === null ? null : $this->production->dailyGood($compare->from, $compare->to), 'good', $period),
                $this->bars('downtime_reasons', 'minutes', array_map(fn (array $r) => ['label' => $r['name'], 'value' => $r['minutes'], 'url' => null], $down['top_reasons'])),
                $this->bars('downtime_machines', 'minutes', array_map(fn (array $r) => ['label' => $r['name'], 'value' => $r['minutes'], 'url' => null], $down['top_machines'])),
            ],
            'tables' => [
                $this->table('worst_shifts', [['key' => 'date', 'type' => 'date'], ['key' => 'shift'], ['key' => 'item'], ['key' => 'good', 'type' => 'qty'], ['key' => 'target', 'type' => 'qty'], ['key' => 'achievement', 'type' => 'pct']],
                    array_map(fn (array $r) => ['cells' => $r, 'link' => $this->link('manufacturing.shifts.report')], $tg['worst'])),
                $this->table('late_orders', [['key' => 'document'], ['key' => 'item'], ['key' => 'planned_end', 'type' => 'date'], ['key' => 'days_late', 'type' => 'count'], ['key' => 'planned_qty', 'type' => 'qty'], ['key' => 'good_qty', 'type' => 'qty']],
                    array_map(fn (array $r) => ['cells' => ['document' => $r['order_no'], 'item' => $r['item'], 'planned_end' => $r['planned_end'], 'days_late' => $r['days_late'], 'planned_qty' => $r['planned_qty'], 'good_qty' => $r['good_qty']], 'link' => $this->link('manufacturing.orders.show', $r['id'])], $late)),
            ],
        ];
    }
}
