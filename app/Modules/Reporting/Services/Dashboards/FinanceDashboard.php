<?php

namespace App\Modules\Reporting\Services\Dashboards;

use App\Modules\Finance\Contracts\FinanceMetrics;
use App\Modules\Reporting\Support\Period;

class FinanceDashboard extends DashboardBuilder
{
    public function __construct(private readonly FinanceMetrics $finance) {}

    public function key(): string
    {
        return 'finance';
    }

    public function permission(): string
    {
        return 'finance.report.view';
    }

    public function modules(): array
    {
        return ['finance'];
    }

    public function build(Period $period, ?Period $compare): array
    {
        $top = (int) config('reporting.top_rows');
        $now = $this->finance->profit($period->from, $period->to);
        $was = $compare === null ? null : $this->finance->profit($compare->from, $compare->to);
        $cash = $this->finance->cash($period->from, $period->to);
        $cashWas = $compare === null ? null : $this->finance->cash($compare->from, $compare->to);
        $ar = $this->finance->openItems('receivable', $top);
        $ap = $this->finance->openItems('payable', $top);

        $ageing = fn (array $x) => array_map(fn (string $k) => ['label' => $this->text('bucket.'.$k), 'value' => $x['buckets'][$k], 'url' => null], array_keys($x['buckets']));
        $parties = fn (array $x) => array_map(fn (array $r) => ['cells' => ['party' => $r['party'], 'total' => $r['total'], 'overdue' => $r['overdue']], 'link' => null], $x['top']);

        return [
            'kpis' => [
                $this->kpi('income', 'lkr', $now['income'], $was['income'] ?? null, true, $this->link('finance.reports.index')),
                $this->kpi('cost_of_sales', 'lkr', $now['cost_of_sales'], $was['cost_of_sales'] ?? null, false, $this->link('finance.reports.index')),
                $this->kpi('gross_profit', 'lkr', $now['gross_profit'], $was['gross_profit'] ?? null, true, $this->link('finance.reports.index')),
                $this->kpi('net_profit', 'lkr', $now['net_profit'], $was['net_profit'] ?? null, true, $this->link('finance.reports.index')),
                $this->kpi('receivable', 'lkr', $ar['total'], null, null, $this->link('finance.reports.index'), $this->text('h.balance_now')),
                $this->kpi('receivable_overdue', 'lkr', $ar['overdue'], null, false, $this->link('finance.reports.index'), $this->text('h.balance_now')),
                $this->kpi('payable', 'lkr', $ap['total'], null, null, $this->link('finance.reports.index'), $this->text('h.balance_now')),
                $this->kpi('payable_overdue', 'lkr', $ap['overdue'], null, false, $this->link('finance.reports.index'), $this->text('h.balance_now')),
                $this->kpi('cash_total', 'lkr', $cash['total'], null, null, $this->link('finance.reports.index'), $this->text('h.balance_now')),
                $this->kpi('cash_received', 'lkr', $cash['received'], $cashWas['received'] ?? null, true),
                $this->kpi('cash_paid', 'lkr', $cash['paid'], $cashWas['paid'] ?? null, null),
            ],
            'charts' => [
                $this->bars('receivable_ageing', 'lkr', $ageing($ar)),
                $this->bars('payable_ageing', 'lkr', $ageing($ap)),
                $this->bars('bank_balances', 'lkr', array_map(fn (array $a) => ['label' => $a['name'], 'value' => ltrim($a['balance'], '-'), 'url' => null], $cash['accounts'])),
            ],
            'tables' => [
                $this->table('top_debtors', [['key' => 'party'], ['key' => 'total', 'type' => 'lkr'], ['key' => 'overdue', 'type' => 'lkr']], $parties($ar)),
                $this->table('top_creditors', [['key' => 'party'], ['key' => 'total', 'type' => 'lkr'], ['key' => 'overdue', 'type' => 'lkr']], $parties($ap)),
                $this->table('bank_accounts', [['key' => 'account'], ['key' => 'kind'], ['key' => 'balance', 'type' => 'lkr']],
                    array_map(fn (array $a) => ['cells' => ['account' => $a['name'], 'kind' => $a['kind'], 'balance' => $a['balance']], 'link' => null], $cash['accounts'])),
            ],
        ];
    }
}
