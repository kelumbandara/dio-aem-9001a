<?php

use App\Modules\Finance\Actions\CreateFiscalYear;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FiscalYear;
use App\Modules\Finance\Services\FinancialReports;
use App\Support\Decimal;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->r = app(FinancialReports::class);
    $this->today = now('Asia/Colombo')->toDateString();
    $this->year = FiscalYear::first();
    $this->post = function (string $debit, string $credit, string $amount, ?string $date = null, array $o = []) {
        $id = fn (string $c) => Account::where('code', $c)->value('id');

        return app(JournalPoster::class)->post(new JournalRequest(...array_merge([
            'entryDate' => $date ?? $this->today, 'description' => "{$debit}/{$credit}", 'kind' => 'system',
            'lines' => [JournalLineData::debit($amount, null, $id($debit)), JournalLineData::credit($amount, null, $id($credit))],
        ], $o)));
    };
    $this->row = fn (array $tb, string $code) => collect($tb['rows'])->firstWhere('code', $code);
});

describe('trial balance', function () {
    it('lists balances in debit and credit columns that add up to the same total', function () {
        ($this->post)('1155', '1210', '100000');          // share capital paid into the bank
        ($this->post)('1155', '2000', '5000');            // a cash sale
        ($this->post)('2411', '1155', '1500');            // telephone bill paid

        $tb = $this->r->trialBalance(null, $this->today);

        expect(($this->row)($tb, '1155'))->toMatchArray(['closing_debit' => '103500.0000', 'closing_credit' => '0.0000'])
            ->and(($this->row)($tb, '1210'))->toMatchArray(['closing_debit' => '0.0000', 'closing_credit' => '100000.0000'])
            ->and(($this->row)($tb, '2411'))->toMatchArray(['closing_debit' => '1500.0000'])
            ->and($tb['total_debit'])->toBe('105000.0000')->and($tb['total_credit'])->toBe('105000.0000');
        expect(collect($tb['rows'])->pluck('code')->all())->toBe(['1155', '1210', '2000', '2411']);   // accounts without activity are left out
    });

    it('separates the opening balance from the movement of a range', function () {
        $earlier = now('Asia/Colombo')->startOfMonth()->subDays(3)->toDateString();
        app(CreateFiscalYear::class)($this->year->starts_on->year - 1, 4);
        ($this->post)('1155', '1210', '1000', $earlier);
        ($this->post)('1155', '2000', '300', $this->today);

        $tb = $this->r->trialBalance(now('Asia/Colombo')->startOfMonth()->toDateString(), $this->today);

        expect(($this->row)($tb, '1155'))->toMatchArray(['opening_debit' => '1000.0000', 'debit' => '300.0000', 'credit' => '0.0000', 'closing_debit' => '1300.0000'])
            ->and(($this->row)($tb, '1210'))->toMatchArray(['opening_credit' => '1000.0000', 'debit' => '0.0000', 'closing_credit' => '1000.0000'])
            ->and(($this->row)($tb, '2000'))->toMatchArray(['opening_debit' => '0.0000', 'credit' => '300.0000', 'closing_credit' => '300.0000'])
            ->and($tb['total_debit'])->toBe($tb['total_credit']);
    });

    it('nets a reversed journal to nothing', function () {
        $posted = ($this->post)('1155', '2000', '700', null, ['kind' => 'manual']);
        app(JournalPoster::class)->reverse($posted->id);

        $tb = $this->r->trialBalance(null, $this->today);

        expect(collect($tb['rows'])->pluck('closing_debit')->unique()->all())->toBe(['0.0000'])->and(collect($tb['rows'])->pluck('closing_credit')->unique()->all())->toBe(['0.0000'])
            ->and($tb['total_debit'])->toBe('0.0000')
            ->and(($this->row)($tb, '1155'))->toMatchArray(['debit' => '700.0000', 'credit' => '700.0000']);   // both entries are visible as movement
    });
});

describe('general ledger', function () {
    it('shows each posting with a running balance', function () {
        $account = Account::where('code', '1155')->first();
        ($this->post)('1155', '1210', '1000', $this->today, ['description' => 'Capital']);
        ($this->post)('2411', '1155', '250');
        ($this->post)('1155', '2000', '75.50');

        $gl = $this->r->generalLedger($account, null, $this->today);

        expect($gl['opening'])->toBe('0.0000')->and(array_column($gl['lines'], 'balance'))->toBe(['1000.0000', '750.0000', '825.5000'])->and($gl['closing'])->toBe('825.5000')
            ->and($gl['lines'][0]['journal_no'])->toStartWith('JV/')->and($gl['lines'][1]['credit'])->toBe('250.0000');
    });

    it('starts from the balance brought forward when a range is chosen', function () {
        $account = Account::where('code', '1155')->first();
        app(CreateFiscalYear::class)($this->year->starts_on->year - 1, 4);
        ($this->post)('1155', '1210', '1000', $this->year->starts_on->subDays(10)->toDateString());
        ($this->post)('1155', '2000', '40');

        $gl = $this->r->generalLedger($account, $this->year->starts_on->toDateString(), $this->today);

        expect($gl['opening'])->toBe('1000.0000')->and($gl['lines'])->toHaveCount(1)->and($gl['closing'])->toBe('1040.0000');
    });
});

describe('profit and loss', function () {
    it('adds income, takes off costs, by group', function () {
        ($this->post)('1155', '2000', '10000');     // sales
        ($this->post)('2206', '1100', '4000');      // cost of sales (stock out)
        ($this->post)('2411', '1155', '1500');      // telephone
        ($this->post)('2501', '1155', '500');       // overdraft interest

        $pl = $this->r->profitAndLoss($this->year->starts_on->toDateString(), $this->today);

        expect($pl['income'])->toBe('10000.0000')->and($pl['expense'])->toBe('6000.0000')->and($pl['net_profit'])->toBe('4000.0000');
        expect(collect($pl['sections'])->pluck('group')->all())->toBe(['SALES REVENUE', 'COST OF GOODS SOLD', 'GENERAL & ADMINISTRATIVE COST', 'FINANCE COST']);
    });

    it('only counts the chosen dates', function () {
        ($this->post)('1155', '2000', '10000', $this->year->starts_on->toDateString());
        ($this->post)('1155', '2000', '300');

        expect($this->r->profitAndLoss($this->today, $this->today)['net_profit'])->toBe('300.0000')
            ->and($this->r->profitAndLoss($this->year->starts_on->toDateString(), $this->today)['net_profit'])->toBe('10300.0000');
    });
});

describe('balance sheet', function () {
    it('balances: assets equal liabilities plus equity including this year\'s profit', function () {
        ($this->post)('1155', '1210', '100000');    // capital
        ($this->post)('1100', '1500', '30000');     // stock bought on credit (payables)
        ($this->post)('1155', '2000', '8000');      // sales
        ($this->post)('2411', '1155', '1000');      // expense
        ($this->post)('1007', '1155', '20000');     // a computer bought

        $bs = $this->r->balanceSheet($this->today);

        expect($bs['total_assets'])->toBe('137000.0000')->and($bs['total_liabilities'])->toBe('30000.0000')->and($bs['year_profit'])->toBe('7000.0000')->and($bs['earlier_profit'])->toBe('0.0000')
            ->and($bs['total_equity'])->toBe('107000.0000');
        expect(Decimal::add($bs['total_liabilities'], $bs['total_equity']))->toBe(Decimal::add($bs['total_assets'], '0'));
    });

    it('carries the profit of earlier years into equity without any closing entry', function () {
        app(CreateFiscalYear::class)($this->year->starts_on->year - 1, 4);
        $lastYear = $this->year->starts_on->subDays(5)->toDateString();
        ($this->post)('1155', '2000', '5000', $lastYear);   // last year's profit
        ($this->post)('1155', '2000', '2000');              // this year's profit

        $bs = $this->r->balanceSheet($this->today);

        expect($bs['earlier_profit'])->toBe('5000.0000')->and($bs['year_profit'])->toBe('2000.0000')->and($bs['total_assets'])->toBe('7000.0000')->and($bs['total_equity'])->toBe('7000.0000');
        $then = $this->r->balanceSheet($lastYear);
        expect($then['year_profit'])->toBe('5000.0000')->and($then['earlier_profit'])->toBe('0.0000')->and($then['total_assets'])->toBe('5000.0000');
    });

    it('stays balanced for any date, whatever was posted', function () {
        ($this->post)('1155', '1210', '1000');
        ($this->post)('1100', '1500', '300');
        ($this->post)('2206', '1100', '100');
        $posted = ($this->post)('2411', '1155', '55.55', null, ['kind' => 'manual']);
        app(JournalPoster::class)->reverse($posted->id);

        $bs = $this->r->balanceSheet($this->today);

        expect($bs['total_assets'])->toBe(Decimal::round(Decimal::add($bs['total_liabilities'], $bs['total_equity']), 4));
    });
});
