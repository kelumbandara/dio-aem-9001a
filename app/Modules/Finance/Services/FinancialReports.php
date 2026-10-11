<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Contracts\CompanyInfo;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FiscalYear;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Trial balance, general ledger, profit and loss, balance sheet — all computed from the journal lines, nothing stored.
 *
 * Every posted entry counts, including entries later marked reversed and the reversals themselves, so a reversal nets
 * the original out. Amounts are decimal strings in LKR. "Natural" sign: assets and expenses are debit minus credit;
 * liabilities, equity and income are credit minus debit.
 */
final class FinancialReports
{
    public function __construct(private readonly CompanyInfo $company) {}

    /**
     * One row per account that has activity or a balance.
     *
     * @return array{rows: list<array<string, mixed>>, total_debit: string, total_credit: string, from: ?string, to: string}
     */
    public function trialBalance(?string $from, string $to): array
    {
        $opening = $from !== null ? $this->sums("e.entry_date < '{$this->date($from)}'") : [];
        $period = $this->sums(($from !== null ? "e.entry_date >= '{$this->date($from)}' AND " : '')."e.entry_date <= '{$this->date($to)}'");

        $rows = [];
        $totalDebit = $totalCredit = '0';
        $accounts = Account::query()->where('is_group', false)->orderBy('code')->get();
        foreach ($accounts as $a) {
            $o = $opening[$a->id] ?? ['d' => '0', 'c' => '0'];
            $p = $period[$a->id] ?? ['d' => '0', 'c' => '0'];
            $net = Decimal::sub(Decimal::add($o['d'], $p['d']), Decimal::add($o['c'], $p['c']));
            if (Decimal::compare($o['d'], '0', 4) === 0 && Decimal::compare($o['c'], '0', 4) === 0 && Decimal::compare($p['d'], '0', 4) === 0 && Decimal::compare($p['c'], '0', 4) === 0) {
                continue;
            }
            $openNet = Decimal::sub($o['d'], $o['c']);
            $row = [
                'account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'class' => $a->class,
                'opening_debit' => $this->debitSide($openNet), 'opening_credit' => $this->creditSide($openNet),
                'debit' => Decimal::round($p['d'], 4), 'credit' => Decimal::round($p['c'], 4),
                'closing_debit' => $this->debitSide($net), 'closing_credit' => $this->creditSide($net),
            ];
            $totalDebit = Decimal::add($totalDebit, $row['closing_debit']);
            $totalCredit = Decimal::add($totalCredit, $row['closing_credit']);
            $rows[] = $row;
        }

        return ['rows' => $rows, 'total_debit' => Decimal::round($totalDebit, 4), 'total_credit' => Decimal::round($totalCredit, 4), 'from' => $from, 'to' => $to];
    }

    /**
     * The postings of one account with a running balance (debit − credit, so a negative balance is a credit).
     *
     * @return array{account: Account, opening: string, lines: list<array<string, mixed>>, closing: string}
     */
    public function generalLedger(Account $account, ?string $from, string $to): array
    {
        $opening = '0';
        if ($from !== null) {
            $row = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
                ->where('l.account_id', $account->id)->where('e.entry_date', '<', $from)->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->first();
            $opening = Decimal::sub((string) $row->d, (string) $row->c);
        }

        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.account_id', $account->id)->when($from !== null, fn ($q) => $q->where('e.entry_date', '>=', $from))->where('e.entry_date', '<=', $to)
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.line_no')
            ->get(['e.entry_date', 'e.public_id', 'e.journal_no', 'e.description', 'e.reference', 'l.description as line_description', 'l.debit', 'l.credit']);

        $balance = $opening;
        $lines = [];
        foreach ($rows as $r) {
            $balance = Decimal::add($balance, Decimal::sub((string) $r->debit, (string) $r->credit));
            $lines[] = [
                'date' => $r->entry_date, 'journal_id' => $r->public_id, 'journal_no' => $r->journal_no, 'description' => $r->line_description ?: $r->description, 'reference' => $r->reference,
                'debit' => Decimal::round((string) $r->debit, 4), 'credit' => Decimal::round((string) $r->credit, 4), 'balance' => Decimal::round($balance, 4),
            ];
        }

        return ['account' => $account, 'opening' => Decimal::round($opening, 4), 'lines' => $lines, 'closing' => Decimal::round($balance, 4)];
    }

    /**
     * @return array{sections: list<array<string, mixed>>, income: string, expense: string, net_profit: string, from: string, to: string}
     */
    public function profitAndLoss(string $from, string $to): array
    {
        $net = $this->sums("e.entry_date >= '{$this->date($from)}' AND e.entry_date <= '{$this->date($to)}'");
        $sections = $this->sections(['income', 'expense'], $net, 'natural');

        $income = $expense = '0';
        foreach ($sections as $s) {
            if ($s['class'] === 'income') {
                $income = Decimal::add($income, $s['total']);
            } else {
                $expense = Decimal::add($expense, $s['total']);
            }
        }

        return [
            'sections' => $sections, 'income' => Decimal::round($income, 4), 'expense' => Decimal::round($expense, 4),
            'net_profit' => Decimal::round(Decimal::sub($income, $expense), 4), 'from' => $from, 'to' => $to,
        ];
    }

    /**
     * Assets = liabilities + equity, where equity includes this year's profit and the accumulated profit of earlier
     * years (the ledger has no year-end closing entries, so profit is carried by calculation, not by posting).
     *
     * @return array{assets: list<array<string, mixed>>, liabilities: list<array<string, mixed>>, equity: list<array<string, mixed>>, total_assets: string, total_liabilities: string, total_equity: string, year_profit: string, earlier_profit: string, as_at: string, year_start: string}
     */
    public function balanceSheet(string $asAt): array
    {
        $cumulative = $this->sums("e.entry_date <= '{$this->date($asAt)}'");
        $yearStart = $this->yearStart($asAt);
        $beforeYear = $this->sums("e.entry_date < '{$yearStart}'");

        $assets = $this->sections(['asset'], $cumulative, 'natural');
        $liabilities = $this->sections(['liability'], $cumulative, 'natural');
        $equity = $this->sections(['equity'], $cumulative, 'natural');

        $profit = fn (array $sums) => $this->netProfit($sums);
        $earlier = $profit($beforeYear);
        $yearProfit = Decimal::sub($profit($cumulative), $earlier);

        $total = fn (array $sections) => array_reduce($sections, fn ($carry, $s) => Decimal::add($carry, $s['total']), '0');
        $totalEquity = Decimal::add(Decimal::add($total($equity), $earlier), $yearProfit);

        return [
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'total_assets' => Decimal::round($total($assets), 4), 'total_liabilities' => Decimal::round($total($liabilities), 4), 'total_equity' => Decimal::round($totalEquity, 4),
            'year_profit' => Decimal::round($yearProfit, 4), 'earlier_profit' => Decimal::round($earlier, 4), 'as_at' => $asAt, 'year_start' => $yearStart,
        ];
    }

    /** Net balance (debit − credit) of one account up to a date, or of every account. */
    public function balanceOf(int $accountId, ?string $asAt = null): string
    {
        $row = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where('l.account_id', $accountId)
            ->when($asAt !== null, fn ($q) => $q->where('e.entry_date', '<=', $asAt))->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')->first();

        return Decimal::round(Decimal::sub((string) $row->d, (string) $row->c), 4);
    }

    /** @return array<int, array{d: string, c: string}> account id => debit and credit totals for the entries matching the (internal, never user-built) condition */
    private function sums(string $condition): array
    {
        $out = [];
        foreach (DB::select("SELECT l.account_id, SUM(l.debit) AS d, SUM(l.credit) AS c FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE {$condition} GROUP BY l.account_id") as $r) {
            $out[(int) $r->account_id] = ['d' => (string) $r->d, 'c' => (string) $r->c];
        }

        return $out;
    }

    /**
     * Accounts of the given classes grouped by their group, with natural-sign balances; zero accounts are left out.
     *
     * @param  list<string>  $classes
     * @param  array<int, array{d: string, c: string}>  $sums
     * @return list<array<string, mixed>>
     */
    private function sections(array $classes, array $sums, string $sign): array
    {
        $out = [];
        foreach (Account::query()->where('is_group', true)->whereIn('class', $classes)->orderBy('code')->get() as $group) {
            $rows = [];
            $total = '0';
            foreach (Account::query()->where('parent_id', $group->id)->orderBy('code')->get() as $a) {
                $s = $sums[$a->id] ?? ['d' => '0', 'c' => '0'];
                $amount = in_array($a->class, Account::CREDIT_CLASSES, true) ? Decimal::sub($s['c'], $s['d']) : Decimal::sub($s['d'], $s['c']);
                if (Decimal::compare($amount, '0', 4) === 0) {
                    continue;
                }
                $total = Decimal::add($total, $amount);
                $rows[] = ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => Decimal::round($amount, 4)];
            }
            if ($rows !== []) {
                $out[] = ['group' => $group->name, 'class' => $group->class, 'rows' => $rows, 'total' => Decimal::round($total, 4)];
            }
        }

        return $out;
    }

    /** @param  array<int, array{d: string, c: string}>  $sums */
    private function netProfit(array $sums): string
    {
        $profit = '0';
        foreach (Account::query()->where('is_group', false)->whereIn('class', ['income', 'expense'])->get(['id', 'class']) as $a) {
            $s = $sums[$a->id] ?? ['d' => '0', 'c' => '0'];
            $profit = $a->class === 'income' ? Decimal::add($profit, Decimal::sub($s['c'], $s['d'])) : Decimal::sub($profit, Decimal::sub($s['d'], $s['c']));
        }

        return $profit;
    }

    private function yearStart(string $asAt): string
    {
        $year = FiscalYear::query()->where('starts_on', '<=', $asAt)->where('ends_on', '>=', $asAt)->first();
        if ($year !== null) {
            return $year->starts_on->toDateString();
        }
        $startMonth = $this->company->fiscalYearStartMonth();
        $d = CarbonImmutable::parse($asAt);

        return CarbonImmutable::create($d->month >= $startMonth ? $d->year : $d->year - 1, $startMonth, 1)->toDateString();
    }

    private function debitSide(string $net): string
    {
        return Decimal::compare($net, '0', 4) > 0 ? Decimal::round($net, 4) : '0.0000';
    }

    private function creditSide(string $net): string
    {
        return Decimal::compare($net, '0', 4) < 0 ? Decimal::round(ltrim($net, '-'), 4) : '0.0000';
    }

    private function date(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Not a date: '.$value);
        }

        return $value;
    }
}
