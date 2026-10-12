<?php

namespace App\Modules\Reporting\Services\Dashboards;

use App\Modules\Inventory\Contracts\StockMetrics;
use App\Modules\Reporting\Support\Period;

class StoresDashboard extends DashboardBuilder
{
    public function __construct(private readonly StockMetrics $stock) {}

    public function key(): string
    {
        return 'stores';
    }

    public function permission(): string
    {
        return 'inventory.stock.view';
    }

    public function modules(): array
    {
        return ['inventory'];
    }

    public function build(Period $period, ?Period $compare): array
    {
        $top = (int) config('reporting.top_rows');
        $value = $this->stock->valuation();
        $now = $this->stock->movement($period->from, $period->to);
        $was = $compare === null ? null : $this->stock->movement($compare->from, $compare->to);
        $low = $this->stock->belowReorder($top);
        $exp = $this->stock->expiring(30, $top);
        $items = $this->stock->topValueItems($top);

        return [
            'kpis' => [
                $this->kpi('stock_value', 'lkr', $value['total_value'], null, null, $this->link('inventory.stock.index'), $this->text('h.stock_now')),
                $this->kpi('items_in_stock', 'count', (string) $value['items_in_stock'], null, null, $this->link('inventory.stock.index'), $this->text('h.stock_now')),
                $this->kpi('stock_in', 'lkr', $now['inflow'], $was['inflow'] ?? null, null),
                $this->kpi('stock_out', 'lkr', $now['outflow'], $was['outflow'] ?? null, null),
                $this->kpi('below_reorder', 'count', (string) $low['count'], null, false, $this->link('inventory.stock.index'), $this->text('h.stock_now')),
                $this->kpi('expiring_lots', 'count', (string) $exp['lots'], null, false, $this->link('inventory.lots.index'), $this->text('h.expiring')),
                $this->kpi('expiring_value', 'lkr', $exp['value'], null, false, $this->link('inventory.lots.index'), $this->text('h.expiring')),
                $this->kpi('expired_value', 'lkr', $exp['expired_value'], null, false, $this->link('inventory.lots.index'), $this->text('h.stock_now')),
            ],
            'charts' => [
                $this->bars('stock_by_warehouse', 'lkr', array_map(fn (array $r) => ['label' => $r['name'], 'value' => $r['value'], 'url' => null], $value['by_warehouse'])),
                $this->bars('stock_top_value', 'lkr', array_map(fn (array $r) => ['label' => $r['name'], 'value' => $r['value'], 'url' => route('inventory.stock.item', [$r['code']])], $items)),
                $this->bars('movement_reasons', 'lkr', array_map(fn (array $r) => ['label' => ucfirst(str_replace('_', ' ', $r['type'])).' · '.str_replace('_', ' ', $r['reason']), 'value' => ltrim($r['value'], '-'), 'url' => null], $now['by_reason'])),
            ],
            'tables' => [
                $this->table('below_reorder', [['key' => 'item'], ['key' => 'warehouse'], ['key' => 'available', 'type' => 'qty'], ['key' => 'min_qty', 'type' => 'qty'], ['key' => 'shortfall', 'type' => 'qty']],
                    array_map(fn (array $r) => ['cells' => ['item' => $r['code'].' '.$r['name'], 'warehouse' => $r['warehouse'], 'available' => $r['available'].' '.$r['uom'], 'min_qty' => $r['min_qty'], 'shortfall' => $r['shortfall']], 'link' => $this->link('inventory.stock.item', $r['code'])], $low['list'])),
                $this->table('expiring', [['key' => 'item'], ['key' => 'lot'], ['key' => 'expiry', 'type' => 'date'], ['key' => 'qty', 'type' => 'qty'], ['key' => 'expired', 'type' => 'flag']],
                    array_map(fn (array $r) => ['cells' => ['item' => $r['code'].' '.$r['name'], 'lot' => $r['lot_no'], 'expiry' => $r['expiry_date'], 'qty' => $r['qty'], 'expired' => $r['expired']], 'link' => $this->link('inventory.lots.index')], $exp['list'])),
            ],
        ];
    }
}
