<?php

use App\Modules\Finance\Contracts\CreditNotePoster;
use App\Modules\Finance\Contracts\OpenItemPoster;
use App\Modules\Finance\Contracts\ReceivablePosition;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\DTOs\CreditNotePosting;
use App\Modules\Finance\DTOs\InvoicePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Support\Exceptions\BusinessException;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
    $this->today = now('Asia/Colombo')->toDateString();
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    app(OpenItemPoster::class)->postInvoice(new InvoicePosting(
        side: 'receivable', partyId: $this->customer->id, documentNo: 'INV-1', documentDate: $this->today, dueDate: now('Asia/Colombo')->addDays(30)->toDateString(),
        lines: [JournalLineData::credit('1000', 'sales_revenue'), JournalLineData::credit('180', 'vat_output')], sourceType: 'sales_invoice', sourceId: 'INV-1',
    ));
    $this->note = fn (array $o = []) => app(CreditNotePoster::class)->postCreditNote(new CreditNotePosting(...array_merge([
        'customerId' => $this->customer->id, 'documentNo' => 'CN-1', 'documentDate' => $this->today, 'invoiceNo' => 'INV-1',
        'lines' => [JournalLineData::debit('400', 'sales_revenue'), JournalLineData::debit('72', 'vat_output')], 'sourceType' => 'sales_return', 'sourceId' => 'R1',
    ], $o)));
});

it('credits receivables and settles the named invoice', function () {
    ($this->note)();

    expect(OpenItem::sole()->open_amount)->toBe('708.0000')
        ->and(($this->bal)('1125'))->toBe('708.0000')
        ->and(Settlement::sole()->only(['method', 'bank_account_id', 'amount']))->toBe(['method' => 'credit_note', 'bank_account_id' => null, 'amount' => '472.0000'])
        ->and(app(ReceivablePosition::class)->outstanding($this->customer->id))->toBe('708.0000')
        ->and(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});

it('is idempotent by source', function () {
    ($this->note)();
    $again = ($this->note)();

    expect($again->replayed)->toBeTrue()->and(Settlement::count())->toBe(1);
});

it('caps the allocation at the invoice balance and keeps the rest as credit on account', function () {
    ($this->note)(['documentNo' => 'CN-2', 'sourceId' => 'R2', 'lines' => [JournalLineData::debit('1500', 'sales_revenue')]]);

    expect(OpenItem::sole()->open_amount)->toBe('0.0000')
        ->and(app(ReceivablePosition::class)->outstanding($this->customer->id))->toBe('0.0000');
});

it('rejects credit lines, unknown invoices and duplicate numbers', function () {
    $fail = function (Closure $fn, string $code) {
        try {
            $fn();
        } catch (BusinessException $e) {
            expect($e->errorCode)->toBe($code);

            return;
        }
        test()->fail("Expected {$code}");
    };
    $fail(fn () => ($this->note)(['lines' => [JournalLineData::credit('5', 'sales_revenue')]]), 'finance.lines_invalid');
    $fail(fn () => ($this->note)(['invoiceNo' => 'NOPE']), 'finance.open_item_invalid');
    ($this->note)();
    $fail(fn () => ($this->note)(['sourceId' => 'R9']), 'finance.document_exists');
});

it('lists only active LKR banks flagged for invoices', function () {
    $bank = BankAccount::where('currency', 'LKR')->where('is_active', true)->first();
    expect(app(ReceivablePosition::class)->invoiceBankAccounts())->toBe([]);
    $bank->update(['show_on_invoices' => true]);
    expect(app(ReceivablePosition::class)->invoiceBankAccounts())->toHaveCount(1);
});
