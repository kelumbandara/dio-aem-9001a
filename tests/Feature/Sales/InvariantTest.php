<?php

use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Inventory\Services\StockReconciler;
use App\Modules\MasterData\Actions\Reference\AddTaxRate;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\Sales\Models\CreditNote;
use App\Modules\Sales\Models\CreditNoteLine;
use App\Modules\Sales\Models\CreditNoteTax;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoiceLine;
use App\Modules\Sales\Models\OrderLine;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Support\Decimal;

/**
 * Random orders, split into several deliveries, invoices and credit notes: whatever the amounts and rounding, the
 * pieces always add up to the whole, and a document that is credited in full is cancelled to the cent.
 */
beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    TaxCode::where('code', 'SSCL')->update(['is_active' => true]);
    TaxCode::where('code', 'NBT')->update(['is_active' => true]);
    app(AddTaxRate::class)(TaxCode::where('code', 'SSCL')->first(), '2.5', '2024-01-01');
    app(AddTaxRate::class)(TaxCode::where('code', 'NBT')->first(), '1.3333', '2024-01-01');
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
});

it('keeps invoices, credit notes and ledgers consistent for random orders', function (int $seed) {
    mt_srand($seed);
    $rand = fn (int $a, int $b) => mt_rand($a, $b);

    $items = [];
    foreach (['VAT', 'VAT', 'EXEMPT'] as $i => $tax) {
        $item = mdItem(['name' => "Item {$seed}-{$i}", 'default_tax_code' => $tax]);
        invReceive($item, $this->wh, '1000', (string) $rand(5, 90));
        $items[] = $item;
    }

    $lines = [];
    foreach (range(1, $rand(1, 4)) as $n) {
        $lines[] = ['item_id' => $items[$rand(0, 2)]->id, 'qty' => (string) $rand(1, 40), 'unit_price' => $rand(1, 99999).'.'.str_pad((string) $rand(0, 9999), 4, '0'), 'discount_pct' => $rand(0, 3) === 0 ? (string) $rand(1, 30).'.'.$rand(0, 99) : '0'];
    }

    $orders = app(OrderService::class);
    $order = $orders->confirm($orders->save(['customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => $lines]));

    // Deliver and invoice in random slices until everything is done.
    $deliveries = app(DeliveryService::class);
    $invoices = app(InvoiceService::class);
    $guard = 0;
    while ($order->refresh()->status === 'confirmed' && $guard++ < 12) {
        $deliveries_pick = [];
        foreach (OrderLine::where('document_id', $order->id)->get() as $l) {
            $open = (int) Decimal::sub($l->qty, $l->qty_delivered);
            if ($open > 0) {
                $deliveries_pick[] = ['line' => $l->line_no, 'qty' => (string) ($open > 1 && $rand(0, 1) ? $rand(1, $open) : $open)];
            }
        }
        if ($deliveries_pick !== []) {
            $deliveries->post($order, ['lines' => $deliveries_pick]);
        }
        $invoices->create($order->refresh(), []);
    }
    expect($order->refresh()->status)->toBe('completed', "seed {$seed}");

    // Slices add up to the order, line by line.
    foreach (OrderLine::where('document_id', $order->id)->get() as $l) {
        expect(Decimal::compare(Decimal::sub((string) InvoiceLine::where('order_line_id', $l->id)->sum('net_amount'), $l->net_amount), '0', 2))->toBe(0, "seed {$seed}: order line {$l->line_no}");
    }
    foreach (Invoice::where('order_id', $order->id)->get() as $inv) {
        expect(Decimal::compare(Decimal::add(Decimal::add($inv->net_total, $inv->vat_total), $inv->levy_total), $inv->gross_total, 2))->toBe(0, "seed {$seed}: {$inv->document_no}");
        expect(Decimal::compare((string) $inv->lines()->sum('net_amount'), $inv->net_total, 2))->toBe(0)->and(Decimal::compare((string) $inv->lines()->sum('vat_amount'), $inv->vat_total, 2))->toBe(0);
        expect(Decimal::compare((string) $inv->taxes()->sum('amount'), Decimal::add($inv->vat_total, $inv->levy_total), 2))->toBe(0);
    }

    // Credit every invoice in random pieces; the sum never passes the invoice and ends at exactly the invoice.
    $notes = app(SalesCreditNoteService::class);
    foreach (Invoice::where('order_id', $order->id)->get() as $inv) {
        $guard = 0;
        while ($guard++ < 10) {
            $pick = [];
            foreach ($inv->lines()->get() as $l) {
                $left = (int) Decimal::sub($l->qty, (string) CreditNoteLine::where('invoice_line_id', $l->id)->sum('qty'));
                if ($left > 0) {
                    $pick[] = ['line' => $l->line_no, 'qty' => (string) ($left > 1 && $rand(0, 1) ? $rand(1, $left) : $left)];
                }
            }
            if ($pick === []) {
                break;
            }
            $notes->create($inv, ['reason' => 'random', 'lines' => $pick]);

            $creditedNet = (string) CreditNote::where('invoice_id', $inv->id)->sum('net_total');
            expect(Decimal::compare($creditedNet, $inv->net_total, 2))->toBeLessThanOrEqual(0, "seed {$seed}: credited more than invoiced");
        }
        $credits = CreditNote::where('invoice_id', $inv->id);
        expect(Decimal::compare((string) $credits->sum('net_total'), $inv->net_total, 2))->toBe(0, "seed {$seed}: net")
            ->and(Decimal::compare((string) $credits->sum('vat_total'), $inv->vat_total, 2))->toBe(0, "seed {$seed}: vat")
            ->and(Decimal::compare((string) $credits->sum('levy_total'), $inv->levy_total, 2))->toBe(0, "seed {$seed}: levy")
            ->and(Decimal::compare((string) $credits->sum('gross_total'), $inv->gross_total, 2))->toBe(0, "seed {$seed}: gross");
        foreach ($inv->taxes()->get() as $t) {
            expect(Decimal::compare((string) CreditNoteTax::where('tax_code_id', $t->tax_code_id)->whereIn('credit_note_id', $credits->select('id'))->sum('amount'), $t->amount, 2))->toBe(0, "seed {$seed}: tax {$t->kind}");
        }
    }

    expect((string) OpenItem::where('side', 'receivable')->sum('open_amount'))->toBe('0.0000')
        ->and(app(FinanceReconciler::class)->discrepancies())->toBe([], "seed {$seed}")
        ->and(app(StockReconciler::class)->discrepancies())->toBe([], "seed {$seed}");
})->with(range(1, 16));
