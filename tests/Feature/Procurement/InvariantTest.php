<?php

use App\Modules\Core\Services\OutboxRelay;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\Inventory\Services\StockReconciler;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\PurchaseReturn;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\PurchaseReturnService;
use App\Modules\Procurement\Services\SupplierBillService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Random purchase orders received, billed and returned in random slices: whatever the amounts and rounding, the books
 * and the stock agree, goods-received-not-invoiced ends at (almost) nothing, and debit notes never pass their bill.
 */
beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
    $this->buyer = userWithPermissions('procurement.order.manage');
});

it('keeps bills, debit notes, stock and ledgers consistent for random orders', function (int $seed) {
    mt_srand($seed);
    $rand = fn (int $a, int $b) => mt_rand($a, $b);

    $lines = [];
    foreach (range(1, $rand(1, 3)) as $n) {
        $item = mdItem(['name' => "Item {$seed}-{$n}", 'default_tax_code' => $rand(0, 3) === 0 ? 'EXEMPT' : 'VAT']);
        $lines[] = ['item_id' => $item->id, 'qty' => (string) $rand(2, 40), 'unit_price' => $rand(1, 99999).'.'.str_pad((string) $rand(0, 9999), 4, '0'), 'discount_pct' => $rand(0, 3) === 0 ? (string) $rand(1, 30) : '0'];
    }
    $orders = app(PurchaseOrderService::class);
    $order = $orders->submit($orders->save(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => $lines]), $this->buyer->id);
    $exactPrices = $seed % 2 === 0;   // odd seeds bill at slightly different prices

    $receipts = app(GoodsReceiptService::class);
    $bills = app(SupplierBillService::class);
    $returns = app(PurchaseReturnService::class);
    $invoiceNo = 0;

    $open = fn (PurchaseOrderLine $l) => (int) Decimal::add(Decimal::sub($l->qty, $l->qty_received), $l->qty_returned);
    $onHand = fn (PurchaseOrderLine $l) => (int) Decimal::sub($l->qty_received, $l->qty_returned);
    $billable = fn (PurchaseOrderLine $l) => max(0, (int) Decimal::sub(Decimal::sub($l->qty_received, $l->qty_returned), $l->qty_billed));
    $fresh = fn () => PurchaseOrderLine::where('document_id', $order->id)->get();

    foreach (range(1, 8) as $step) {
        $order->refresh();
        $op = $rand(0, 2);
        $line = $fresh()->random();
        if ($op === 0 && $open($line) > 0) {
            $receipts->post($order, ['lines' => [['line' => $line->line_no, 'qty' => (string) $rand(1, $open($line))]]]);
        } elseif ($op === 1 && $billable($line) > 0) {
            $price = $exactPrices ? null : Decimal::round(Decimal::mul(Decimal::div($line->net_amount, $line->qty), (string) (1 + $rand(-15, 15) / 1000)), 4);
            $bills->create($order, ['supplier_invoice_no' => 'S'.$seed.'-'.(++$invoiceNo), 'lines' => [['line' => $line->line_no, 'qty' => (string) $rand(1, $billable($line))] + ($price !== null ? ['unit_price' => $price] : [])]]);
        } elseif ($op === 2 && $onHand($line) > 0) {
            $qty = $rand(1, $onHand($line));
            $bill = null;
            if ($rand(0, 1) === 1) {
                foreach (SupplierBill::where('order_id', $order->id)->get() as $b) {
                    $bl = $b->lines()->where('order_line_id', $line->id)->first();
                    $left = $bl ? (int) Decimal::sub($bl->qty, (string) DB::table('purchase_return_lines')->where('bill_line_id', $bl->id)->sum('qty')) : 0;
                    if ($left >= $qty) {
                        $bill = $b->supplier_invoice_no;
                        break;
                    }
                }
            }
            $returns->create($order, ['reason' => 'random', 'lines' => [['line' => $line->line_no, 'qty' => (string) $qty]]] + ($bill ? ['bill' => $bill] : []));
        }
    }

    // finish: receive what is open, bill what is billable
    $order->refresh();
    foreach ($fresh() as $l) {
        if ($open($l) > 0 && $order->status === 'approved') {
            $receipts->post($order, ['lines' => [['line' => $l->line_no, 'qty' => (string) $open($l)]]]);
        }
    }
    $order->refresh();
    foreach ($fresh() as $l) {
        if ($billable($l) > 0 && $order->status === 'approved') {
            $bills->create($order, ['supplier_invoice_no' => 'S'.$seed.'-'.(++$invoiceNo), 'lines' => [['line' => $l->line_no, 'qty' => (string) $billable($l)]]]);
        }
    }
    (fn () => app(OutboxRelay::class)->run(5000))();

    $bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    $order->refresh();
    expect($order->status)->toBe('completed', "seed {$seed}: ".json_encode($fresh()->map(fn ($l) => $l->only(['line_no', 'qty', 'qty_received', 'qty_returned', 'qty_billed']))->all()));

    // nothing billed beyond what was ordered or received; debit notes never pass their bill
    foreach ($fresh() as $l) {
        expect(Decimal::compare($l->qty_billed, $l->qty, 6))->toBe(0, "seed {$seed}: line {$l->line_no} fully billed");
    }
    foreach (SupplierBill::where('order_id', $order->id)->get() as $b) {
        $credited = (string) PurchaseReturn::where('bill_id', $b->id)->sum('net_total');
        expect(Decimal::compare($credited, $b->net_total, 2))->toBeLessThanOrEqual(0, "seed {$seed}: debit notes beyond bill {$b->document_no}");
    }

    // exact prices: bills less debit notes equal the order, to the cent
    $netBilled = Decimal::sub((string) SupplierBill::where('order_id', $order->id)->sum('net_total'), (string) PurchaseReturn::where('order_id', $order->id)->sum('net_total'));
    if ($exactPrices) {
        expect(Decimal::compare($netBilled, $order->net_total, 2))->toBe(0, "seed {$seed}: billed {$netBilled} vs ordered {$order->net_total}");
    }

    // the payable equals bills less debit notes; the books and the stock agree; goods-received-not-invoiced is empty but for 1/100 of a rupee of rounding
    $grossBilled = Decimal::sub((string) SupplierBill::where('order_id', $order->id)->sum('gross_total'), (string) PurchaseReturn::where('order_id', $order->id)->sum('gross_total'));
    expect(Decimal::compare((string) OpenItem::where('side', 'payable')->sum('open_amount'), $grossBilled, 2))->toBe(0, "seed {$seed}: payable")
        ->and(abs((float) $bal('1510')))->toBeLessThan(PurchaseReturn::count() === 0 ? 0.00001 : 0.0005, "seed {$seed}: GRNI {$bal('1510')}")   // exactly nothing unless goods went back at average cost
        ->and($bal('1100'))->toBe(Decimal::round((string) DB::table('item_costs')->sum('value'), 4), "seed {$seed}: inventory account")
        ->and(app(FinanceReconciler::class)->discrepancies())->toBe([], "seed {$seed}")
        ->and(app(StockReconciler::class)->discrepancies())->toBe([], "seed {$seed}");
    expect(Settlement::where('method', 'debit_note')->count())->toBe(PurchaseReturn::whereNotNull('bill_id')->count());
    expect(PurchaseOrder::count())->toBe(1);
})->with(range(1, 16));
