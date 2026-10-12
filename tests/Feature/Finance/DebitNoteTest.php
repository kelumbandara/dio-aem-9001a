<?php

use App\Modules\Finance\Contracts\DebitNotePoster;
use App\Modules\Finance\Contracts\OpenItemPoster;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\DTOs\DebitNotePosting;
use App\Modules\Finance\DTOs\InvoicePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Support\Exceptions\BusinessException;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
    $this->today = now('Asia/Colombo')->toDateString();
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    $this->bill = fn (array $lines, string $no = 'BILL-1') => app(OpenItemPoster::class)->postInvoice(new InvoicePosting('payable', $this->supplier->id, $no, $this->today, $this->today, $lines, 'purchase_bill', $no));
});

it('accepts a bill with a credit line when the price is lower than the goods received', function () {
    // goods received 1,000; billed 950 + VAT 171: grni cleared 1,000, favourable variance 50 credited
    ($this->bill)([JournalLineData::debit('1000', 'grni'), JournalLineData::credit('50', 'purchase_variance'), JournalLineData::debit('171', 'vat_input')]);

    expect(OpenItem::sole()->amount)->toBe('1121.0000')->and(($this->bal)('1500'))->toBe('-1121.0000')->and(($this->bal)('2217'))->toBe('-50.0000');
    expect(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});

it('still refuses a bill that comes to nothing or has a line on both sides', function () {
    $fail = function (Closure $fn) {
        try {
            $fn();
        } catch (BusinessException $e) {
            expect($e->errorCode)->toBe('finance.lines_invalid');

            return;
        }
        test()->fail('Expected finance.lines_invalid');
    };
    $fail(fn () => ($this->bill)([JournalLineData::debit('100', 'grni'), JournalLineData::credit('100', 'purchase_variance')], 'B2'));
    $fail(fn () => ($this->bill)([JournalLineData::credit('100', 'purchase_variance')], 'B3'));
});

it('posts a debit note that reduces the bill, with a non-cash settlement', function () {
    ($this->bill)([JournalLineData::debit('1000', 'grni'), JournalLineData::debit('180', 'vat_input')]);
    $note = fn (array $o = []) => app(DebitNotePoster::class)->postDebitNote(new DebitNotePosting(...array_merge([
        'supplierId' => $this->supplier->id, 'documentNo' => 'PR/1', 'documentDate' => $this->today, 'billNo' => 'BILL-1',
        'lines' => [JournalLineData::credit('400', 'grni'), JournalLineData::credit('72', 'vat_input')], 'sourceType' => 'purchase_return', 'sourceId' => 'R1', 'reason' => 'Damaged',
    ], $o)));

    $note();
    expect(OpenItem::sole()->open_amount)->toBe('708.0000')->and(($this->bal)('1500'))->toBe('-708.0000')
        ->and(Settlement::sole()->only(['method', 'side', 'bank_account_id', 'amount']))->toBe(['method' => 'debit_note', 'side' => 'payable', 'bank_account_id' => null, 'amount' => '472.0000']);
    expect($note()->replayed)->toBeTrue()->and(Settlement::count())->toBe(1);
    expect(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});

it('refuses debit lines, an unknown bill and a repeated number', function () {
    ($this->bill)([JournalLineData::debit('1000', 'grni')]);
    $post = fn (array $o) => app(DebitNotePoster::class)->postDebitNote(new DebitNotePosting(...array_merge([
        'supplierId' => $this->supplier->id, 'documentNo' => 'PR/1', 'documentDate' => $this->today, 'billNo' => 'BILL-1', 'lines' => [JournalLineData::credit('10', 'grni')],
    ], $o)));
    $fail = function (Closure $fn, string $code) {
        try {
            $fn();
        } catch (BusinessException $e) {
            expect($e->errorCode)->toBe($code);

            return;
        }
        test()->fail("Expected {$code}");
    };
    $fail(fn () => $post(['lines' => [JournalLineData::debit('10', 'grni')], 'sourceId' => 'x']), 'finance.lines_invalid');
    $fail(fn () => $post(['billNo' => 'NOPE', 'sourceId' => 'y']), 'finance.open_item_invalid');
    $post(['sourceId' => 'z']);
    $fail(fn () => $post(['sourceId' => 'w']), 'finance.document_exists');
});
