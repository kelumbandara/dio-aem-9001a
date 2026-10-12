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
use App\Modules\Procurement\Approvals\PurchaseOrderApprove;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\PurchaseReturnService;
use App\Modules\Procurement\Services\SupplierBillService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function buyFails(Closure $fn, string $code): void
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
    $this->seed(FinanceSeeder::class);
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->item = mdItem(['name' => 'Widget', 'default_tax_code' => 'VAT']);
    $this->supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    $this->relay = fn () => app(OutboxRelay::class)->run(2000);
    $this->buyer = userWithPermissions('procurement.order.manage');
    $this->orders = app(PurchaseOrderService::class);
    $this->receipts = app(GoodsReceiptService::class);
    $this->bills = app(SupplierBillService::class);
    $this->returns = app(PurchaseReturnService::class);
    $this->checks = function () {
        expect(app(FinanceReconciler::class)->discrepancies())->toBe([])->and(app(StockReconciler::class)->discrepancies())->toBe([]);
    };
    $this->approved = function (string $qty = '10', string $price = '100', ?object $item = null) {
        $o = $this->orders->save(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => ($item ?? $this->item)->id, 'qty' => $qty, 'unit_price' => $price]]]);

        return $this->orders->submit($o, $this->buyer->id);
    };
});

describe('order to bill', function () {
    it('orders, receives into stock against goods-received-not-invoiced, and bills with input VAT', function () {
        $order = ($this->approved)();
        expect($order->status)->toBe('approved')->and($order->document_no)->toMatch('#^PO/\d{4}-\d{2}/\d{5}$#')
            ->and($order->only(['net_total', 'vat_total', 'gross_total']))->toBe(['net_total' => '1000.0000', 'vat_total' => '180.0000', 'gross_total' => '1180.0000']);

        $receipt = $this->receipts->post($order, ['supplier_dn_ref' => 'DN-77']);
        ($this->relay)();
        expect($receipt->document_no)->toStartWith('GRN/')->and(invOnHand($this->item, $this->wh))->toBe('10.000000')
            ->and(($this->bal)('1100'))->toBe('1000.0000')->and(($this->bal)('1510'))->toBe('-1000.0000');

        $bill = $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'SUP-001']);
        expect($bill->document_no)->toStartWith('BILL/')->and($bill->only(['net_total', 'vat_total', 'gross_total', 'variance_total']))->toBe(['net_total' => '1000.0000', 'vat_total' => '180.0000', 'gross_total' => '1180.0000', 'variance_total' => '0.0000'])
            ->and($order->refresh()->status)->toBe('completed');
        expect(OpenItem::sole()->only(['document_no', 'amount']))->toBe(['document_no' => 'SUP-001', 'amount' => '1180.0000'])
            ->and(($this->bal)('1510'))->toBe('0.0000')->and(($this->bal)('1126'))->toBe('180.0000')->and(($this->bal)('1500'))->toBe('-1180.0000');
        ($this->checks)();
    });

    it('refuses to bill more than was received and more than was ordered to receive', function () {
        $order = ($this->approved)();
        buyFails(fn () => $this->bills->create($order, ['supplier_invoice_no' => 'S1']), 'procurement.nothing_to_bill');
        $this->receipts->post($order, ['lines' => [['line' => 1, 'qty' => '6']]]);
        buyFails(fn () => $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'S1', 'lines' => [['line' => 1, 'qty' => '7']]]), 'procurement.qty_exceeds_billable');
        buyFails(fn () => $this->receipts->post($order->refresh(), ['lines' => [['line' => 1, 'qty' => '5']]]), 'procurement.qty_exceeds_open');

        $first = $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'S1']);
        expect($first->net_total)->toBe('600.0000');
        $this->receipts->post($order->refresh(), []);
        $second = $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'S2']);
        expect($second->net_total)->toBe('400.0000')->and($order->refresh()->status)->toBe('completed');
        ($this->relay)();
        expect(($this->bal)('1510'))->toBe('0.0000');
        ($this->checks)();
    });

    it('refuses a repeated supplier invoice number', function () {
        $order = ($this->approved)();
        $this->receipts->post($order, ['lines' => [['line' => 1, 'qty' => '3']]]);
        $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'SAME']);
        $this->receipts->post($order->refresh(), []);
        buyFails(fn () => $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'SAME']), 'procurement.bill_exists');
    });
});

describe('price match', function () {
    beforeEach(function () {
        $this->order = ($this->approved)();
        $this->receipts->post($this->order, []);
        ($this->relay)();
    });

    it('books a small difference to purchase price variance without asking anyone', function () {
        $bill = $this->bills->create($this->order->refresh(), ['supplier_invoice_no' => 'CHEAP', 'lines' => [['line' => 1, 'qty' => '10', 'unit_price' => '99']]]);

        expect($bill->only(['net_total', 'vat_total', 'gross_total', 'variance_total']))->toBe(['net_total' => '990.0000', 'vat_total' => '178.2000', 'gross_total' => '1168.2000', 'variance_total' => '-10.0000'])
            ->and(($this->bal)('2217'))->toBe('-10.0000')->and(($this->bal)('1510'))->toBe('0.0000')->and(($this->bal)('1500'))->toBe('-1168.2000');
        ($this->checks)();
    });

    it('blocks a price more than the tolerance away unless someone allowed accepts it with a reason', function () {
        $dear = ['supplier_invoice_no' => 'DEAR', 'lines' => [['line' => 1, 'qty' => '10', 'unit_price' => '105']]];
        buyFails(fn () => $this->bills->create($this->order, $dear), 'procurement.price_variance_exceeded');
        buyFails(fn () => $this->bills->create($this->order, $dear, null, true), 'procurement.price_variance_exceeded');   // allowed, but no reason
        buyFails(fn () => $this->bills->create($this->order, $dear + ['override_reason' => 'Price rise agreed by phone']), 'procurement.price_variance_exceeded');   // reason, but not allowed

        $bill = $this->bills->create($this->order->refresh(), $dear + ['override_reason' => 'Price rise agreed by phone'], null, true);
        expect($bill->override_reason)->toBe('Price rise agreed by phone')->and($bill->variance_total)->toBe('50.0000')
            ->and(($this->bal)('2217'))->toBe('50.0000')->and(OpenItem::sole()->amount)->toBe('1239.0000');
        ($this->checks)();
    });
});

describe('services and non-stock items', function () {
    it('bills a service by the ordered quantity to the purchases account, with no receipt', function () {
        $service = mdItem(['name' => 'Repairs', 'item_type' => 'service', 'default_tax_code' => 'VAT']);
        $order = ($this->approved)('2', '500', $service);
        buyFails(fn () => $this->receipts->post($order, ['lines' => [['line' => 1, 'qty' => '1']]]), 'procurement.line_not_receivable');

        $bill = $this->bills->create($order, ['supplier_invoice_no' => 'SRV-1']);
        expect($bill->gross_total)->toBe('1180.0000')->and($order->refresh()->status)->toBe('completed')
            ->and(($this->bal)('2412'))->toBe('1000.0000')->and(($this->bal)('1126'))->toBe('180.0000')->and(($this->bal)('1500'))->toBe('-1180.0000');
        ($this->checks)();
    });

    it('claims no input VAT on an exempt item', function () {
        $exempt = mdItem(['name' => 'Books', 'default_tax_code' => 'EXEMPT']);
        $order = ($this->approved)('4', '250', $exempt);
        expect($order->vat_total)->toBe('0.0000');
        $this->receipts->post($order, []);
        $bill = $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'EX-1']);
        expect($bill->gross_total)->toBe('1000.0000')->and(($this->bal)('1126'))->toBe('0.0000');
    });

    it('buys with input VAT when the item has no tax code', function () {
        $plain = mdItem(['name' => 'Plain']);
        expect(($this->approved)('1', '100', $plain)->vat_total)->toBe('18.0000');
    });
});

describe('returns and debit notes', function () {
    it('sends unbilled goods back: stock leaves, goods-received falls, and only the rest can be billed', function () {
        $order = ($this->approved)();
        $this->receipts->post($order, []);
        $return = $this->returns->create($order->refresh(), ['reason' => 'Wrong size', 'lines' => [['line' => 1, 'qty' => '3']]]);
        ($this->relay)();

        expect($return->document_no)->toStartWith('PRN/')->and(invOnHand($this->item, $this->wh))->toBe('7.000000')->and(($this->bal)('1510'))->toBe('-700.0000')->and($return->journal_id)->toBeNull();
        $bill = $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'S7']);
        expect($bill->net_total)->toBe('700.0000');
        ($this->relay)();
        expect(($this->bal)('1510'))->toBe('0.0000');
        ($this->checks)();
    });

    it('returns billed goods with a debit note that reduces the bill and makes the quantity billable again', function () {
        $order = ($this->approved)();
        $this->receipts->post($order, []);
        $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'S10']);

        $return = $this->returns->create($order->refresh(), ['reason' => 'Damaged', 'bill' => 'S10', 'lines' => [['line' => 1, 'qty' => '4']]]);
        ($this->relay)();

        expect($return->only(['net_total', 'vat_total', 'gross_total']))->toBe(['net_total' => '400.0000', 'vat_total' => '72.0000', 'gross_total' => '472.0000'])
            ->and(OpenItem::sole()->open_amount)->toBe('708.0000')->and(Settlement::sole()->method)->toBe('debit_note')
            ->and(invOnHand($this->item, $this->wh))->toBe('6.000000')->and(($this->bal)('1100'))->toBe('600.0000')
            ->and(($this->bal)('1510'))->toBe('0.0000')->and(($this->bal)('1126'))->toBe('108.0000')->and(($this->bal)('1500'))->toBe('-708.0000');

        // the supplier replaces the four: they can be received and billed again
        $this->receipts->post($order->refresh(), []);
        $again = $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'S11']);
        expect($again->net_total)->toBe('400.0000')->and($order->refresh()->status)->toBe('completed');
        ($this->relay)();
        expect(($this->bal)('1510'))->toBe('0.0000');
        ($this->checks)();
    });

    it('never credits more than the bill or returns more than was received', function () {
        $order = ($this->approved)();
        $this->receipts->post($order, []);
        $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'S10']);
        buyFails(fn () => $this->returns->create($order->refresh(), ['reason' => 'x', 'lines' => [['line' => 1, 'qty' => '11']]]), 'procurement.qty_exceeds_received');
        $this->returns->create($order->refresh(), ['reason' => 'a', 'bill' => 'S10', 'lines' => [['line' => 1, 'qty' => '10']]]);
        expect(OpenItem::sole()->open_amount)->toBe('0.0000');
        buyFails(fn () => $this->returns->create($order->refresh(), ['reason' => 'b', 'bill' => 'S10', 'lines' => [['line' => 1, 'qty' => '1']]]), 'procurement.qty_exceeds_received');
        buyFails(fn () => $this->returns->create($order->refresh(), ['reason' => ' ', 'lines' => [['line' => 1, 'qty' => '1']]]), 'procurement.reason_required');
    });
});

describe('reversal and immutability', function () {
    it('reverses a receipt that has not been billed, puts nothing back into goods-received, and refuses once billed', function () {
        $order = ($this->approved)();
        $receipt = $this->receipts->post($order, []);
        $this->receipts->reverse($receipt, 'Wrong delivery');
        ($this->relay)();
        expect(invOnHand($this->item, $this->wh))->toBe('0.000000')->and(($this->bal)('1510'))->toBe('0.0000')->and($order->lines()->first()->qty_received)->toBe('0.000000');

        $second = $this->receipts->post($order->refresh(), []);
        $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'S1']);
        buyFails(fn () => $this->receipts->reverse($second), 'procurement.receipt_used');
        ($this->checks)();
    });

    it('lets the database refuse changes to a posted bill', function () {
        $order = ($this->approved)();
        $this->receipts->post($order, []);
        $bill = $this->bills->create($order->refresh(), ['supplier_invoice_no' => 'S1']);

        foreach (["UPDATE supplier_bills SET net_total = 1 WHERE id = {$bill->id}", "DELETE FROM supplier_bills WHERE id = {$bill->id}", "UPDATE supplier_bill_lines SET qty = 1 WHERE bill_id = {$bill->id}"] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                test()->fail("Expected the database to refuse: {$sql}");
            } catch (QueryException $e) {
                expect($e->getMessage())->toContain('immutable');
            }
        }
        expect(SupplierBill::count())->toBe(1);
    });
});

describe('order approval', function () {
    it('waits for the approvers when a policy exists, and is approved by them', function () {
        $approver = userWithRole('finance-approver');
        paymentPolicy(['name' => 'Purchase orders', 'action_key' => PurchaseOrderApprove::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);
        $requester = userWithPermissions('procurement.order.manage');

        $order = $this->orders->save(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '10', 'unit_price' => '100']]]);
        $order = $this->orders->submit($order, $requester->id);
        expect($order->status)->toBe('pending_approval')->and($order->approval_request_id)->not->toBeNull();
        buyFails(fn () => $this->receipts->post($order, []), 'procurement.invalid_state');
        buyFails(fn () => $this->orders->save(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '1', 'unit_price' => '1']]], $order), 'procurement.invalid_state');

        asApi($approver)->postJson("/api/v1/approvals/{$order->approval_request_id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'executed');
        expect($order->refresh()->status)->toBe('approved')->and($order->approved_by)->toBeNull()->and($order->approved_at)->not->toBeNull();
    });

    it('can be withdrawn to a draft, edited and cancelled', function () {
        paymentPolicy(['name' => 'Purchase orders', 'action_key' => PurchaseOrderApprove::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);
        userWithRole('finance-approver');
        $requester = userWithPermissions('procurement.order.manage');
        $order = $this->orders->submit($this->orders->save(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '2', 'unit_price' => '10']]]), $requester->id);

        $order = $this->orders->withdraw($order);
        expect($order->status)->toBe('draft');
        $this->orders->cancel($order, 'Not needed');
        expect($order->refresh()->status)->toBe('cancelled');
    });

    it('cannot be cancelled once goods arrived', function () {
        $order = ($this->approved)();
        $this->receipts->post($order, ['lines' => [['line' => 1, 'qty' => '1']]]);
        buyFails(fn () => $this->orders->cancel($order->refresh(), 'oops'), 'procurement.order_in_progress');
        expect(PurchaseOrder::count())->toBe(1);
    });
});
