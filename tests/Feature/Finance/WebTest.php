<?php

use App\Modules\Finance\Approvals\ManualJournalPost;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\Contracts\OpenItemPoster;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\DTOs\InvoicePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\DTOs\JournalRequest;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AccountMapping;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\FiscalPeriod;
use App\Modules\Finance\Models\FiscalYear;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\SettlementService;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Actions\SaveSupplier;
use Illuminate\Support\Arr;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->user = finUser();
    $this->today = now('Asia/Colombo')->toDateString();
    $this->bank = BankAccount::whereHas('account', fn ($q) => $q->where('code', '1155'))->first();
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
    $this->supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
    $this->invoice = function (string $no = 'INV-1') {
        app(OpenItemPoster::class)->postInvoice(new InvoicePosting('receivable', $this->customer->id, $no, $this->today, now('Asia/Colombo')->addDays(30)->toDateString(), [JournalLineData::credit('1000', 'sales_revenue'), JournalLineData::credit('180', 'vat_output')], 'sales_invoice', $no));

        return OpenItem::where('document_no', $no)->first();
    };
});

describe('pages open and show the data', function () {
    it('renders every screen in all three languages without raw translation keys', function (string $locale) {
        $this->user->forceFill(['locale' => $locale])->save();
        $invoice = ($this->invoice)();
        $journal = app(JournalPoster::class)->post(new JournalRequest($this->today, 'Telephone', [
            JournalLineData::debit('500', null, Account::where('code', '2411')->value('id')), JournalLineData::credit('500', null, Account::where('code', '1155')->value('id')),
        ]));
        $receipt = app(SettlementService::class)->post(['side' => 'receivable', 'party_id' => $this->customer->id, 'bank_account_id' => $this->bank->id, 'date' => $this->today, 'amount' => '300', 'allocations' => [['open_item_id' => $invoice->id, 'amount' => '200']]]);
        $bill = app(OpenItemPoster::class)->postInvoice(new InvoicePosting('payable', $this->supplier->id, 'BILL-1', $this->today, $this->today, [JournalLineData::debit('900', 'grni')], 'purchase_invoice', '1'));
        $payment = app(SettlementService::class)->post(['side' => 'payable', 'party_id' => $this->supplier->id, 'bank_account_id' => $this->bank->id, 'date' => $this->today, 'amount' => '100']);
        $account = Account::where('code', '1155')->first();

        $urls = ['/finance/accounts', '/finance/accounts?show=all', '/finance/accounts/create', "/finance/accounts/{$account->public_id}", "/finance/accounts/{$account->public_id}/edit",
            '/finance/journals', '/finance/journals?kind=manual&q=tel&from=2020-01-01', '/finance/journals/new', "/finance/journals/{$journal->publicId}",
            '/finance/receivables', '/finance/receivables?party='.$this->customer->code, '/finance/receivables/settlements', '/finance/receivables/settlements/new', '/finance/receivables/settlements/new?party='.$this->customer->code,
            "/finance/receivables/settlements/{$receipt->public_id}", '/finance/receivables/parties/'.$this->customer->code,
            '/finance/payables', '/finance/payables/settlements', '/finance/payables/settlements/new?party='.$this->supplier->code, "/finance/payables/settlements/{$payment->public_id}", '/finance/payables/parties/'.$this->supplier->code,
            '/finance/reports', '/finance/reports/trial-balance', '/finance/reports/trial-balance?from=2020-01-01', '/finance/reports/general-ledger', '/finance/reports/general-ledger?account=1155',
            '/finance/reports/profit-and-loss', '/finance/reports/balance-sheet', '/finance/periods', '/finance/setup'];

        foreach ($urls as $url) {
            $body = $this->actingAs($this->user->fresh())->get($url)->assertOk()->getContent();
            expect($body)->not->toContain('finance::')->not->toContain('finance.ui.', "raw key on {$url}");
        }
    })->with(['en', 'si', 'ta']);

    it('has identical translation keys in every language', function () {
        $keys = fn (string $locale) => collect(array_keys(Arr::dot(require app_path("Modules/Finance/Lang/{$locale}/ui.php"))))->sort()->values()->all();

        foreach (['si', 'ta'] as $locale) {
            expect($keys($locale))->toBe($keys('en'), "{$locale} differs from en");
        }
    });

    it('shows the menu to people who may see it, and nothing to others', function () {
        $this->actingAs($this->user)->get('/')->assertSee('Chart of accounts')->assertSee('Journals')->assertSee('Receivables')->assertSee('Financial reports');
        $reader = userWithPermissions('finance.view');
        $this->actingAs($reader)->get('/finance/accounts')->assertOk()->assertDontSee('New account');
        $this->actingAs($reader)->get('/finance/accounts/create')->assertForbidden();
        $this->actingAs($reader)->get('/finance/receivables')->assertForbidden();
        $this->actingAs($reader)->get('/finance/reports/trial-balance')->assertForbidden();
        $this->actingAs(userWithPermissions('masterdata.item.view'))->get('/finance/accounts')->assertForbidden();
    });

    it('needs a login', function () {
        $this->get('/finance/accounts')->assertRedirect('/login');
    });

    it('shows balances and groups on the chart', function () {
        app(JournalPoster::class)->post(new JournalRequest($this->today, 'x', [JournalLineData::debit('1234.50', null, Account::where('code', '1155')->value('id')), JournalLineData::credit('1234.50', null, Account::where('code', '2000')->value('id'))]));

        $this->actingAs($this->user)->get('/finance/accounts')->assertSee('CURRENT ASSETS')->assertSee('BANK OF CEYLON- LKR')->assertSee('1,234.50')->assertDontSee('PURCHASE PRICE VARIENCE');
        $this->actingAs($this->user)->get('/finance/accounts?show=all')->assertSee('PURCHASE PRICE VARIENCE');
    });
});

describe('chart of accounts', function () {
    it('adds and edits an account', function () {
        $group = Account::where('code', '11')->first();
        $this->actingAs($this->user)->post('/finance/accounts', ['code' => '2499', 'name' => 'RENT', 'class' => 'expense', 'parent_id' => $group->id, 'is_group' => '0', 'is_active' => '1'])->assertSessionHasNoErrors()->assertRedirect('/finance/accounts');
        $account = Account::where('code', '2499')->first();

        $this->actingAs($this->user)->put("/finance/accounts/{$account->public_id}", ['name' => 'OFFICE RENT', 'class' => 'expense', 'parent_id' => $group->id, 'is_active' => '1', 'version' => 1])->assertSessionHasNoErrors();
        expect($account->fresh())->name->toBe('OFFICE RENT')->version->toBe(2);
        $this->actingAs($this->user)->from('/x')->put("/finance/accounts/{$account->public_id}", ['name' => 'Stale', 'class' => 'expense', 'parent_id' => $group->id, 'version' => 1])->assertSessionHasErrors('form');
        $this->actingAs($this->user)->from('/x')->post('/finance/accounts', ['code' => '2499', 'name' => 'Dup', 'class' => 'expense', 'parent_id' => $group->id])->assertSessionHasErrors('code');
    });
});

describe('journals', function () {
    it('posts a journal from the form, ignoring empty rows, and reverses it', function () {
        $this->actingAs($this->user)->post('/finance/journals', [
            'entry_date' => $this->today, 'description' => 'Telephone bill', 'reference' => 'TEL-1',
            'lines' => [['account' => '2411', 'debit' => '1500', 'credit' => '', 'description' => ''], ['account' => '1155', 'debit' => '', 'credit' => '1500', 'description' => ''], ['account' => '', 'debit' => '', 'credit' => '', 'description' => '']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $journal = JournalEntry::sole();
        expect($journal->kind)->toBe('manual')->and($journal->total)->toBe('1500.0000');
        $this->actingAs($this->user)->get("/finance/journals/{$journal->public_id}")->assertSee('Telephone bill')->assertSee('Reverse this journal');

        $this->actingAs($this->user)->post("/finance/journals/{$journal->public_id}/reverse", ['note' => 'Wrong month'])->assertSessionHas('status');
        expect($journal->fresh()->status)->toBe('reversed');
        $this->actingAs($this->user)->get("/finance/journals/{$journal->public_id}")->assertDontSee('Reverse this journal');
    });

    it('returns to the form with the message on the line', function () {
        $this->actingAs($this->user)->from('/finance/journals/new')->post('/finance/journals', ['description' => 'x', 'lines' => [['account' => '2411', 'debit' => '10'], ['account' => '1155', 'credit' => '9']]])
            ->assertRedirect('/finance/journals/new')->assertSessionHasErrors('lines');
        $this->actingAs($this->user)->from('/finance/journals/new')->post('/finance/journals', ['description' => 'x', 'lines' => [['account' => 'NOPE', 'debit' => '10'], ['account' => '1155', 'credit' => '10']]])
            ->assertSessionHasErrors('lines.0.account');
        $this->actingAs($this->user)->from('/finance/journals/new')->post('/finance/journals', ['description' => 'x', 'lines' => [['account' => '1125', 'debit' => '10'], ['account' => '1155', 'credit' => '10']]])
            ->assertSessionHasErrors('lines.0.account');
        expect(JournalEntry::count())->toBe(0);
    });

    it('does not let someone without the permission post or reverse', function () {
        $this->actingAs(userWithPermissions('finance.view'))->get('/finance/journals/new')->assertForbidden();
        $this->actingAs(userWithPermissions('finance.view'))->post('/finance/journals', ['description' => 'x', 'lines' => []])->assertForbidden();
    });

    it('tells the person when the journal is waiting for approval', function () {
        userWithRole('finance-approver');
        paymentPolicy(['name' => 'Manual journals', 'action_key' => ManualJournalPost::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);

        $this->actingAs($this->user)->post('/finance/journals', ['description' => 'Big one', 'lines' => [['account' => '2411', 'debit' => '900'], ['account' => '1155', 'credit' => '900']]])
            ->assertRedirect('/finance/journals')->assertSessionHas('status', fn ($m) => str_contains($m, 'needs approval') && str_contains($m, 'APR/'));
        expect(JournalEntry::count())->toBe(0);
    });
});

describe('receipts and payments', function () {
    it('records a receipt from the form, allocating to an invoice, then allocates the rest and reverses', function () {
        $invoice = ($this->invoice)();

        $this->actingAs($this->user)->post('/finance/receivables/settlements', [
            'party' => $this->customer->code, 'bank_account' => $this->bank->public_id, 'date' => $this->today, 'amount' => '1000', 'method' => 'cheque', 'reference' => 'CHQ-5',
            'allocations' => [['open_item' => $invoice->public_id, 'amount' => '600']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $receipt = Settlement::sole();
        expect($invoice->fresh()->open_amount)->toBe('580.0000');
        $this->actingAs($this->user)->get("/finance/receivables/settlements/{$receipt->public_id}")->assertSee($receipt->document_no)->assertSee('Allocate the rest')->assertSee('CHQ-5');

        $this->actingAs($this->user)->post("/finance/receivables/settlements/{$receipt->public_id}/allocate", ['allocations' => [['open_item' => $invoice->public_id, 'amount' => '400']]])->assertSessionHasNoErrors();
        expect($invoice->fresh()->open_amount)->toBe('180.0000');

        $this->actingAs($this->user)->post("/finance/receivables/settlements/{$receipt->public_id}/reverse", ['note' => 'Bounced'])->assertSessionHas('status');
        expect($invoice->fresh()->open_amount)->toBe('1180.0000')->and($receipt->fresh()->status)->toBe('reversed');
    });

    it('returns to the form with the message when something is wrong', function () {
        $invoice = ($this->invoice)();
        $post = fn (array $o) => $this->actingAs($this->user)->from('/finance/receivables/settlements/new')->post('/finance/receivables/settlements', array_merge([
            'party' => $this->customer->code, 'bank_account' => $this->bank->public_id, 'amount' => '100', 'method' => 'cash', 'allocations' => [['open_item' => $invoice->public_id, 'amount' => '100']],
        ], $o));

        $post(['allocations' => [['open_item' => $invoice->public_id, 'amount' => '5000']], 'amount' => '9000'])->assertRedirect('/finance/receivables/settlements/new')->assertSessionHasErrors('allocations.0.amount');
        $post(['party' => 'NOPE'])->assertSessionHasErrors('party');
        $post(['method' => 'barter'])->assertSessionHasErrors('method');
        expect(Settlement::count())->toBe(0);
    });

    it('pays a supplier from the form', function () {
        app(OpenItemPoster::class)->postInvoice(new InvoicePosting('payable', $this->supplier->id, 'BILL-1', $this->today, $this->today, [JournalLineData::debit('900', 'grni')], 'purchase_invoice', '1'));
        $bill = OpenItem::where('side', 'payable')->first();

        $this->actingAs($this->user)->post('/finance/payables/settlements', [
            'party' => $this->supplier->code, 'bank_account' => $this->bank->public_id, 'amount' => '900', 'method' => 'bank_transfer', 'allocations' => [['open_item' => $bill->public_id, 'amount' => '900']],
        ])->assertSessionHasNoErrors();

        expect($bill->fresh()->open_amount)->toBe('0.0000')->and(Settlement::sole()->side)->toBe('payable');
    });

    it('keeps the two sides apart', function () {
        $ar = userWithPermissions('finance.ar.view', 'finance.ar.receive');

        $this->actingAs($ar)->get('/finance/receivables')->assertOk();
        $this->actingAs($ar)->get('/finance/payables')->assertForbidden();
        $this->actingAs($ar)->get('/finance/payables/settlements/new')->assertForbidden();
        $this->actingAs(userWithPermissions('finance.ar.view'))->get('/finance/receivables/settlements/new')->assertForbidden();
    });
});

describe('periods and setup', function () {
    it('closes, reopens and locks periods, and creates the next year', function () {
        $period = FiscalPeriod::orderBy('starts_on')->first();

        $this->actingAs($this->user)->post("/finance/periods/{$period->id}/close")->assertSessionHas('status');
        expect($period->fresh()->status)->toBe('closed');
        $this->actingAs($this->user)->get('/finance/periods')->assertSee('Closed')->assertSee('Reopen')->assertSee('Lock');
        $this->actingAs($this->user)->post("/finance/periods/{$period->id}/reopen")->assertSessionHas('status');
        $this->actingAs($this->user)->post("/finance/periods/{$period->id}/close");
        $this->actingAs($this->user)->post("/finance/periods/{$period->id}/lock")->assertSessionHas('status');
        expect($period->fresh()->status)->toBe('locked');
        $this->actingAs($this->user)->from('/x')->post("/finance/periods/{$period->id}/reopen")->assertSessionHasErrors('form');

        $this->actingAs($this->user)->post('/finance/periods/years', ['start_year' => FiscalYear::first()->starts_on->year + 1])->assertSessionHasNoErrors();
        expect(FiscalYear::count())->toBe(2);
        $this->actingAs(userWithPermissions('finance.view', 'finance.period.manage'))->post("/finance/periods/{$period->id}/lock")->assertForbidden();
    });

    it('changes an account mapping and adds a bank account', function () {
        $this->actingAs($this->user)->post('/finance/setup/mappings/cogs', ['account' => '2213'])->assertSessionHasNoErrors();
        expect(AccountMapping::where('key', 'cogs')->first()->account->code)->toBe('2213');
        $this->actingAs($this->user)->from('/x')->post('/finance/setup/mappings/cogs', ['account' => '2000'])->assertSessionHasErrors('account');

        $this->actingAs($this->user)->post('/finance/setup/banks', ['account' => '1111', 'name' => 'HNB', 'kind' => 'bank', 'bank_name' => 'Hatton National Bank', 'account_no' => '1234567890', 'currency' => 'LKR'])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->get('/finance/setup')->assertSee('HNB')->assertSee('••••7890')->assertDontSee('1234567890');
        $hnb = BankAccount::where('name', 'HNB')->first();
        $this->actingAs($this->user)->post("/finance/setup/banks/{$hnb->public_id}/toggle")->assertSessionHasNoErrors();
        expect($hnb->fresh()->is_active)->toBeFalse();
        $this->actingAs(userWithPermissions('finance.view'))->post('/finance/setup/mappings/cogs', ['account' => '2213'])->assertForbidden();
    });
});

describe('reports', function () {
    it('shows balanced statements', function () {
        app(JournalPoster::class)->post(new JournalRequest($this->today, 'Capital', [JournalLineData::debit('10000', null, Account::where('code', '1155')->value('id')), JournalLineData::credit('10000', null, Account::where('code', '1210')->value('id'))]));
        app(JournalPoster::class)->post(new JournalRequest($this->today, 'Sale', [JournalLineData::debit('2000', null, Account::where('code', '1155')->value('id')), JournalLineData::credit('2000', null, Account::where('code', '2000')->value('id'))]));

        $this->actingAs($this->user)->get('/finance/reports/trial-balance')->assertSee('12,000.00')->assertSee('SALES');
        $this->actingAs($this->user)->get('/finance/reports/profit-and-loss')->assertSee('Net profit')->assertSee('2,000.00');
        $this->actingAs($this->user)->get('/finance/reports/balance-sheet')->assertSee('The balance sheet balances.')->assertSee('12,000.00');
        $this->actingAs($this->user)->get('/finance/reports/general-ledger?account=1155')->assertSee('Capital')->assertSee('12,000.00');
    });
});
