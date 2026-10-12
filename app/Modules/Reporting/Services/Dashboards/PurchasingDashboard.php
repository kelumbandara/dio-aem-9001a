<?php

namespace App\Modules\Reporting\Services\Dashboards;

use App\Modules\Procurement\Contracts\PurchasingMetrics;
use App\Modules\Reporting\Support\Period;

class PurchasingDashboard extends DashboardBuilder
{
    public function __construct(private readonly PurchasingMetrics $purchasing) {}

    public function key(): string
    {
        return 'purchasing';
    }

    public function permission(): string
    {
        return 'procurement.view';
    }

    public function modules(): array
    {
        return ['procurement'];
    }

    public function build(Period $period, ?Period $compare): array
    {
        $now = $this->purchasing->summary($period->from, $period->to);
        $was = $compare === null ? null : $this->purchasing->summary($compare->from, $compare->to);
        $open = $this->purchasing->openOrders(10);
        $top = $this->purchasing->topSuppliers($period->from, $period->to, (int) config('reporting.top_rows'));

        return [
            'kpis' => [
                $this->kpi('net_purchases', 'lkr', $now['net_purchases'], $was['net_purchases'] ?? null, null, $this->link('procurement.bills.index')),
                $this->kpi('orders_approved', 'count', (string) $now['orders_approved'], isset($was) ? (string) $was['orders_approved'] : null, null, $this->link('procurement.orders.index')),
                $this->kpi('orders_value', 'lkr', $now['orders_value'], $was['orders_value'] ?? null, null, $this->link('procurement.orders.index')),
                $this->kpi('receipts_value', 'lkr', $now['receipts_value'], $was['receipts_value'] ?? null, null, $this->link('procurement.receipts.index')),
                $this->kpi('returns', 'lkr', $now['returns_net'], $was['returns_net'] ?? null, false, $this->link('procurement.returns.index')),
                $this->kpi('price_variance', 'lkr', $now['price_variance'], $was['price_variance'] ?? null, false, null, $this->text('h.price_variance')),
                $this->kpi('to_receive', 'lkr', $open['to_receive'], null, null, $this->link('procurement.orders.index'), $this->text('h.open_snapshot')),
                $this->kpi('po_overdue', 'count', (string) $open['overdue'], null, false, $this->link('procurement.orders.index'), $this->text('h.open_snapshot')),
            ],
            'charts' => [
                $this->series('purchases_trend', 'lkr', $this->purchasing->dailyNet($period->from, $period->to), $compare === null ? null : $this->purchasing->dailyNet($compare->from, $compare->to), 'net', $period),
                $this->bars('top_suppliers', 'lkr', array_map(fn (array $r) => ['label' => $r['name'], 'value' => $r['net'], 'url' => route('suppliers.show', [$r['public_id']])], $top)),
            ],
            'tables' => [
                $this->table('top_suppliers', [['key' => 'supplier'], ['key' => 'bills', 'type' => 'count'], ['key' => 'net', 'type' => 'lkr']],
                    array_map(fn (array $r) => ['cells' => ['supplier' => $r['name'], 'bills' => $r['bills'], 'net' => $r['net']], 'link' => $this->link('suppliers.show', $r['public_id'])], $top)),
                $this->table('open_purchase_orders', [['key' => 'document'], ['key' => 'supplier'], ['key' => 'expected', 'type' => 'date'], ['key' => 'to_receive', 'type' => 'lkr'], ['key' => 'overdue', 'type' => 'flag']],
                    array_map(fn (array $r) => ['cells' => ['document' => $r['document_no'], 'supplier' => $r['supplier'], 'expected' => $r['expected_date'], 'to_receive' => $r['to_receive'], 'overdue' => $r['overdue']], 'link' => $this->link('procurement.orders.show', $r['id'])], $open['list'])),
            ],
        ];
    }
}
