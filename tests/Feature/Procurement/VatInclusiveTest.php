<?php

use App\Modules\Approvals\Database\Seeders\PurchaseOrderPolicySeeder;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Core\Services\OutboxRelay;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\PurchaseReturnService;
use App\Modules\Procurement\Services\SupplierBillService;
use App\Support\Exceptions\BusinessException;
use Spatie\Permission\Models\Role;

/** The owner's rule: supplier prices include VAT; the order's net value is the price less the VAT inside it. */
beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->item = mdItem(['name' => 'Widget', 'default_tax_code' => 'VAT']);
    $this->supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
    $this->buyer = userWithPermissions('procurement.order.manage');
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    $this->orders = app(PurchaseOrderService::class);
    $this->draft = fn (string $qty = '10', string $price = '118') => $this->orders->save(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => $qty, 'unit_price' => $price]]]);
    $this->order = fn (string $qty = '10', string $price = '118') => $this->orders->submit(($this->draft)($qty, $price), $this->buyer->id);
});

it('is the default: a typed price of 118 is 100 net plus 18 input VAT', function () {
    expect(config('procurement.prices_include_vat'))->toBeTrue();
    $order = ($this->order)();

    expect($order->prices_include_vat)->toBeTrue()
        ->and($order->only(['net_total', 'vat_total', 'gross_total']))->toBe(['net_total' => '1000.0000', 'vat_total' => '180.0000', 'gross_total' => '1180.0000'])
        ->and($order->lines->first()->vat_amount)->toBe('180.0000');
});

it('receives at the net cost and bills the quoted price: payable 1,180, GRNI cleared, input VAT 180', function () {
    $order = ($this->order)();
    app(GoodsReceiptService::class)->post($order, []);
    $bill = app(SupplierBillService::class)->create($order->refresh(), ['supplier_invoice_no' => 'S1']);
    (fn () => app(OutboxRelay::class)->run(2000))();

    expect($bill->prices_include_vat)->toBeTrue()->and($bill->only(['net_total', 'vat_total', 'gross_total', 'variance_total']))->toBe(['net_total' => '1000.0000', 'vat_total' => '180.0000', 'gross_total' => '1180.0000', 'variance_total' => '0.0000'])
        ->and(invOnHand($this->item, $this->wh))->toBe('10.000000')->and(($this->bal)('1100'))->toBe('1000.0000')->and(($this->bal)('1510'))->toBe('0.0000')
        ->and(($this->bal)('1126'))->toBe('180.0000')->and(OpenItem::sole()->amount)->toBe('1180.0000');
    expect(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});

it('compares the supplier price on the same basis and books a cheaper bill to variance', function () {
    $order = ($this->order)();
    app(GoodsReceiptService::class)->post($order, []);
    $bills = app(SupplierBillService::class);

    // 5 % dearer than the quoted 118 is refused; 1 % cheaper passes: total 1,168.20 = net 990.00 + VAT 178.20
    try {
        $bills->create($order->refresh(), ['supplier_invoice_no' => 'DEAR', 'lines' => [['line' => 1, 'qty' => '10', 'unit_price' => '123.9']]]);
        test()->fail('Expected the price to be refused');
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe('procurement.price_variance_exceeded');
    }
    $bill = $bills->create($order->refresh(), ['supplier_invoice_no' => 'CHEAP', 'lines' => [['line' => 1, 'qty' => '10', 'unit_price' => '116.82']]]);
    expect($bill->gross_total)->toBe('1168.2000')->and($bill->net_total)->toBe('990.0000')->and($bill->vat_total)->toBe('178.2000')->and($bill->variance_total)->toBe('-10.0000');
});

it('bills in slices that add up to the order and credits a return at the bills own amounts', function () {
    $order = ($this->order)('7', '99.99');
    $receipts = app(GoodsReceiptService::class);
    $bills = app(SupplierBillService::class);

    $receipts->post($order, ['lines' => [['line' => 1, 'qty' => '3']]]);
    $first = $bills->create($order->refresh(), ['supplier_invoice_no' => 'S1']);
    $receipts->post($order->refresh(), []);
    $second = $bills->create($order->refresh(), ['supplier_invoice_no' => 'S2']);

    // 7 x 99.99 = 699.93 including VAT, in two pieces
    expect(bcadd($first->gross_total, $second->gross_total, 2))->toBe('699.93')->and(bcadd($first->net_total, $second->net_total, 2))->toBe(bcadd($order->refresh()->net_total, '0', 2))
        ->and($order->status)->toBe('completed');

    $return = app(PurchaseReturnService::class)->create($order, ['reason' => 'All back', 'bill' => 'S1', 'lines' => [['line' => 1, 'qty' => '3']]]);
    expect($return->gross_total)->toBe($first->gross_total);
    expect(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});

it('creates the starting approval rules once: every order needs a purchase-approver, big orders a finance-approver too', function () {
    $this->seed(PurchaseOrderPolicySeeder::class);
    $this->seed(PurchaseOrderPolicySeeder::class);   // safe to repeat
    expect(ApprovalPolicy::where('action_key', 'procurement.po.approve')->count())->toBe(2)
        ->and(Role::where('name', 'purchase-approver')->exists())->toBeTrue();

    $approver = userWithRole('purchase-approver');
    $order = $this->orders->submit(($this->draft)('10', '118'), $this->buyer->id);
    expect($order->status)->toBe('pending_approval');
    asApi($approver)->postJson("/api/v1/approvals/{$order->approval_request_id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'executed');
    expect($order->refresh()->status)->toBe('approved');

    // from LKR 100,000 a second approval, by finance, is needed
    $finance = userWithRole('finance-approver');
    $big = $this->orders->submit(($this->draft)('10', '60000'), $this->buyer->id);
    asApi($approver)->postJson("/api/v1/approvals/{$big->approval_request_id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'pending');
    expect($big->refresh()->status)->toBe('pending_approval');
    asApi($finance)->postJson("/api/v1/approvals/{$big->approval_request_id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'executed');
    expect($big->refresh()->status)->toBe('approved');

    // the owner's threshold is LKR 100,000: just above needs finance too, well below needs only purchasing
    $mid = $this->orders->submit(($this->draft)('10', '12000'), $this->buyer->id);
    asApi($approver)->postJson("/api/v1/approvals/{$mid->approval_request_id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'pending');
    $small = $this->orders->submit(($this->draft)('10', '5000'), $this->buyer->id);
    asApi($approver)->postJson("/api/v1/approvals/{$small->approval_request_id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'executed');
});
