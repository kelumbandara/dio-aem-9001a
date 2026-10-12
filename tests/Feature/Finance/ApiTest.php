<?php

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Finance\Approvals\ManualJournalPost;
use App\Modules\Finance\Approvals\SupplierPaymentPost;
use App\Modules\Finance\Contracts\OpenItemPoster;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\DTOs\InvoicePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\FiscalPeriod;
use App\Modules\Finance\Models\FiscalYear;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Actions\SaveSupplier;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->user = finUser();
    $this->today = now('Asia/Colombo')->toDateString();
    $this->bank = BankAccount::whereHas('account', fn ($q) => $q->where('code', '1155'))->first();
    $this->lines = fn (string $amount = '500', string $debit = '2411', string $credit = '1155') => [['account' => $debit, 'debit' => $amount], ['account' => $credit, 'credit' => $amount]];
});

describe('chart of accounts', function () {
    it('lists, filters, shows balances and finds accounts', function () {
        asApi($this->user)->getJson('/api/v1/accounts?filter[class]=income&filter[groups]=false')->assertOk()->assertJsonPath('data.0.class', 'income')->assertJsonPath('data.0.is_group', false);
        asApi($this->user)->getJson('/api/v1/accounts?filter[q]=trade deb')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', '1125');
        asApi($this->user)->getJson('/api/v1/accounts?filter[is_active]=false')->assertOk()->assertJsonPath('data.0.code', '2214');
        $id = Account::where('code', '2411')->value('public_id');
        asApi($this->user)->getJson("/api/v1/accounts/{$id}")->assertOk()->assertJsonPath('data.code', '2411')->assertJsonPath('data.balance', '0.0000')->assertJsonPath('data.parent', '11');
    });

    it('creates and updates an account, with versions and clear errors', function () {
        $id = asApi($this->user)->postJson('/api/v1/accounts', ['code' => '2499', 'name' => 'RENT', 'class' => 'expense', 'parent' => '11'], idemKey())->assertCreated()->assertJsonPath('data.parent', '11')->json('data.id');

        asApi($this->user)->patchJson("/api/v1/accounts/{$id}", ['name' => 'OFFICE RENT', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        asApi($this->user)->patchJson("/api/v1/accounts/{$id}", ['name' => 'x', 'version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'concurrency.stale_version');
        asApi($this->user)->postJson('/api/v1/accounts', ['code' => '2499', 'name' => 'Dup', 'class' => 'expense', 'parent' => '11'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'finance.code_taken');
        asApi($this->user)->postJson('/api/v1/accounts', ['code' => '2498', 'name' => 'x', 'class' => 'wizard', 'parent' => '11'], idemKey())->assertStatus(422);
    });

    it('needs the permissions', function () {
        $viewer = userWithPermissions('finance.view');

        asApi($viewer)->getJson('/api/v1/accounts')->assertOk();
        asApi($viewer)->postJson('/api/v1/accounts', ['code' => '2499', 'name' => 'RENT', 'class' => 'expense', 'parent' => '11'], idemKey())->assertForbidden();
        asApi(userWithPermissions('finance.report.view'))->getJson('/api/v1/accounts')->assertForbidden();
    });
});

describe('setup', function () {
    it('shows and changes the account mapping', function () {
        asApi($this->user)->getJson('/api/v1/account-mappings')->assertOk()->assertJsonPath('data.0.key', 'inventory')->assertJsonPath('data.0.account', '1100');
        asApi($this->user)->putJson('/api/v1/account-mappings/cogs', ['account' => '2213'])->assertOk()->assertJsonPath('data.account', '2213');
        asApi($this->user)->putJson('/api/v1/account-mappings/cogs', ['account' => '2000'])->assertStatus(422)->assertJsonPath('error.code', 'finance.mapping_class');
        asApi($this->user)->putJson('/api/v1/account-mappings/cogs', ['account' => 'NOPE'])->assertStatus(422)->assertJsonPath('error.code', 'finance.unknown_code');
        asApi(userWithPermissions('finance.view'))->putJson('/api/v1/account-mappings/cogs', ['account' => '2213'])->assertForbidden();
    });

    it('manages bank accounts and masks the number', function () {
        asApi($this->user)->getJson('/api/v1/bank-accounts')->assertOk()->assertJsonCount(6, 'data');
        $new = Account::where('code', '1111')->exists();
        asApi($this->user)->postJson('/api/v1/bank-accounts', ['account' => '1111', 'name' => 'HNB', 'bank_name' => 'Hatton National Bank', 'account_no' => '1234567890'], idemKey())->assertCreated()
            ->assertJsonPath('data.account_no', '••••7890')->assertJsonMissingPath('data.account_number');
        expect($new)->toBeTrue()->and(DB::table('bank_accounts')->where('name', 'HNB')->value('account_no'))->not->toContain('1234567890');
        asApi($this->user)->postJson('/api/v1/bank-accounts', ['account' => '2000', 'name' => 'x'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'finance.account_invalid');
        asApi($this->user)->patchJson('/api/v1/bank-accounts/'.BankAccount::where('name', 'HNB')->value('public_id'), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    });

    it('creates fiscal years and closes, reopens and locks periods with separate permissions', function () {
        asApi($this->user)->postJson('/api/v1/fiscal-years', ['start_year' => FiscalYear::first()->starts_on->year + 1], idemKey())->assertCreated();
        asApi($this->user)->getJson('/api/v1/fiscal-years')->assertOk()->assertJsonCount(2, 'data')->assertJsonCount(12, 'data.0.periods');
        asApi($this->user)->postJson('/api/v1/fiscal-years', ['start_year' => FiscalYear::first()->starts_on->year], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'finance.year_overlaps');

        $period = FiscalPeriod::orderBy('starts_on')->first();
        $manager = userWithPermissions('finance.view', 'finance.period.manage');
        asApi($manager)->postJson("/api/v1/fiscal-periods/{$period->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        asApi($manager)->postJson("/api/v1/fiscal-periods/{$period->id}/lock")->assertForbidden();
        asApi($this->user)->postJson("/api/v1/fiscal-periods/{$period->id}/lock")->assertOk()->assertJsonPath('data.status', 'locked');
        asApi($this->user)->postJson("/api/v1/fiscal-periods/{$period->id}/reopen")->assertStatus(422)->assertJsonPath('error.code', 'finance.period_state');
        asApi($this->user)->postJson("/api/v1/fiscal-periods/{$period->id}/explode")->assertNotFound();
    });
});

describe('journals', function () {
    it('posts a manual journal by account code, lists it and shows its lines', function () {
        $r = asApi($this->user)->postJson('/api/v1/journals', ['description' => 'Telephone bill', 'reference' => 'TEL-9', 'lines' => ($this->lines)('1500')], idemKey())->assertCreated()
            ->assertJsonPath('data.kind', 'manual')->assertJsonPath('data.total', '1500.0000')->assertJsonPath('data.lines.0.account', '2411')->assertJsonPath('data.lines.1.credit', '1500.0000');

        asApi($this->user)->getJson('/api/v1/journals/'.$r->json('data.id'))->assertOk()->assertJsonPath('data.reference', 'TEL-9');
        asApi($this->user)->getJson('/api/v1/journals?filter[kind]=manual&filter[q]=tel')->assertOk()->assertJsonCount(1, 'data');
        asApi($this->user)->getJson('/api/v1/journals?filter[kind]=system')->assertOk()->assertJsonCount(0, 'data');
    });

    it('answers problems in the standard format with the line and field', function () {
        asApi($this->user)->postJson('/api/v1/journals', ['description' => 'x', 'lines' => [['account' => '2411', 'debit' => '10'], ['account' => '1155', 'credit' => '9']]], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'finance.unbalanced');
        asApi($this->user)->postJson('/api/v1/journals', ['description' => 'x', 'lines' => [['account' => 'NOPE', 'debit' => '10'], ['account' => '1155', 'credit' => '10']]], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'finance.unknown_code')->assertJsonPath('error.details.0.field', 'lines.0.account');
        asApi($this->user)->postJson('/api/v1/journals', ['description' => 'x', 'lines' => [['account' => '1125', 'debit' => '10'], ['account' => '1155', 'credit' => '10']]], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'finance.control_account');
        asApi($this->user)->postJson('/api/v1/journals', ['description' => 'x', 'lines' => [['account' => '2411', 'debit' => 'abc'], ['account' => '1155', 'credit' => '10']]], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        expect(JournalEntry::count())->toBe(0);
    });

    it('replays the same request once (idempotency)', function () {
        $key = idemKey();
        $payload = ['description' => 'Once', 'lines' => ($this->lines)('50')];

        $first = asApi($this->user)->postJson('/api/v1/journals', $payload, $key)->assertCreated()->json('data.id');
        $again = asApi($this->user)->postJson('/api/v1/journals', $payload, $key)->assertCreated()->json('data.id');

        expect($again)->toBe($first)->and(JournalEntry::count())->toBe(1);
    });

    it('reverses with the permission, once, and refuses closed periods', function () {
        $id = asApi($this->user)->postJson('/api/v1/journals', ['description' => 'Oops', 'lines' => ($this->lines)('70')], idemKey())->json('data.id');

        asApi(userWithPermissions('finance.view', 'finance.journal.post'))->postJson("/api/v1/journals/{$id}/reverse", [], idemKey())->assertForbidden();
        asApi($this->user)->postJson("/api/v1/journals/{$id}/reverse", ['note' => 'Wrong account'], idemKey())->assertCreated()->assertJsonPath('data.kind', 'reversal')->assertJsonPath('data.description', 'Reversal of '.JournalEntry::orderBy('id')->first()->journal_no.': Wrong account');
        asApi($this->user)->getJson("/api/v1/journals/{$id}")->assertJsonPath('data.status', 'reversed');
        asApi($this->user)->postJson("/api/v1/journals/{$id}/reverse", [], idemKey())->assertStatus(409)->assertJsonPath('error.code', 'finance.already_reversed');
    });

    it('needs the permission to post', function () {
        asApi(userWithPermissions('finance.view'))->postJson('/api/v1/journals', ['description' => 'x', 'lines' => ($this->lines)('5')], idemKey())->assertForbidden();
    });

    it('goes to approval when a policy exists, posts nothing yet, and posts on approval', function () {
        $approver = userWithRole('finance-approver');
        paymentPolicy(['name' => 'Manual journals', 'action_key' => ManualJournalPost::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);

        $id = asApi($this->user)->postJson('/api/v1/journals', ['description' => 'Needs approval', 'lines' => ($this->lines)('900')], idemKey())->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')->json('data.approval_request_id');
        expect(JournalEntry::count())->toBe(0);

        asApi($this->user)->postJson('/api/v1/journals', ['description' => 'Bad', 'lines' => [['account' => '2411', 'debit' => '5'], ['account' => '1155', 'credit' => '4']]], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'finance.unbalanced');   // a bad journal is never queued
        asApi($approver)->postJson("/api/v1/approvals/{$id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'executed');

        $journal = JournalEntry::sole();
        expect($journal->description)->toBe('Needs approval')->and($journal->created_by)->toBe($this->user->id)->and($journal->kind)->toBe('manual');
    });
});

describe('receivables and payables', function () {
    beforeEach(function () {
        $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
        $this->supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
        app(OpenItemPoster::class)->postInvoice(new InvoicePosting('receivable', $this->customer->id, 'INV-1', $this->today, now('Asia/Colombo')->addDays(30)->toDateString(),
            [JournalLineData::credit('1000', 'sales_revenue'), JournalLineData::credit('180', 'vat_output')], 'sales_invoice', '1'));
        app(OpenItemPoster::class)->postInvoice(new InvoicePosting('payable', $this->supplier->id, 'BILL-1', $this->today, now('Asia/Colombo')->addDays(14)->toDateString(),
            [JournalLineData::debit('5000', 'grni')], 'purchase_invoice', '1'));
        $this->invoice = OpenItem::where('side', 'receivable')->first();
        $this->bill = OpenItem::where('side', 'payable')->first();
    });

    it('lists what is owed, the ageing, a party\'s ledger and the tie-out', function () {
        asApi($this->user)->getJson('/api/v1/receivables/open-items')->assertOk()->assertJsonPath('data.0.document_no', 'INV-1')->assertJsonPath('data.0.open_amount', '1180.0000')->assertJsonMissingPath('data.0.internal_id');
        asApi($this->user)->getJson("/api/v1/receivables/open-items?filter[party]={$this->customer->code}")->assertJsonCount(1, 'data');
        asApi($this->user)->getJson('/api/v1/receivables/ageing')->assertOk()->assertJsonPath('data.totals.current', '1180.0000');
        asApi($this->user)->getJson("/api/v1/receivables/parties/{$this->customer->code}/ledger")->assertOk()->assertJsonPath('data.closing', '1180.0000');
        asApi($this->user)->getJson('/api/v1/receivables/tie-out')->assertOk()->assertJsonPath('data.difference', '0.0000');
        asApi($this->user)->getJson('/api/v1/payables/open-items')->assertOk()->assertJsonPath('data.0.document_no', 'BILL-1');
        asApi($this->user)->getJson('/api/v1/receivables/parties/NOPE/ledger')->assertStatus(422)->assertJsonPath('error.code', 'finance.unknown_code');
    });

    it('records a receipt against an invoice by public id, then lists, shows, allocates and reverses it', function () {
        $r = asApi($this->user)->postJson('/api/v1/receivables/settlements', [
            'party' => $this->customer->code, 'bank_account' => $this->bank->public_id, 'amount' => '1000', 'method' => 'cheque', 'reference' => 'CHQ-1',
            'allocations' => [['open_item' => $this->invoice->public_id, 'amount' => '600']],
        ], idemKey())->assertCreated()->assertJsonPath('data.document_no', fn ($v) => str_starts_with($v, 'REC/'))->assertJsonPath('data.unallocated', '400.0000')
            ->assertJsonPath('data.allocations.0.document_no', 'INV-1');
        $id = $r->json('data.id');

        asApi($this->user)->getJson('/api/v1/receivables/settlements')->assertOk()->assertJsonCount(1, 'data');
        asApi($this->user)->getJson("/api/v1/receivables/settlements/{$id}")->assertOk()->assertJsonPath('data.reference', 'CHQ-1');
        asApi($this->user)->getJson('/api/v1/receivables/unallocated')->assertOk()->assertJsonPath('data.0.unallocated', '400.0000');
        asApi($this->user)->postJson("/api/v1/receivables/settlements/{$id}/allocate", ['allocations' => [['open_item' => $this->invoice->public_id, 'amount' => '400']]])->assertOk()->assertJsonPath('data.unallocated', '0.0000');
        expect($this->invoice->fresh()->open_amount)->toBe('180.0000');

        asApi($this->user)->postJson("/api/v1/receivables/settlements/{$id}/reverse", ['note' => 'Bounced'], idemKey())->assertCreated()->assertJsonPath('data.status', 'reversed');
        expect($this->invoice->fresh()->open_amount)->toBe('1180.0000');
        asApi($this->user)->getJson("/api/v1/payables/settlements/{$id}")->assertNotFound();   // a receipt is not a payment
    });

    it('refuses over-allocation and other people\'s invoices in the standard format', function () {
        $payload = ['party' => $this->customer->code, 'bank_account' => $this->bank->public_id, 'amount' => '5000', 'allocations' => [['open_item' => $this->invoice->public_id, 'amount' => '2000']]];

        asApi($this->user)->postJson('/api/v1/receivables/settlements', $payload, idemKey())->assertStatus(422)->assertJsonPath('error.code', 'finance.over_allocated')->assertJsonPath('error.details.0.field', 'allocations.0.amount');
        asApi($this->user)->postJson('/api/v1/receivables/settlements', ['allocations' => [['open_item' => $this->bill->public_id, 'amount' => '5']]] + $payload, idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'finance.open_item_invalid');
        expect(Settlement::count())->toBe(0);
    });

    it('keeps the two sides apart by permission', function () {
        $ar = userWithPermissions('finance.ar.view', 'finance.ar.receive');

        asApi($ar)->getJson('/api/v1/receivables/open-items')->assertOk();
        asApi($ar)->getJson('/api/v1/payables/open-items')->assertForbidden();
        asApi($ar)->postJson('/api/v1/payables/settlements', ['party' => $this->supplier->code, 'bank_account' => $this->bank->public_id, 'amount' => '5'], idemKey())->assertForbidden();
        asApi(userWithPermissions('finance.ar.view'))->postJson('/api/v1/receivables/settlements', ['party' => $this->customer->code, 'bank_account' => $this->bank->public_id, 'amount' => '5'], idemKey())->assertForbidden();
    });

    it('pays a supplier at once without a policy, and via approval with one', function () {
        $pay = fn (string $amount) => asApi($this->user)->postJson('/api/v1/payables/settlements', [
            'party' => $this->supplier->code, 'bank_account' => $this->bank->public_id, 'amount' => $amount, 'allocations' => [['open_item' => $this->bill->public_id, 'amount' => $amount]],
        ], idemKey());

        $pay('1000')->assertCreated()->assertJsonPath('data.document_no', fn ($v) => str_starts_with($v, 'PAY/'));

        $approver = userWithRole('finance-approver');
        paymentPolicy(['name' => 'Supplier payments', 'action_key' => SupplierPaymentPost::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);
        $id = $pay('2000')->assertStatus(202)->assertJsonPath('data.status', 'pending')->json('data.approval_request_id');
        expect(Settlement::count())->toBe(1)->and($this->bill->fresh()->open_amount)->toBe('4000.0000');

        $pay('999999')->assertStatus(422)->assertJsonPath('error.code', 'finance.over_allocated');   // a bad payment is never queued
        asApi($approver)->postJson("/api/v1/approvals/{$id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'executed');
        expect(Settlement::count())->toBe(2)->and($this->bill->fresh()->open_amount)->toBe('2000.0000');
    });

    it('does not ask for approval for receipts', function () {
        userWithRole('finance-approver');
        paymentPolicy(['name' => 'Supplier payments', 'action_key' => SupplierPaymentPost::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);

        asApi($this->user)->postJson('/api/v1/receivables/settlements', ['party' => $this->customer->code, 'bank_account' => $this->bank->public_id, 'amount' => '100'], idemKey())->assertCreated();
    });
});

describe('reports', function () {
    it('serves the trial balance, general ledger, profit and loss and balance sheet', function () {
        asApi($this->user)->postJson('/api/v1/journals', ['description' => 'Capital', 'lines' => [['account' => '1155', 'debit' => '10000'], ['account' => '1210', 'credit' => '10000']]], idemKey());
        asApi($this->user)->postJson('/api/v1/journals', ['description' => 'Sale', 'lines' => [['account' => '1155', 'debit' => '2000'], ['account' => '2000', 'credit' => '2000']]], idemKey());

        asApi($this->user)->getJson('/api/v1/reports/trial-balance')->assertOk()->assertJsonPath('data.total_debit', '12000.0000')->assertJsonPath('data.total_credit', '12000.0000')->assertJsonCount(3, 'data.rows');
        asApi($this->user)->getJson('/api/v1/reports/general-ledger?account=1155')->assertOk()->assertJsonPath('data.closing', '12000.0000')->assertJsonPath('data.account.name', 'BANK OF CEYLON- LKR- 0089695095')->assertJsonCount(2, 'data.lines');
        asApi($this->user)->getJson('/api/v1/reports/profit-and-loss?from='.FiscalYear::first()->starts_on->toDateString())->assertOk()->assertJsonPath('data.net_profit', '2000.0000');
        asApi($this->user)->getJson('/api/v1/reports/balance-sheet')->assertOk()->assertJsonPath('data.total_assets', '12000.0000')->assertJsonPath('data.total_equity', '12000.0000')->assertJsonPath('data.year_profit', '2000.0000');
        asApi($this->user)->getJson('/api/v1/reports/general-ledger?account=NOPE')->assertStatus(422);
        asApi($this->user)->getJson('/api/v1/reports/profit-and-loss')->assertStatus(422);
        asApi(userWithPermissions('finance.view'))->getJson('/api/v1/reports/trial-balance')->assertForbidden();
    });
});

it('is unavailable when the module is switched off', function () {
    app(ModuleRegistry::class)->disable('manufacturing');
    app(ModuleRegistry::class)->disable('procurement');
    app(ModuleRegistry::class)->disable('sales');
    app(ModuleRegistry::class)->disable('finance');

    asApi($this->user)->getJson('/api/v1/accounts')->assertForbidden()->assertJsonPath('error.code', 'core.module_disabled');
});
