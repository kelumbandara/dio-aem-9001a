<?php

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\OutboxEvent;
use App\Modules\Finance\Actions\ChangePeriodStatus;
use App\Modules\Finance\Actions\CreateFiscalYear;
use App\Modules\Finance\Actions\SaveAccount;
use App\Modules\Finance\Actions\SaveMapping;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AccountMapping;
use App\Modules\Finance\Models\FiscalPeriod;
use App\Modules\Finance\Models\FiscalYear;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Services\AccountMap;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Support\Exceptions\BusinessException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->acc = fn (string $code) => Account::where('code', $code)->firstOrFail();
});

function finError(Closure $fn, string $code): void
{
    try {
        $fn();
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected business error {$code}.");
}

/** A simple balanced manual journal between two accounts of the chart. */
function jv(string $debitCode, string $creditCode, string $amount, array $o = []): PostedJournal
{
    $id = fn (string $c) => Account::where('code', $c)->value('id');

    return app(JournalPoster::class)->post(new JournalRequest(...array_merge([
        'entryDate' => now('Asia/Colombo')->toDateString(), 'description' => 'Test journal',
        'lines' => [JournalLineData::debit($amount, null, $id($debitCode)), JournalLineData::credit($amount, null, $id($creditCode))],
    ], $o)));
}

describe('the chart of accounts', function () {
    it('contains every account of the FrontAccounting chart', function () {
        $codes = array_merge(
            range(1001, 1008), range(1050, 1056), [1100, 1105, 1110, 1111, 1125, 1150, 1155, 1160, 1161, 1162, 1165],
            [1400, 1410, 1420, 1440, 1450, 1500, 1505, 1515, 1516, 1517, 1518, 1519, 1520, 1521, 1522, 1523, 1210, 1300, 1301, 1330, 2000],
            range(2100, 2105), [2200, 2201, 2202, 2203, 2204, 2205, 2213, 2214, 2215, 2216, 2217, 2218, 2230, 2231, 2240, 2241, 2250, 2251, 2260, 2261, 2262, 2270, 2271, 2272],
            range(2310, 2317), range(2400, 2419), [2420, 2421, 2430, 2431, 2440, 2441, 2450, 2460, 2490, 2500, 2501, 2502],
        );

        $missing = array_values(array_filter($codes, fn ($c) => ! Account::where('code', (string) $c)->where('is_group', false)->exists()));

        expect($codes)->toHaveCount(117)->and($missing)->toBe([]);
    });

    it('applies the agreed corrections', function () {
        $a = $this->acc;

        expect($a('2214')->is_active)->toBeFalse()->and($a('2217')->is_active)->toBeTrue()
            ->and($a('2205')->parent->name)->toBe('OTHER REVENUE')->and($a('2205')->class)->toBe('income')
            ->and($a('2203')->parent->name)->toBe('DISTRIBUTION COST')
            ->and($a('2490')->class)->toBe('equity')->and($a('2490')->parent->name)->toBe('RETAINED EARNINGS')
            ->and($a('1210')->class)->toBe('equity')->and($a('1300')->class)->toBe('equity')->and($a('1440')->class)->toBe('liability')
            ->and($a('2408')->name)->toBe('OFFICE EQUIPMENT MAINTENANCE')
            ->and(Account::whereIn('code', ['1126', '1510', '1524', '1525', '1526', '1340', '2206', '2207', '2503'])->count())->toBe(9);
    });

    it('maps every automatic-posting key to an account of a sensible class', function () {
        foreach (AccountMap::keys() as $key) {
            $mapping = AccountMapping::where('key', $key)->first();
            expect($mapping)->not->toBeNull("{$key} is unmapped");
            expect(in_array($mapping->account->class, SaveMapping::CLASSES[$key], true))->toBeTrue("{$key} maps to a {$mapping->account->class} account");
        }
        expect(SaveMapping::CLASSES)->toHaveCount(count(AccountMap::DEFAULTS));
    });

    it('seeds idempotently without overwriting what the accountant changed', function () {
        $this->acc->__invoke('1100')->forceFill(['name' => 'STOCKS (our name)'])->save();
        AccountMapping::where('key', 'cogs')->update(['account_id' => Account::where('code', '2213')->value('id')]);

        $this->seed(FinanceSeeder::class);

        expect(Account::where('code', '1100')->value('name'))->toBe('STOCKS (our name)')->and(Account::where('code', '1100')->count())->toBe(1)
            ->and(AccountMapping::where('key', 'cogs')->first()->account->code)->toBe('2213')->and(FiscalYear::count())->toBe(1);
    });

    it('lets the accountant add accounts and protects the ones in use', function () {
        $group = Account::where('code', '11')->first();
        $new = app(SaveAccount::class)(['code' => '2499', 'name' => 'RENT', 'class' => 'expense', 'parent_id' => $group->id]);
        expect($new->is_group)->toBeFalse()->and($new->version)->toBe(1);

        finError(fn () => app(SaveAccount::class)(['code' => '2499', 'name' => 'Dup', 'class' => 'expense', 'parent_id' => $group->id]), 'finance.code_taken');
        finError(fn () => app(SaveAccount::class)(['code' => '2498', 'name' => 'Wrong class', 'class' => 'income', 'parent_id' => $group->id]), 'finance.parent_invalid');
        finError(fn () => app(SaveAccount::class)(['code' => '2497', 'name' => 'No group', 'class' => 'expense']), 'finance.parent_invalid');

        jv('2499', '1155', '100');
        finError(fn () => app(SaveAccount::class)(['code' => '2499X', 'name' => 'RENT', 'class' => 'expense', 'parent_id' => $group->id], $new->refresh()), 'finance.account_in_use');   // code fixed once posted
        finError(fn () => app(SaveAccount::class)(['is_active' => false], Account::where('code', '1155')->first()), 'finance.account_in_use');                                                // bank account of an active bank/cash account
        finError(fn () => app(SaveAccount::class)(['is_active' => false], Account::where('code', '1100')->first()), 'finance.account_in_use');                                              // mapped
        finError(fn () => app(SaveAccount::class)(['name' => 'x'], $new->refresh(), 99), 'concurrency.stale_version');
    });

    it('keeps mappings on suitable, active, postable accounts', function () {
        finError(fn () => app(SaveMapping::class)('inventory', Account::where('code', '2000')->value('id')), 'finance.mapping_class');
        finError(fn () => app(SaveMapping::class)('inventory', Account::where('code', '02')->value('id')), 'finance.account_invalid');
        finError(fn () => app(SaveMapping::class)('nonsense', Account::where('code', '1100')->value('id')), 'finance.mapping_unknown');
        app(SaveMapping::class)('cogs', Account::where('code', '2213')->value('id'));
        expect(AccountMapping::where('key', 'cogs')->first()->account->code)->toBe('2213');
    });
});

describe('fiscal years and periods', function () {
    it('creates the current year from April with twelve monthly periods', function () {
        $year = FiscalYear::first();
        $periods = $year->periods;

        expect($year->starts_on->format('m-d'))->toBe('04-01')->and($year->ends_on->format('m-d'))->toBe('03-31')->and($periods)->toHaveCount(12)
            ->and($periods->first()->starts_on->toDateString())->toBe($year->starts_on->toDateString())->and($periods->last()->ends_on->toDateString())->toBe($year->ends_on->toDateString())
            ->and($periods->pluck('status')->unique()->all())->toBe(['open']);
        // no gaps or overlaps
        foreach ($periods->values() as $i => $p) {
            if ($i > 0) {
                expect($p->starts_on->toDateString())->toBe($periods[$i - 1]->ends_on->addDay()->toDateString());
            }
        }
        expect(now('Asia/Colombo')->toDateString())->toBeGreaterThanOrEqual($year->starts_on->toDateString())->toBeLessThanOrEqual($year->ends_on->toDateString());
    });

    it('creates the next year, refuses overlaps and bad input', function () {
        $next = app(CreateFiscalYear::class)((int) FiscalYear::first()->starts_on->year + 1, 4);

        expect($next->name)->toMatch('#^\d{4}/\d{2}$#')->and(FiscalPeriod::count())->toBe(24);
        finError(fn () => app(CreateFiscalYear::class)((int) FiscalYear::first()->starts_on->year, 4), 'finance.year_overlaps');
        finError(fn () => app(CreateFiscalYear::class)(1999, 4), 'finance.year_invalid');
        finError(fn () => app(CreateFiscalYear::class)(2040, 13), 'finance.year_invalid');
    });

    it('stops posting into a closed or locked period, and allows it again after reopening', function () {
        $period = FiscalPeriod::where('starts_on', '<=', now('Asia/Colombo')->toDateString())->where('ends_on', '>=', now('Asia/Colombo')->toDateString())->first();

        app(ChangePeriodStatus::class)($period, 'close');
        finError(fn () => jv('1155', '2000', '100'), 'finance.period_closed');

        app(ChangePeriodStatus::class)($period, 'reopen');
        jv('1155', '2000', '100');

        app(ChangePeriodStatus::class)($period, 'close');
        app(ChangePeriodStatus::class)($period, 'lock');
        finError(fn () => app(ChangePeriodStatus::class)($period, 'reopen'), 'finance.period_state');
        finError(fn () => jv('1155', '2000', '100'), 'finance.period_closed');
        expect(fn () => DB::transaction(fn () => DB::table('fiscal_periods')->where('id', $period->id)->update(['name' => 'tampered'])))->toThrow(QueryException::class);   // locked is final, even in the database
        expect(AuditLog::where('auditable_type', (new FiscalPeriod)->getMorphClass())->where('event', 'updated')->count())->toBeGreaterThanOrEqual(3);
    });

    it('refuses a date that no fiscal year covers', function () {
        finError(fn () => jv('1155', '2000', '100', ['entryDate' => '2020-01-15']), 'finance.no_period');
    });
});

describe('posting a journal', function () {
    it('posts a balanced journal, numbered, audited and announced', function () {
        $posted = jv('1155', '2000', '1250.50');

        $entry = JournalEntry::find($posted->id);
        expect($posted->journalNo)->toMatch('#^JV/#')->and($posted->total)->toBe('1250.5000')->and($entry->status)->toBe('posted')->and($entry->kind)->toBe('manual')
            ->and($entry->lines)->toHaveCount(2)->and(JournalLine::sum('debit'))->toEqual(JournalLine::sum('credit'));
        expect(AuditLog::where('event', 'finance.journal_posted')->count())->toBe(1)->and(OutboxEvent::where('event_type', 'finance.journal.posted')->count())->toBe(1);
        expect(app(FinanceReconciler::class)->discrepancies())->toBe([]);
    });

    it('refuses what is not balanced or not valid, and posts nothing', function (Closure $build, string $code) {
        $request = $build(fn (string $c) => Account::where('code', $c)->value('id'));
        finError(fn () => app(JournalPoster::class)->post($request), $code);
        expect(JournalEntry::count())->toBe(0)->and(JournalLine::count())->toBe(0);
    })->with([
        'unbalanced' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [JournalLineData::debit('100', null, $id('1155')), JournalLineData::credit('99.99', null, $id('2000'))]), 'finance.unbalanced'],
        'one line' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [JournalLineData::debit('100', null, $id('1155'))]), 'finance.lines_invalid'],
        'both sides on a line' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [new JournalLineData($id('1155'), null, '5', '5'), JournalLineData::credit('0', null, $id('2000'))]), 'finance.one_side'],
        'zero line' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [JournalLineData::debit('0', null, $id('1155')), JournalLineData::credit('0', null, $id('2000'))]), 'finance.one_side'],
        'negative amount' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [JournalLineData::debit('-5', null, $id('1155')), JournalLineData::credit('5', null, $id('2000'))]), 'finance.amount_invalid'],
        'five decimals' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [JournalLineData::debit('1.00001', null, $id('1155')), JournalLineData::credit('1.00001', null, $id('2000'))]), 'finance.amount_invalid'],
        'a group account' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [JournalLineData::debit('5', null, $id('02')), JournalLineData::credit('5', null, $id('2000'))]), 'finance.account_invalid'],
        'an inactive account' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [JournalLineData::debit('5', null, $id('2214')), JournalLineData::credit('5', null, $id('2000'))]), 'finance.account_invalid'],
        'no account' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [new JournalLineData(null, null, '5'), JournalLineData::credit('5', null, $id('2000'))]), 'finance.account_invalid'],
        'no description' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), '  ', [JournalLineData::debit('5', null, $id('1155')), JournalLineData::credit('5', null, $id('2000'))]), 'finance.description_required'],
        'future date' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->addDay()->toDateString(), 'x', [JournalLineData::debit('5', null, $id('1155')), JournalLineData::credit('5', null, $id('2000'))]), 'finance.date_in_future'],
        'impossible date' => [fn ($id) => new JournalRequest('2026-02-31', 'x', [JournalLineData::debit('5', null, $id('1155')), JournalLineData::credit('5', null, $id('2000'))]), 'finance.date_invalid'],
        'party on one half' => [fn ($id) => new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [new JournalLineData($id('1155'), null, '5', '0', null, 'customer', null), JournalLineData::credit('5', null, $id('2000'))]), 'finance.party_invalid'],
    ]);

    it('cannot be tricked by a manual journal into the receivable, payable or inventory accounts', function (string $code) {
        finError(fn () => jv($code, '2000', '100'), 'finance.control_account');
        finError(fn () => jv('1155', $code, '100'), 'finance.control_account');
    })->with(['1125', '1500', '1100']);

    it('lets the database refuse an unbalanced journal even if application code is bypassed', function () {
        DB::statement('SET CONSTRAINTS journal_lines_balanced IMMEDIATE');
        $period = FiscalPeriod::first();
        $entry = JournalEntry::query()->create(['journal_no' => 'RAW/1', 'entry_date' => now()->toDateString(), 'period_id' => $period->id, 'description' => 'raw', 'total' => '10']);

        expect(fn () => DB::transaction(fn () => JournalLine::query()->create(['entry_id' => $entry->id, 'line_no' => 1, 'account_id' => $this->acc->__invoke('1155')->id, 'debit' => '10', 'credit' => '0'])))
            ->toThrow(QueryException::class, 'not balanced');
    });

    it('rejects a line that is neither a debit nor a credit at the database', function () {
        $entry = JournalEntry::query()->create(['journal_no' => 'RAW/2', 'entry_date' => now()->toDateString(), 'period_id' => FiscalPeriod::first()->id, 'description' => 'raw', 'total' => '0']);

        expect(fn () => DB::transaction(fn () => DB::table('journal_lines')->insert(['entry_id' => $entry->id, 'line_no' => 1, 'account_id' => Account::first()->id, 'debit' => 5, 'credit' => 5])))->toThrow(QueryException::class);
    });

    it('posts a source document once', function () {
        $args = ['sourceType' => 'thing', 'sourceId' => '42'];
        $first = jv('1155', '2000', '100', $args);
        $again = jv('1155', '2000', '100', $args);

        expect($again->journalNo)->toBe($first->journalNo)->and($again->replayed)->toBeTrue()->and(JournalEntry::count())->toBe(1);
    });

    it('can be asked what would happen without it happening', function () {
        $request = new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [JournalLineData::debit('5', null, Account::where('code', '1155')->value('id')), JournalLineData::credit('5', null, Account::where('code', '2000')->value('id'))]);

        expect(app(JournalPoster::class)->check($request)->total)->toBe('5.0000')->and(JournalEntry::count())->toBe(0);
    });

    it('posts by mapping key and fails clearly when a key has no account', function () {
        app(JournalPoster::class)->post(new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [JournalLineData::debit('5', 'discount_given'), JournalLineData::credit('5', null, Account::where('code', '1155')->value('id'))]));
        AccountMapping::where('key', 'discount_given')->delete();

        finError(fn () => app(JournalPoster::class)->post(new JournalRequest(now('Asia/Colombo')->toDateString(), 'x', [JournalLineData::debit('5', 'discount_given'), JournalLineData::credit('5', null, Account::where('code', '1155')->value('id'))])), 'finance.mapping_missing');
    });
});

describe('journals are permanent', function () {
    it('refuses changes and deletes at the model and the database', function () {
        $posted = jv('1155', '2000', '100');
        $line = JournalLine::first();

        expect(fn () => $line->update(['debit' => '1']))->toThrow(LogicException::class);
        expect(fn () => $line->delete())->toThrow(LogicException::class);
        expect(fn () => DB::transaction(fn () => DB::table('journal_lines')->update(['debit' => 1])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('journal_lines')->delete()))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('journal_entries')->update(['description' => 'tampered'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('journal_entries')->delete()))->toThrow(QueryException::class);
        expect(JournalEntry::find($posted->id)->description)->toBe('Test journal');
    });

    it('is corrected by a reversal that nets to zero and cannot be reversed twice', function () {
        $posted = jv('1155', '2000', '100');

        $reversal = app(JournalPoster::class)->reverse($posted->id, 'Entered twice');

        $original = JournalEntry::find($posted->id);
        $rev = JournalEntry::find($reversal->id);
        expect($original->status)->toBe('reversed')->and($original->reversed_by_id)->toBe($rev->id)->and($rev->kind)->toBe('reversal')->and($rev->reverses_id)->toBe($original->id)
            ->and($rev->description)->toContain('Entered twice');
        expect(DB::table('journal_lines')->where('account_id', $this->acc->__invoke('1155')->id)->selectRaw('sum(debit - credit) as n')->value('n'))->toEqual(0);
        finError(fn () => app(JournalPoster::class)->reverse($posted->id), 'finance.already_reversed');
        finError(fn () => app(JournalPoster::class)->reverse($reversal->id), 'finance.cannot_reverse_reversal');
        finError(fn () => app(JournalPoster::class)->reverse(99999), 'finance.journal_not_found');
        expect(app(FinanceReconciler::class)->discrepancies())->toBe([]);
    });
});
