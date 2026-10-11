<?php

use App\Modules\Finance\Actions\ChangePeriodStatus;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\Contracts\OpenItemPoster;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\DTOs\InvoicePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\PostedJournal;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\FiscalPeriod;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\Finance\Services\SettlementService;
use App\Modules\Finance\Services\SubledgerQueries;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Support\Exceptions\BusinessException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
    $this->customer2 = app(SaveCustomer::class)(['name' => 'Colombo Stores']);
    $this->supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
    $this->bank = BankAccount::whereHas('account', fn ($q) => $q->where('code', '1155'))->first();
    $this->today = now('Asia/Colombo')->toDateString();
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    $this->reconcile = fn () => expect(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});

function finFails(Closure $fn, string $code): void
{
    try {
        $fn();
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected business error {$code}.");
}

/** A sales invoice of LKR 1,000 + 18% VAT = 1,180. */
function salesInvoice(int $customerId, string $no = 'INV-1', array $o = []): PostedJournal
{
    return app(OpenItemPoster::class)->postInvoice(new InvoicePosting(...array_merge([
        'side' => 'receivable', 'partyId' => $customerId, 'documentNo' => $no, 'documentDate' => now('Asia/Colombo')->toDateString(), 'dueDate' => now('Asia/Colombo')->addDays(30)->toDateString(),
        'lines' => [JournalLineData::credit('1000', 'sales_revenue'), JournalLineData::credit('180', 'vat_output')], 'sourceType' => 'sales_invoice', 'sourceId' => $no,
    ], $o)));
}

function supplierBill(int $supplierId, string $no = 'BILL-1', string $amount = '5000', array $o = []): PostedJournal
{
    return app(OpenItemPoster::class)->postInvoice(new InvoicePosting(...array_merge([
        'side' => 'payable', 'partyId' => $supplierId, 'documentNo' => $no, 'documentDate' => now('Asia/Colombo')->toDateString(), 'dueDate' => now('Asia/Colombo')->addDays(14)->toDateString(),
        'lines' => [JournalLineData::debit($amount, 'grni')],
    ], $o)));
}

describe('invoices and bills', function () {
    it('posts a sales invoice: receivable against revenue and VAT, with an open item', function () {
        $posted = salesInvoice($this->customer->id);

        $journal = JournalEntry::find($posted->id);
        $lines = $journal->lines->map(fn ($l) => [Account::find($l->account_id)->code, $l->debit, $l->credit, $l->party_type, $l->party_id])->all();
        expect($lines)->toBe([['1125', '1180.0000', '0.0000', 'customer', $this->customer->id], ['2000', '0.0000', '1000.0000', null, null], ['1524', '0.0000', '180.0000', null, null]]);
        $item = OpenItem::sole();
        expect($item->amount)->toBe('1180.0000')->and($item->open_amount)->toBe('1180.0000')->and($item->side)->toBe('receivable')->and($item->journal_id)->toBe($journal->id);
        ($this->reconcile)();
    });

    it('posts a supplier bill: expense or goods-received against payables', function () {
        supplierBill($this->supplier->id);

        expect(($this->bal)('1500'))->toBe('-5000.0000')->and(($this->bal)('1510'))->toBe('5000.0000')->and(OpenItem::sole()->side)->toBe('payable');
        ($this->reconcile)();
    });

    it('posts a source once and refuses duplicate numbers, inactive parties and malformed invoices', function () {
        $first = salesInvoice($this->customer->id);
        $again = salesInvoice($this->customer->id);
        expect($again->journalNo)->toBe($first->journalNo)->and($again->replayed)->toBeTrue()->and(OpenItem::count())->toBe(1);

        finFails(fn () => salesInvoice($this->customer->id, 'INV-1', ['sourceId' => 'other']), 'finance.document_exists');
        salesInvoice($this->customer2->id, 'INV-1', ['sourceId' => 'c2']);   // the same number for another customer is fine

        $this->customer2->forceFill(['is_active' => false])->save();
        finFails(fn () => salesInvoice($this->customer2->id, 'INV-2'), 'masterdata.customer_inactive');
        finFails(fn () => salesInvoice($this->customer->id, 'INV-3', ['lines' => [JournalLineData::debit('5', 'sales_revenue')]]), 'finance.lines_invalid');   // a debit line on a sales invoice
        finFails(fn () => salesInvoice($this->customer->id, 'INV-4', ['lines' => []]), 'finance.lines_invalid');
        finFails(fn () => salesInvoice($this->customer->id, 'INV-5', ['dueDate' => now('Asia/Colombo')->subDay()->toDateString()]), 'finance.due_before_date');
        finFails(fn () => salesInvoice($this->customer->id, '  ', []), 'finance.document_no_required');
        finFails(fn () => salesInvoice($this->customer->id, 'INV-6', ['side' => 'sideways']), 'finance.side_invalid');
        expect(OpenItem::count())->toBe(2);
        ($this->reconcile)();
    });

    it('does not post into a closed period', function () {
        $period = FiscalPeriod::where('starts_on', '<=', $this->today)->where('ends_on', '>=', $this->today)->first();
        app(ChangePeriodStatus::class)($period, 'close');

        finFails(fn () => salesInvoice($this->customer->id), 'finance.period_closed');
        expect(OpenItem::count())->toBe(0)->and(JournalEntry::count())->toBe(0);
    });
});

describe('receipts and payments', function () {
    beforeEach(function () {
        salesInvoice($this->customer->id, 'INV-1');                              // 1,180
        salesInvoice($this->customer->id, 'INV-2', ['sourceId' => 'INV-2']);     // 1,180
        $this->i1 = OpenItem::where('document_no', 'INV-1')->first();
        $this->i2 = OpenItem::where('document_no', 'INV-2')->first();
        $this->receive = fn (array $o = []) => app(SettlementService::class)->post(array_merge([
            'side' => 'receivable', 'party_id' => $this->customer->id, 'bank_account_id' => $this->bank->id, 'date' => $this->today, 'amount' => '1180', 'method' => 'bank_transfer',
            'reference' => 'CHQ-77', 'allocations' => [['open_item_id' => $this->i1->id, 'amount' => '1180']],
        ], $o), null);
    });

    it('settles an invoice in full: bank up, receivable down, invoice closed', function () {
        $receipt = ($this->receive)();

        expect($receipt->document_no)->toMatch('#^REC/#')->and($receipt->status)->toBe('posted')->and($this->i1->fresh()->open_amount)->toBe('0.0000')
            ->and(($this->bal)('1155'))->toBe('1180.0000')->and(($this->bal)('1125'))->toBe('1180.0000');   // INV-2 still owed
        $lines = JournalEntry::find($receipt->journal_id)->lines->map(fn ($l) => [Account::find($l->account_id)->code, $l->debit, $l->credit])->all();
        expect($lines)->toBe([['1155', '1180.0000', '0.0000'], ['1125', '0.0000', '1180.0000']]);
        ($this->reconcile)();
    });

    it('settles partly, over several invoices, and keeps money on account', function () {
        $receipt = ($this->receive)(['amount' => '2000', 'allocations' => [['open_item_id' => $this->i1->id, 'amount' => '1180'], ['open_item_id' => $this->i2->id, 'amount' => '500']]]);

        expect($this->i1->fresh()->open_amount)->toBe('0.0000')->and($this->i2->fresh()->open_amount)->toBe('680.0000')->and(app(SettlementService::class)->unallocated($receipt))->toBe('320.0000');
        $onAccount = ($this->receive)(['amount' => '300', 'allocations' => [], 'reference' => 'advance']);
        expect(app(SettlementService::class)->unallocated($onAccount))->toBe('300.0000')->and(app(SubledgerQueries::class)->unallocated('receivable'))->toHaveCount(2);
        ($this->reconcile)();

        app(SettlementService::class)->allocate($onAccount, [['open_item_id' => $this->i2->id, 'amount' => '300']]);
        expect($this->i2->fresh()->open_amount)->toBe('380.0000')->and(app(SettlementService::class)->unallocated($onAccount->fresh()))->toBe('0.0000');
        ($this->reconcile)();
    });

    it('refuses to settle more than is owed or more than was received, or someone else\'s invoice', function () {
        finFails(fn () => ($this->receive)(['amount' => '2000', 'allocations' => [['open_item_id' => $this->i1->id, 'amount' => '1180.01']]]), 'finance.over_allocated');
        finFails(fn () => ($this->receive)(['amount' => '1000', 'allocations' => [['open_item_id' => $this->i1->id, 'amount' => '1180']]]), 'finance.over_allocated');
        finFails(fn () => ($this->receive)(['party_id' => $this->customer2->id]), 'finance.open_item_invalid');
        finFails(fn () => ($this->receive)(['allocations' => [['open_item_id' => 99999, 'amount' => '5']]]), 'finance.open_item_invalid');
        ($this->receive)();
        finFails(fn () => ($this->receive)(), 'finance.over_allocated');   // already settled
        expect(Settlement::count())->toBe(1);
        ($this->reconcile)();
    });

    it('validates the money and the accounts', function (array $override, string $code) {
        finFails(fn () => ($this->receive)($override), $code);
        expect(Settlement::count())->toBe(0)->and(JournalEntry::count())->toBe(2);   // only the two invoices
    })->with([
        'zero' => [['amount' => '0', 'allocations' => []], 'finance.amount_invalid'],
        'negative' => [['amount' => '-5', 'allocations' => []], 'finance.amount_invalid'],
        'five decimals' => [['amount' => '1.00001', 'allocations' => []], 'finance.amount_invalid'],
        'unknown method' => [['method' => 'barter'], 'finance.method_invalid'],
        'unknown customer' => [['party_id' => 99999, 'allocations' => []], 'finance.party_invalid'],
        'no bank' => [['bank_account_id' => 99999], 'finance.bank_invalid'],
        'wrong side' => [['side' => 'sideways'], 'finance.side_invalid'],
        'future date' => [['date' => '2999-01-01'], 'finance.date_in_future'],
    ]);

    it('does not take money into a foreign-currency or inactive account', function () {
        $usd = BankAccount::whereHas('account', fn ($q) => $q->where('code', '1150'))->first();
        finFails(fn () => ($this->receive)(['bank_account_id' => $usd->id]), 'finance.foreign_bank_not_supported');

        $this->bank->forceFill(['is_active' => false])->save();
        finFails(fn () => ($this->receive)(), 'finance.bank_invalid');
    });

    it('reverses a receipt and gives the invoice back its balance, once', function () {
        $receipt = ($this->receive)();

        $reversed = app(SettlementService::class)->reverse($receipt, 'Cheque bounced');

        expect($reversed->status)->toBe('reversed')->and($this->i1->fresh()->open_amount)->toBe('1180.0000')->and(($this->bal)('1155'))->toBe('0.0000')
            ->and(JournalEntry::find($receipt->journal_id)->status)->toBe('reversed')->and(app(SettlementService::class)->unallocated($reversed))->toBe('0.0000');
        finFails(fn () => app(SettlementService::class)->reverse($receipt), 'finance.already_reversed');
        finFails(fn () => app(SettlementService::class)->allocate($receipt, [['open_item_id' => $this->i2->id, 'amount' => '5']]), 'finance.settlement_reversed');
        ($this->reconcile)();
    });

    it('cannot be undone through the general journal screens', function () {
        $receipt = ($this->receive)();

        finFails(fn () => app(JournalPoster::class)->reverse($receipt->journal_id), 'finance.reverse_via_document');
        finFails(fn () => app(JournalPoster::class)->reverse($this->i1->journal_id), 'finance.reverse_via_document');
    });

    it('pays a supplier: payables down, bank down', function () {
        supplierBill($this->supplier->id, 'BILL-1', '5000');
        $bill = OpenItem::where('side', 'payable')->first();

        $payment = app(SettlementService::class)->post([
            'side' => 'payable', 'party_id' => $this->supplier->id, 'bank_account_id' => $this->bank->id, 'date' => $this->today, 'amount' => '3000',
            'allocations' => [['open_item_id' => $bill->id, 'amount' => '3000']],
        ]);

        expect($payment->document_no)->toMatch('#^PAY/#')->and($bill->fresh()->open_amount)->toBe('2000.0000')->and(($this->bal)('1155'))->toBe('-3000.0000')->and(($this->bal)('1500'))->toBe('-2000.0000');
        ($this->reconcile)();
    });

    it('keeps its records permanent', function () {
        $receipt = ($this->receive)();

        expect(fn () => DB::transaction(fn () => DB::table('settlements')->where('id', $receipt->id)->update(['amount' => 1])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('settlements')->delete()))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('settlement_allocations')->update(['amount' => 1])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('settlement_allocations')->delete()))->toThrow(QueryException::class);
        expect(fn () => $receipt->allocations()->first()->update(['amount' => 1]))->toThrow(LogicException::class);
    });
});

describe('what is owed', function () {
    it('buckets by how overdue, per customer', function () {
        $day = fn (int $n) => now('Asia/Colombo')->subDays($n)->toDateString();
        // due dates: not yet due, 10 days overdue, 45 days overdue, 120 days overdue
        foreach ([['A1', now('Asia/Colombo')->addDays(5)], ['A2', now('Asia/Colombo')->subDays(10)], ['A3', now('Asia/Colombo')->subDays(45)], ['A4', now('Asia/Colombo')->subDays(120)]] as [$no, $due]) {
            salesInvoice($this->customer->id, $no, ['documentDate' => min($this->today, $due->subDays(1)->toDateString()), 'dueDate' => $due->toDateString(), 'sourceId' => $no, 'lines' => [JournalLineData::credit('100', 'sales_revenue')]]);
        }

        $report = app(SubledgerQueries::class)->ageing('receivable');

        expect($report['rows'])->toHaveCount(1)->and($report['rows'][0])->toMatchArray(['current' => '100.0000', 'd1_30' => '100.0000', 'd31_60' => '100.0000', 'd61_90' => '0.0000', 'd90_plus' => '100.0000', 'total' => '400.0000'])
            ->and($report['totals']['total'])->toBe('400.0000');
        expect(app(SubledgerQueries::class)->openItems('receivable')->getCollection()->pluck('document_no')->all())->toBe(['A4', 'A3', 'A2', 'A1']);   // oldest due first
    });

    it('lists a party\'s account with a running balance', function () {
        salesInvoice($this->customer->id, 'INV-1');
        app(SettlementService::class)->post(['side' => 'receivable', 'party_id' => $this->customer->id, 'bank_account_id' => $this->bank->id, 'date' => $this->today, 'amount' => '500', 'allocations' => [['open_item_id' => OpenItem::first()->id, 'amount' => '500']]]);

        $ledger = app(SubledgerQueries::class)->partyLedger('receivable', $this->customer->id, null, $this->today);

        expect($ledger['lines'])->toHaveCount(2)->and($ledger['lines'][0]['balance'])->toBe('1180.0000')->and($ledger['lines'][1]['balance'])->toBe('680.0000')->and($ledger['closing'])->toBe('680.0000');
        $later = app(SubledgerQueries::class)->partyLedger('receivable', $this->customer->id, now('Asia/Colombo')->addDay()->toDateString(), now('Asia/Colombo')->addDays(2)->toDateString());
        expect($later['opening'])->toBe('680.0000')->and($later['lines'])->toBe([]);
    });

    it('notices when the sub-ledger and the control account disagree', function () {
        salesInvoice($this->customer->id, 'INV-1');
        DB::table('open_items')->update(['open_amount' => '1']);

        $problems = app(FinanceReconciler::class)->discrepancies();

        expect($problems)->not->toBe([])->and(implode(' ', $problems))->toContain('Open item INV-1')->and(implode(' ', $problems))->toContain('Receivables');
        $this->artisan('finance:reconcile')->assertFailed();
    });

    it('says the books agree when they do', function () {
        salesInvoice($this->customer->id, 'INV-1');

        $this->artisan('finance:reconcile')->expectsOutputToContain('agree')->assertSuccessful();
    });
});
