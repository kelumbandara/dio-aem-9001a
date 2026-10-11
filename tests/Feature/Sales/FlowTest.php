<?php

use App\Modules\Core\Services\OutboxRelay;
use App\Modules\Finance\Contracts\ReceivablePosition;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\Services\StockReconciler;
use App\Modules\MasterData\Actions\Reference\AddTaxRate;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\OrderLine;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function salesFails(Closure $fn, string $code): void
{
    try {
        $fn();
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected business error {$code}.");
}

beforeEach(function () {
    config(['sales.prices_include_vat' => false]);   // these tests work in net prices; VAT-inclusive pricing has its own tests
    $this->seed(FinanceSeeder::class);
    // Levies are created inactive and without a rate: the accountant switches them on.
    TaxCode::where('code', 'SSCL')->update(['is_active' => true]);
    app(AddTaxRate::class)(TaxCode::where('code', 'SSCL')->first(), '2.5', '2024-01-01');
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->item = mdItem(['name' => 'Widget', 'default_tax_code' => 'VAT']);
    invReceive($this->item, $this->wh, '100', '60');
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    $this->relay = fn () => app(OutboxRelay::class)->run(2000);
    $this->orders = app(OrderService::class);
    $this->checks = function () {
        expect(app(FinanceReconciler::class)->discrepancies())->toBe([])->and(app(StockReconciler::class)->discrepancies())->toBe([]);
    };
    $this->draft = fn (array $o = [], string $qty = '10', string $price = '100') => $this->orders->save($o + [
        'customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id,
        'lines' => [['item_id' => $this->item->id, 'qty' => $qty, 'unit_price' => $price]],
    ]);
    $this->confirmed = fn (array $o = [], string $qty = '10') => $this->orders->confirm(($this->draft)($o, $qty));
});

describe('quotation to invoice', function () {
    it('runs the whole flow and posts VAT and SSCL on the liable turnover', function () {
        $quotes = app(QuotationService::class);
        $q = $quotes->save(['customer_id' => $this->customer->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '10', 'unit_price' => '100']]]);
        expect($q->document_no)->toStartWith('QUO/')->and($q->gross_total)->toBe('1205.0000')->and($q->vat_total)->toBe('180.0000')->and($q->levy_total)->toBe('25.0000');

        $quotes->transition($q, 'sent');
        $quotes->transition($q->refresh(), 'accepted');
        $order = $this->orders->fromQuotation($q->refresh(), ['warehouse_id' => $this->wh->id]);
        expect($order->status)->toBe('draft')->and($order->quotation_id)->toBe($q->id)->and($q->refresh()->status)->toBe('converted');

        $order = $this->orders->confirm($order);
        expect($order->status)->toBe('confirmed')->and($order->lines->first()->reservation_id)->not->toBeNull();

        $delivery = app(DeliveryService::class)->post($order, []);
        expect($delivery->document_no)->toStartWith('DN/')->and(invOnHand($this->item, $this->wh))->toBe('90.000000');

        $invoice = app(InvoiceService::class)->create($order->refresh(), []);
        expect($invoice->document_no)->toMatch('#^INV/\d{4}-\d{2}/\d{5}$#')
            ->and($invoice->only(['net_total', 'vat_total', 'levy_total', 'gross_total']))->toBe(['net_total' => '1000.0000', 'vat_total' => '180.0000', 'levy_total' => '25.0000', 'gross_total' => '1205.0000'])
            ->and($invoice->taxes->map(fn ($t) => [$t->kind, $t->rate, $t->base_amount, $t->amount])->all())->toBe([['vat', '18.0000', '1000.0000', '180.0000'], ['sscl', '2.5000', '1000.0000', '25.0000']])
            ->and($order->refresh()->status)->toBe('completed');

        ($this->relay)();
        expect(($this->bal)('1125'))->toBe('1205.0000')->and(($this->bal)('2000'))->toBe('-1000.0000')->and(($this->bal)('1524'))->toBe('-180.0000')
            ->and(($this->bal)('1525'))->toBe('-25.0000')->and(($this->bal)('2206'))->toBe('600.0000');
        expect(OpenItem::sole()->open_amount)->toBe('1205.0000');
        ($this->checks)();
    });

    it('rejects an expired quotation and edits to anything but a draft', function () {
        $quotes = app(QuotationService::class);
        $q = $quotes->save(['customer_id' => $this->customer->id, 'quote_date' => '2026-01-01', 'valid_until' => '2026-01-31', 'lines' => [['item_id' => $this->item->id, 'qty' => '1', 'unit_price' => '10']]]);
        $quotes->transition($q, 'sent');
        salesFails(fn () => $quotes->transition($q->refresh(), 'accepted'), 'sales.quotation_expired');
        salesFails(fn () => $quotes->save(['customer_id' => $this->customer->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '1', 'unit_price' => '10']]], $q->refresh()), 'sales.invalid_state');
    });

    it('does not charge SSCL on exempt lines and uses the tax code of the item', function () {
        $service = mdItem(['name' => 'Consulting', 'item_type' => 'service', 'default_tax_code' => 'EXEMPT']);
        $order = $this->orders->save([
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id,
            'lines' => [['item_id' => $this->item->id, 'qty' => '1', 'unit_price' => '1000'], ['item_id' => $service->id, 'qty' => '1', 'unit_price' => '500']],
        ]);
        // VAT 18% on 1000 only; SSCL 2.5% on the liable 1000 only (the exempt service is outside it).
        expect($order->only(['net_total', 'vat_total', 'levy_total', 'gross_total']))->toBe(['net_total' => '1500.0000', 'vat_total' => '180.0000', 'levy_total' => '25.0000', 'gross_total' => '1705.0000']);
    });

    it('charges a levy only while a rate is in force', function () {
        $nbt = TaxCode::where('code', 'NBT')->first();
        expect(($this->draft)()->levy_total)->toBe('25.0000');
        app(AddTaxRate::class)($nbt, '2', '2024-01-01');
        expect(($this->draft)()->levy_total)->toBe('25.0000');   // NBT still inactive
        $nbt->update(['is_active' => true]);
        expect(($this->draft)()->levy_total)->toBe('45.0000');
    });
});

describe('credit limit', function () {
    beforeEach(function () {
        $this->limited = app(SaveCustomer::class)(['name' => 'Small Shop', 'credit_limit' => '1500']);
        $this->limitedOrder = fn (string $qty = '10') => $this->orders->save([
            'customer_id' => $this->limited->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => $qty, 'unit_price' => '100']],
        ]);
    });

    it('blocks confirmation above the limit, counting open orders and unpaid invoices', function () {
        $first = $this->orders->confirm(($this->limitedOrder)('10'));      // 1,205 of 1,500
        expect($first->status)->toBe('confirmed');
        salesFails(fn () => $this->orders->confirm(($this->limitedOrder)('5')), 'sales.credit_limit_exceeded');   // +602.50 -> 1,807.50

        app(DeliveryService::class)->post($first, []);
        app(InvoiceService::class)->create($first->refresh(), []);       // now a receivable, still counted once
        salesFails(fn () => $this->orders->confirm(($this->limitedOrder)('5')), 'sales.credit_limit_exceeded');
        expect(app(ReceivablePosition::class)->outstanding($this->limited->id))->toBe('1205.0000');
    });

    it('lets only someone allowed to override do so, with a reason, and records it', function () {
        $draft = ($this->limitedOrder)('20');
        salesFails(fn () => $this->orders->confirm($draft, null, false, 'urgent'), 'sales.credit_limit_exceeded');
        salesFails(fn () => $this->orders->confirm($draft, null, true, '   '), 'sales.credit_limit_exceeded');

        $order = $this->orders->confirm($draft, null, true, 'Paid in advance by cheque');
        expect($order->status)->toBe('confirmed')->and($order->credit_override_reason)->toBe('Paid in advance by cheque');
    });

    it('has no limit for a customer without one', function () {
        expect(($this->confirmed)(['customer_id' => $this->customer->id], '50')->status)->toBe('confirmed');
    });
});

describe('stock and deliveries', function () {
    it('reserves what is available when confirming and never fails for a shortage', function () {
        $order = ($this->confirmed)([], '150');
        expect($order->status)->toBe('confirmed')->and($order->lines->first()->reservation_id)->not->toBeNull();
        expect(app(StockAvailability::class)->level($this->item->id, $this->wh->id)->reserved)->toBe('100.000000');
    });

    it('refuses to deliver more than is on hand and leaves nothing behind', function () {
        $order = ($this->confirmed)([], '150');
        salesFails(fn () => app(DeliveryService::class)->post($order, []), 'inventory.insufficient_stock');
        expect(invOnHand($this->item, $this->wh))->toBe('100.000000')->and(DB::table('sales_deliveries')->count())->toBe(0)
            ->and(OrderLine::first()->qty_delivered)->toBe('0.000000');
    });

    it('delivers in parts, re-reserving the rest, and invoices only what was delivered', function () {
        $order = ($this->confirmed)([], '150');
        $d = app(DeliveryService::class);
        $d->post($order, ['lines' => [['line' => 1, 'qty' => '100']]]);
        expect(invOnHand($this->item, $this->wh))->toBe('0.000000');
        salesFails(fn () => $d->post($order->refresh(), ['lines' => [['line' => 1, 'qty' => '51']]]), 'sales.qty_exceeds_open');

        $inv = app(InvoiceService::class);
        salesFails(fn () => $inv->create($order->refresh(), ['lines' => [['line' => 1, 'qty' => '101']]]), 'sales.qty_exceeds_billable');
        $first = $inv->create($order->refresh(), []);
        expect($first->net_total)->toBe('10000.0000');

        invReceive($this->item, $this->wh, '50', '60');
        $d->post($order->refresh(), []);
        $second = $inv->create($order->refresh(), []);
        expect($second->net_total)->toBe('5000.0000')->and($order->refresh()->status)->toBe('completed');
        expect(($this->bal)('1125'))->toBe(bcadd($first->gross_total, $second->gross_total, 4));
    });

    it('reverses an uninvoiced delivery and puts the stock back', function () {
        $order = ($this->confirmed)();
        $delivery = app(DeliveryService::class)->post($order, []);
        app(DeliveryService::class)->reverse($delivery, 'wrong customer');

        expect(invOnHand($this->item, $this->wh))->toBe('100.000000')->and($delivery->refresh()->status)->toBe('reversed')
            ->and($order->lines()->first()->qty_delivered)->toBe('0.000000');
        ($this->relay)();
        expect(($this->bal)('2206'))->toBe('0.0000');
        ($this->checks)();
    });

    it('will not reverse a delivery that has been invoiced', function () {
        $order = ($this->confirmed)();
        $delivery = app(DeliveryService::class)->post($order, []);
        app(InvoiceService::class)->create($order->refresh(), []);
        salesFails(fn () => app(DeliveryService::class)->reverse($delivery), 'sales.delivery_invoiced');
    });

    it('cancels an order that has not started and releases its reservation', function () {
        $order = ($this->confirmed)();
        $this->orders->cancel($order, 'Customer changed mind');
        expect($order->refresh()->status)->toBe('cancelled')
            ->and(app(StockAvailability::class)->level($this->item->id, $this->wh->id)->reserved)->toBe('0.000000');

        $started = ($this->confirmed)();
        app(DeliveryService::class)->post($started, []);
        salesFails(fn () => $this->orders->cancel($started->refresh(), 'oops'), 'sales.order_in_progress');
    });

    it('invoices a service by its ordered quantity without any delivery', function () {
        $service = mdItem(['name' => 'Installation', 'item_type' => 'service', 'default_tax_code' => 'VAT']);
        $order = $this->orders->confirm($this->orders->save([
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $service->id, 'qty' => '2', 'unit_price' => '750']],
        ]));
        $invoice = app(InvoiceService::class)->create($order, []);
        expect($invoice->gross_total)->toBe('1807.5000')->and($order->refresh()->status)->toBe('completed');
        ($this->checks)();
    });
});

describe('credit notes', function () {
    beforeEach(function () {
        $this->order = ($this->confirmed)();
        app(DeliveryService::class)->post($this->order, []);
        $this->invoice = app(InvoiceService::class)->create($this->order->refresh(), []);
        $this->notes = app(SalesCreditNoteService::class);
    });

    it('credits part of an invoice with its VAT and SSCL, settles the invoice and returns stock', function () {
        $note = $this->notes->create($this->invoice, ['reason' => 'Damaged', 'restock' => true, 'lines' => [['line' => 1, 'qty' => '4']]]);

        expect($note->only(['net_total', 'vat_total', 'levy_total', 'gross_total']))->toBe(['net_total' => '400.0000', 'vat_total' => '72.0000', 'levy_total' => '10.0000', 'gross_total' => '482.0000'])
            ->and(OpenItem::sole()->open_amount)->toBe('723.0000')->and(invOnHand($this->item, $this->wh))->toBe('94.000000');
        ($this->relay)();
        expect(($this->bal)('1125'))->toBe('723.0000')->and(($this->bal)('2000'))->toBe('-600.0000')->and(($this->bal)('1525'))->toBe('-15.0000')
            ->and(($this->bal)('2206'))->toBe('360.0000');   // 6 units still sold at the cost of 60
        ($this->checks)();
    });

    it('never credits more than was invoiced and ends exactly at zero', function () {
        $this->notes->create($this->invoice, ['reason' => 'Part', 'lines' => [['line' => 1, 'qty' => '3']]]);
        salesFails(fn () => $this->notes->create($this->invoice, ['reason' => 'Too much', 'lines' => [['line' => 1, 'qty' => '7.5']]]), 'sales.qty_exceeds_invoice');

        $this->notes->create($this->invoice, ['reason' => 'Rest']);
        salesFails(fn () => $this->notes->create($this->invoice, ['reason' => 'Again']), 'sales.nothing_to_credit');
        expect(OpenItem::sole()->open_amount)->toBe('0.0000')->and(($this->bal)('1125'))->toBe('0.0000')->and(($this->bal)('2000'))->toBe('0.0000')
            ->and(($this->bal)('1524'))->toBe('0.0000')->and(($this->bal)('1525'))->toBe('0.0000');
        ($this->checks)();
    });

    it('needs a reason and refuses to restock lot-tracked goods without a lot', function () {
        salesFails(fn () => $this->notes->create($this->invoice, ['reason' => ' ']), 'sales.reason_required');
        $this->notes->create($this->invoice, ['reason' => 'Return', 'restock' => true, 'lines' => [['line' => 1, 'qty' => '1']]]);
        expect(invOnHand($this->item, $this->wh))->toBe('91.000000');
    });
});

describe('posted documents are immutable', function () {
    it('lets the database refuse changes to a posted invoice', function () {
        $order = ($this->confirmed)();
        app(DeliveryService::class)->post($order, []);
        $invoice = app(InvoiceService::class)->create($order->refresh(), []);

        foreach (["UPDATE sales_invoices SET net_total = 1 WHERE id = {$invoice->id}", "DELETE FROM sales_invoices WHERE id = {$invoice->id}", "UPDATE sales_invoice_lines SET qty = 1 WHERE invoice_id = {$invoice->id}"] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                test()->fail("Expected the database to refuse: {$sql}");
            } catch (QueryException $e) {
                expect($e->getMessage())->toContain('immutable');
            }
        }
        expect(Invoice::count())->toBe(1)->and(JournalEntry::where('source_type', 'receivable:sales_invoice')->count())->toBe(1);
    });
});
