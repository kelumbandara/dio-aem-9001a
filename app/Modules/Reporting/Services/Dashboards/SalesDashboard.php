<?php

namespace App\Modules\Reporting\Services\Dashboards;

use App\Modules\Reporting\Support\Period;
use App\Modules\Sales\Contracts\SalesMetrics;

class SalesDashboard extends DashboardBuilder
{
    public function __construct(private readonly SalesMetrics $sales) {}

    public function key(): string
    {
        return 'sales';
    }

    public function permission(): string
    {
        return 'sales.view';
    }

    public function modules(): array
    {
        return ['sales'];
    }

    public function build(Period $period, ?Period $compare): array
    {
        $now = $this->sales->summary($period->from, $period->to);
        $was = $compare === null ? null : $this->sales->summary($compare->from, $compare->to);
        $open = $this->sales->openOrders(10);
        $top = $this->sales->topCustomers($period->from, $period->to, (int) config('reporting.top_rows'));
        $items = $this->sales->topItems($period->from, $period->to, (int) config('reporting.top_rows'));

        return [
            'kpis' => [
                $this->kpi('net_sales', 'lkr', $now['net_sales'], $was['net_sales'] ?? null, true, $this->link('sales.invoices.index')),
                $this->kpi('invoices', 'count', (string) $now['invoices'], isset($was) ? (string) $was['invoices'] : null, true, $this->link('sales.invoices.index')),
                $this->kpi('average_invoice', 'lkr', $now['average_invoice'], $was['average_invoice'] ?? null, true),
                $this->kpi('credit_notes', 'lkr', $now['net_credited'], $was['net_credited'] ?? null, false, $this->link('sales.credit-notes.index')),
                $this->kpi('orders_confirmed', 'count', (string) $now['orders_confirmed'], isset($was) ? (string) $was['orders_confirmed'] : null, true, $this->link('sales.orders.index')),
                $this->kpi('quotations', 'count', (string) $now['quotations'], isset($was) ? (string) $was['quotations'] : null, true, $this->link('sales.quotations.index')),
                $this->kpi('to_invoice', 'lkr', $open['to_invoice'], null, null, $this->link('sales.orders.index'), $this->text('h.open_snapshot')),
                $this->kpi('orders_overdue', 'count', (string) $open['overdue'], null, false, $this->link('sales.orders.index'), $this->text('h.open_snapshot')),
            ],
            'charts' => [
                $this->series('sales_trend', 'lkr', $this->sales->dailyNet($period->from, $period->to), $compare === null ? null : $this->sales->dailyNet($compare->from, $compare->to), 'net', $period),
                $this->bars('top_customers', 'lkr', array_map(fn (array $r) => ['label' => $r['name'], 'value' => $r['net'], 'url' => route('customers.show', [$r['public_id']])], $top)),
                $this->bars('top_items', 'lkr', array_map(fn (array $r) => ['label' => $r['name'], 'value' => $r['net'], 'url' => route('items.show', [$r['code']])], $items)),
            ],
            'tables' => [
                $this->table('top_customers', [['key' => 'customer'], ['key' => 'invoices', 'type' => 'count'], ['key' => 'net', 'type' => 'lkr']],
                    array_map(fn (array $r) => ['cells' => ['customer' => $r['name'], 'invoices' => $r['invoices'], 'net' => $r['net']], 'link' => $this->link('customers.show', $r['public_id'])], $top)),
                $this->table('top_items', [['key' => 'item'], ['key' => 'qty', 'type' => 'qty'], ['key' => 'net', 'type' => 'lkr']],
                    array_map(fn (array $r) => ['cells' => ['item' => $r['name'], 'qty' => $r['qty'].' '.$r['uom'], 'net' => $r['net']], 'link' => $this->link('items.show', $r['code'])], $items)),
                $this->table('largest_invoices', [['key' => 'document'], ['key' => 'customer'], ['key' => 'date', 'type' => 'date'], ['key' => 'gross', 'type' => 'lkr']],
                    array_map(fn (array $r) => ['cells' => ['document' => $r['document_no'], 'customer' => $r['customer'], 'date' => $r['date'], 'gross' => $r['gross']], 'link' => $this->link('sales.invoices.show', $r['id'])], $this->sales->largestInvoices($period->from, $period->to, (int) config('reporting.top_rows')))),
                $this->table('open_orders', [['key' => 'document'], ['key' => 'customer'], ['key' => 'required', 'type' => 'date'], ['key' => 'to_invoice', 'type' => 'lkr'], ['key' => 'overdue', 'type' => 'flag']],
                    array_map(fn (array $r) => ['cells' => ['document' => $r['document_no'], 'customer' => $r['customer'], 'required' => $r['required_date'], 'to_invoice' => $r['to_invoice'], 'overdue' => $r['overdue']], 'link' => $this->link('sales.orders.show', $r['id'])], $open['list'])),
            ],
        ];
    }
}
