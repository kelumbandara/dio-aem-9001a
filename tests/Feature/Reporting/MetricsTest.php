<?php

use App\Modules\Core\Services\OutboxRelay;
use App\Modules\Finance\Contracts\FinanceMetrics;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\Inventory\Contracts\StockMetrics;
use App\Modules\Manufacturing\Contracts\ProductionMetrics;
use App\Modules\Manufacturing\Services\OutputService;
use App\Modules\Manufacturing\Services\SetupService;
use App\Modules\Manufacturing\Services\ShiftTargetService;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\Procurement\Contracts\PurchasingMetrics;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\PurchaseReturnService;
use App\Modules\Procurement\Services\SupplierBillService;
use App\Modules\Sales\Contracts\SalesMetrics;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\SalesCreditNoteService;
use Carbon\CarbonImmutable;

/** Decimal strings are equal whatever their trailing zeros. */
function rptEq(?string $a, string $b): bool
{
    return $a !== null && bccomp($a, $b, 6) === 0;
}

beforeEach(function () {
    config(['sales.prices_include_vat' => false, 'procurement.prices_include_vat' => false]);
    $this->seed(FinanceSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00', 'Asia/Colombo'));
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    $this->relay = fn () => app(OutboxRelay::class)->run(2000);

    // Sales: 10 widgets at 100 delivered and invoiced (net 1,000, VAT 180), then 4 credited and restocked. Stock cost is 60 each.
    $this->widget = mdItem(['name' => 'Widget', 'default_tax_code' => 'VAT']);
    invReceive($this->widget, $this->wh, '100', '60');
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
    $order = app(OrderService::class)->confirm(app(OrderService::class)->save([
        'customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->widget->id, 'qty' => '10', 'unit_price' => '100']],
    ]));
    app(DeliveryService::class)->post($order, []);
    $this->invoice = app(InvoiceService::class)->create($order->refresh(), []);
    app(SalesCreditNoteService::class)->create($this->invoice, ['reason' => 'Damaged', 'restock' => true, 'lines' => [['line' => 1, 'qty' => '4']]]);

    // Purchasing: 10 gadgets at 50 ordered, received and billed, then 3 returned.
    $this->gadget = mdItem(['name' => 'Gadget', 'default_tax_code' => 'VAT']);
    $this->supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
    $buyer = userWithPermissions('procurement.order.manage');
    $po = app(PurchaseOrderService::class);
    $this->po = $po->submit($po->save(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->gadget->id, 'qty' => '10', 'unit_price' => '50']]]), $buyer->id);
    app(GoodsReceiptService::class)->post($this->po, []);
    app(SupplierBillService::class)->create($this->po->refresh(), ['supplier_invoice_no' => 'SUP-001']);
    app(PurchaseReturnService::class)->create($this->po->refresh(), ['reason' => 'Wrong', 'bill' => 'SUP-001', 'lines' => [['line' => 1, 'qty' => '3']]]);
    ($this->relay)();
});

describe('sales figures', function () {
    it('counts invoices and deducts credit notes, and agrees with the ledger income', function () {
        $m = app(SalesMetrics::class)->summary('2026-11-01', '2026-11-30');
        expect($m['invoices'])->toBe(1)->and(rptEq($m['net_invoiced'], '1000'))->toBeTrue()->and(rptEq($m['gross_invoiced'], '1180'))->toBeTrue()
            ->and($m['credit_notes'])->toBe(1)->and(rptEq($m['net_credited'], '400'))->toBeTrue()
            ->and(rptEq($m['net_sales'], '600'))->toBeTrue()->and(rptEq($m['average_invoice'], '1000'))->toBeTrue()
            ->and($m['orders_confirmed'])->toBe(1)->and($m['deliveries'])->toBe(1);

        $profit = app(FinanceMetrics::class)->profit('2026-11-01', '2026-11-30');
        expect(rptEq($profit['income'], $m['net_sales']))->toBeTrue();                    // two independent routes to the same number
    });

    it('is empty for a period without sales and only sees the days inside the period', function () {
        $m = app(SalesMetrics::class);
        expect($m->summary('2026-10-01', '2026-10-31')['invoices'])->toBe(0)->and(rptEq($m->summary('2026-10-01', '2026-10-31')['net_sales'], '0'))->toBeTrue()
            ->and(rptEq($m->summary('2026-10-01', '2026-10-31')['average_invoice'], '0'))->toBeTrue()
            ->and($m->summary('2026-11-05', '2026-11-30')['invoices'])->toBe(0)
            ->and($m->summary('2026-11-04', '2026-11-04')['invoices'])->toBe(1);           // both ends of the range are included
    });

    it('gives one row per day, zero where nothing happened, adding up to the total', function () {
        $days = app(SalesMetrics::class)->dailyNet('2026-11-01', '2026-11-07');
        expect($days)->toHaveCount(7)->and($days[0]['date'])->toBe('2026-11-01')->and(rptEq($days[3]['net'], '600'))->toBeTrue()->and(rptEq($days[0]['net'], '0'))->toBeTrue();
        expect(rptEq(array_reduce($days, fn ($c, $d) => bcadd($c, $d['net'], 2), '0'), '600'))->toBeTrue();
    });

    it('ranks customers and items after credit notes and lists the largest invoices', function () {
        $m = app(SalesMetrics::class);
        $top = $m->topCustomers('2026-11-01', '2026-11-30');
        expect($top)->toHaveCount(1)->and($top[0]['name'])->toBe('Lanka Traders')->and(rptEq($top[0]['net'], '600'))->toBeTrue()->and($top[0]['public_id'])->not->toBe('');
        $items = $m->topItems('2026-11-01', '2026-11-30');
        expect($items[0]['name'])->toBe('Widget')->and(rptEq($items[0]['qty'], '6'))->toBeTrue()->and(rptEq($items[0]['net'], '600'))->toBeTrue();
        expect($m->largestInvoices('2026-11-01', '2026-11-30')[0]['document_no'])->toBe($this->invoice->document_no);
    });
});

describe('purchasing figures', function () {
    it('counts the order, receipt and bill and deducts returns', function () {
        $m = app(PurchasingMetrics::class)->summary('2026-11-01', '2026-11-30');
        expect($m['orders_approved'])->toBe(1)->and(rptEq($m['orders_value'], '500'))->toBeTrue()->and($m['receipts'])->toBe(1)->and(rptEq($m['receipts_value'], '500'))->toBeTrue()
            ->and($m['bills'])->toBe(1)->and(rptEq($m['bills_net'], '500'))->toBeTrue()->and($m['returns'])->toBe(1)->and(rptEq($m['returns_net'], '150'))->toBeTrue()
            ->and(rptEq($m['net_purchases'], '350'))->toBeTrue();
        expect(app(PurchasingMetrics::class)->summary('2026-10-01', '2026-10-31')['bills'])->toBe(0);
    });

    it('ranks suppliers and reopens the returned quantity as still to receive', function () {
        $m = app(PurchasingMetrics::class);
        $top = $m->topSuppliers('2026-11-01', '2026-11-30');
        expect($top[0]['name'])->toBe('ACME Supplies')->and(rptEq($top[0]['net'], '350'))->toBeTrue();
        $open = $m->openOrders();
        expect($open['orders'])->toBe(1)->and(rptEq($open['to_receive'], '150'))->toBeTrue()->and($open['list'][0]['supplier'])->toContain('ACME');   // the 3 returned come back as due
        $days = $m->dailyNet('2026-11-01', '2026-11-07');
        expect($days)->toHaveCount(7)->and(rptEq(array_reduce($days, fn ($c, $d) => bcadd($c, $d['net'], 2), '0'), '350'))->toBeTrue();
    });
});

describe('stock figures', function () {
    it('values stock at average cost and agrees with the inventory account in the ledger', function () {
        $v = app(StockMetrics::class)->valuation();
        expect(rptEq($v['total_value'], ($this->bal)('1100')))->toBeTrue()->and($v['items_in_stock'])->toBe(2);
        expect(rptEq(array_reduce($v['by_warehouse'], fn ($c, $w) => bcadd($c, $w['value'], 2), '0'), $v['total_value']))->toBeTrue();
        expect(app(StockMetrics::class)->topValueItems(1)[0]['name'])->toBe('Widget');
    });

    it('reports what came in and went out, leaving transfers out', function () {
        $m = app(StockMetrics::class)->movement('2026-11-04', '2026-11-04');
        expect(rptEq($m['net'], bcsub($m['inflow'], $m['outflow'], 2)))->toBeTrue()->and(bccomp($m['inflow'], '0', 2))->toBe(1)->and(bccomp($m['outflow'], '0', 2))->toBe(1);
        $other = app(StockMetrics::class)->movement('2026-10-01', '2026-10-31');
        expect(rptEq($other['inflow'], '0'))->toBeTrue()->and($other['by_reason'])->toBe([]);
    });
});

describe('finance figures', function () {
    it('gives profit that matches the ledger and open items that match the invoice', function () {
        $f = app(FinanceMetrics::class);
        $p = $f->profit('2026-11-01', '2026-11-30');
        expect(rptEq($p['income'], '600'))->toBeTrue()->and(rptEq($p['cost_of_sales'], '360'))->toBeTrue()->and(rptEq($p['gross_profit'], '240'))->toBeTrue();
        $ar = $f->openItems('receivable');
        expect(rptEq($ar['total'], '708'))->toBeTrue()->and($ar['top'][0]['party'])->toContain('Lanka Traders');         // 1,180 less the 472 credit note
        $ap = $f->openItems('payable');
        expect(bccomp($ap['total'], '0', 2))->toBe(1);
        expect(rptEq(array_reduce($ar['buckets'], fn ($c, $b) => bcadd($c, $b, 2), '0'), $ar['total']))->toBeTrue();
    });
});

describe('production figures', function () {
    beforeEach(function () {
        $shirt = mdItem(['name' => 'Shirt', 'base_uom' => 'PCS']);
        $fabric = mdItem(['name' => 'Cotton fabric', 'base_uom' => 'KG']);
        mfgBom($shirt, [['item' => $fabric, 'qty' => '2']]);
        app(SetupService::class)->save('shifts', ['code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'days' => '1111111']);
        app(ShiftTargetService::class)->save(['shift' => 'DAY', 'item' => $shirt->code, 'target' => '40']);
        $user = userWithPermissions('manufacturing.output.report', 'manufacturing.order.release');
        $order = mfgReleased($shirt, '1000', $this->wh);
        app(OutputService::class)->report($order->fresh(), ['shift' => 'DAY', 'date' => '2026-11-04', 'produced' => '40', 'rejected' => '5', 'rework' => '3'], $user->id);
    });

    it('works out good units, rates and target achievement from the reported output', function () {
        $m = app(ProductionMetrics::class);
        $s = $m->summary('2026-11-01', '2026-11-30');
        expect(rptEq($s['produced'], '40'))->toBeTrue()->and(rptEq($s['good'], '32'))->toBeTrue()->and(rptEq($s['rejected'], '5'))->toBeTrue()
            ->and(rptEq($s['reject_rate'], '12.5'))->toBeTrue()->and(rptEq($s['first_pass_yield'], '80'))->toBeTrue();
        $t = $m->targets('2026-11-04', '2026-11-04');
        expect(rptEq($t['achievement'], '80'))->toBeTrue()->and($t['shifts'])->toBe(1);
        $days = $m->dailyGood('2026-11-01', '2026-11-07');
        expect($days)->toHaveCount(7)->and(rptEq($days[3]['good'], '32'))->toBeTrue();
    });

    it('has no rates when nothing was produced rather than dividing by zero', function () {
        $s = app(ProductionMetrics::class)->summary('2026-10-01', '2026-10-31');
        expect($s['reject_rate'])->toBeNull()->and($s['first_pass_yield'])->toBeNull()->and(rptEq($s['good'], '0'))->toBeTrue();
        $none = app(ProductionMetrics::class)->targets('2026-10-01', '2026-10-31');                     // a due shift with a target and no output counts as 0 %\n        expect(rptEq($none['achievement'], '0'))->toBeTrue()->and($none['shifts'])->toBe(31);
        expect(app(ProductionMetrics::class)->downtime('2026-10-01', '2026-10-31')['events'])->toBe(0);
    });
});
