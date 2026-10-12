<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Contracts\FinanceMetrics;
use App\Modules\Finance\Models\BankAccount;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseFinanceMetrics implements FinanceMetrics
{
    public function __construct(private readonly AccountMap $map, private readonly SubledgerQueries $subledger) {}

    public function profit(string $from, string $to): array
    {
        $rows = DB::select(
            "SELECT a.class, a.id, COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id
             WHERE e.entry_date BETWEEN ? AND ? AND a.class IN ('income', 'expense') GROUP BY a.class, a.id",
            [$from, $to],
        );
        $cogsId = $this->map->idOrNull('cogs');
        $income = $expenses = $cogs = '0';
        foreach ($rows as $r) {
            if ($r->class === 'income') {
                $income = Decimal::add($income, Decimal::sub((string) $r->c, (string) $r->d));
            } else {
                $net = Decimal::sub((string) $r->d, (string) $r->c);
                $expenses = Decimal::add($expenses, $net);
                $cogs = (int) $r->id === $cogsId ? Decimal::add($cogs, $net) : $cogs;
            }
        }

        return [
            'income' => Decimal::round($income, 2), 'cost_of_sales' => Decimal::round($cogs, 2), 'gross_profit' => Decimal::round(Decimal::sub($income, $cogs), 2),
            'other_expenses' => Decimal::round(Decimal::sub($expenses, $cogs), 2), 'expenses' => Decimal::round($expenses, 2), 'net_profit' => Decimal::round(Decimal::sub($income, $expenses), 2),
        ];
    }

    public function openItems(string $side, int $topLimit = 10): array
    {
        $ageing = $this->subledger->ageing($side);
        $totals = $ageing['totals'];
        $rows = $ageing['rows'];
        usort($rows, fn (array $a, array $b) => Decimal::compare((string) $b['total'], (string) $a['total']) ?: strcmp((string) $a['party'], (string) $b['party']));

        return [
            'total' => Decimal::round((string) $totals['total'], 2), 'overdue' => Decimal::round(Decimal::sub((string) $totals['total'], (string) $totals['current']), 2),
            'buckets' => [
                'current' => Decimal::round((string) $totals['current'], 2), 'd1_30' => Decimal::round((string) $totals['d1_30'], 2), 'd31_60' => Decimal::round((string) $totals['d31_60'], 2),
                'd61_90' => Decimal::round((string) $totals['d61_90'], 2), 'd90_plus' => Decimal::round((string) $totals['d90_plus'], 2),
            ],
            'top' => array_map(fn (array $r) => [
                'party_id' => (int) $r['party_id'], 'party' => (string) $r['party'], 'total' => Decimal::round((string) $r['total'], 2),
                'overdue' => Decimal::round(Decimal::sub((string) $r['total'], (string) $r['current']), 2),
            ], array_slice($rows, 0, $topLimit)),
        ];
    }

    public function cash(string $from, string $to): array
    {
        $today = CarbonImmutable::now(config('core.display_timezone'))->toDateString();
        $accounts = [];
        $total = '0';
        $received = $paid = '0';
        foreach (BankAccount::query()->where('is_active', true)->orderBy('name')->get() as $b) {
            $sum = DB::selectOne('SELECT COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.account_id = ? AND e.entry_date <= ?', [$b->account_id, $today]);
            $balance = Decimal::sub((string) $sum->d, (string) $sum->c);
            $total = Decimal::add($total, $balance);
            $accounts[] = ['name' => (string) $b->name, 'kind' => (string) $b->kind, 'balance' => Decimal::round($balance, 2)];

            $move = DB::selectOne('SELECT COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ?', [$b->account_id, $from, $to]);
            $received = Decimal::add($received, (string) $move->d);
            $paid = Decimal::add($paid, (string) $move->c);
        }

        return [
            'total' => Decimal::round($total, 2), 'accounts' => $accounts, 'received' => Decimal::round($received, 2), 'paid' => Decimal::round($paid, 2),
            'net_movement' => Decimal::round(Decimal::sub($received, $paid), 2),
        ];
    }
}
