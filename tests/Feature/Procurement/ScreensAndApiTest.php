<?php

use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseReturn;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\PurchaseReturnService;
use App\Modules\Procurement\Services\SupplierBillService;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Role;

const BUYER_PERMISSIONS = ['procurement.view', 'procurement.order.manage', 'procurement.receipt.post', 'procurement.return.post', 'procurement.reverse', 'procurement.bill.post'];

beforeEach(function () {
    config(['procurement.prices_include_vat' => false]);   // these tests work in net prices; VAT-inclusive pricing has its own tests
    $this->seed(FinanceSeeder::class);
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->item = mdItem(['name' => 'Widget', 'default_tax_code' => 'VAT']);
    $this->supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
    $this->user = userWithPermissions(...BUYER_PERMISSIONS);
    $this->lines = [['item' => $this->item->code, 'qty' => '10', 'unit_price' => '100']];
    $this->payload = fn (array $o = []) => array_merge(['supplier' => $this->supplier->code, 'warehouse' => 'MAIN', 'lines' => $this->lines], $o);
});

describe('API', function () {
    it('runs order to debit note over HTTP with codes and line numbers', function () {
        $id = asApi($this->user)->postJson('/api/v1/purchase-orders', ($this->payload)(), idemKey())->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.totals.gross', '1180.0000')->assertJsonPath('data.lines.0.item', $this->item->code)->json('data.id');
        asApi($this->user)->postJson("/api/v1/purchase-orders/{$id}/submit", [], idemKey())->assertOk()->assertJsonPath('data.status', 'approved');
        $receipt = asApi($this->user)->postJson("/api/v1/purchase-orders/{$id}/receipts", ['supplier_dn_ref' => 'DN-1', 'lines' => [['line' => 1, 'qty' => '10']]], idemKey())->assertCreated()
            ->assertJsonPath('data.lines.0.value', '1000.0000')->json('data.id');
        $bill = asApi($this->user)->postJson("/api/v1/purchase-orders/{$id}/bills", ['supplier_invoice_no' => 'SUP-9'], idemKey())->assertCreated()
            ->assertJsonPath('data.totals.gross', '1180.0000')->assertJsonPath('data.supplier_invoice_no', 'SUP-9')->json('data.id');
        asApi($this->user)->postJson("/api/v1/purchase-orders/{$id}/returns", ['reason' => 'Damaged', 'bill' => 'SUP-9', 'lines' => [['line' => 1, 'qty' => '2']]], idemKey())->assertCreated()
            ->assertJsonPath('data.totals.gross', '236.0000')->assertJsonPath('data.bill', 'SUP-9');

        asApi($this->user)->getJson('/api/v1/purchase-orders?filter[q]=PO')->assertOk()->assertJsonCount(1, 'data');
        asApi($this->user)->getJson("/api/v1/goods-receipts/{$receipt}")->assertOk()->assertJsonPath('data.status', 'posted');
        asApi($this->user)->getJson("/api/v1/supplier-bills/{$bill}")->assertOk()->assertJsonPath('data.returns.0.gross_total', '236.0000');
        asApi($this->user)->getJson('/api/v1/supplier-bills?filter[q]=SUP')->assertOk()->assertJsonCount(1, 'data');
        asApi($this->user)->getJson('/api/v1/purchase-returns')->assertOk()->assertJsonCount(1, 'data');
        asApi($this->user)->postJson("/api/v1/goods-receipts/{$receipt}/reverse", [], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'procurement.receipt_used');
    });

    it('stops a bill whose price is outside the tolerance and lets only an overrider accept it', function () {
        $id = asApi($this->user)->postJson('/api/v1/purchase-orders', ($this->payload)(), idemKey())->json('data.id');
        asApi($this->user)->postJson("/api/v1/purchase-orders/{$id}/submit", [], idemKey());
        asApi($this->user)->postJson("/api/v1/purchase-orders/{$id}/receipts", [], idemKey())->assertCreated();
        $dear = ['supplier_invoice_no' => 'DEAR', 'lines' => [['line' => 1, 'qty' => '10', 'unit_price' => '110']]];
        asApi($this->user)->postJson("/api/v1/purchase-orders/{$id}/bills", $dear + ['override_reason' => 'agreed'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'procurement.price_variance_exceeded');

        $boss = userWithPermissions(...[...BUYER_PERMISSIONS, 'procurement.bill.override']);
        asApi($boss)->postJson("/api/v1/purchase-orders/{$id}/bills", $dear + ['override_reason' => 'agreed'], idemKey())->assertCreated()->assertJsonPath('data.override_reason', 'agreed')->assertJsonPath('data.variance_total', '100.0000');
    });

    it('answers 403 without the permission, 422 with details for bad input, 409 for a stale edit', function () {
        $reader = userWithPermissions('procurement.view');
        asApi($reader)->getJson('/api/v1/purchase-orders')->assertOk();
        asApi($reader)->postJson('/api/v1/purchase-orders', ($this->payload)(), idemKey())->assertForbidden();
        asApi(userWithPermissions('masterdata.item.view'))->getJson('/api/v1/purchase-orders')->assertForbidden();

        asApi($this->user)->postJson('/api/v1/purchase-orders', ($this->payload)(['lines' => [['item' => 'NOPE', 'qty' => '1']]]), idemKey())->assertStatus(422)->assertJsonPath('error.code', 'procurement.item_not_found');
        asApi($this->user)->postJson('/api/v1/purchase-orders', ($this->payload)(['supplier' => 'SUP-99999']), idemKey())->assertStatus(422)->assertJsonPath('error.code', 'procurement.supplier_required');
        asApi($this->user)->postJson('/api/v1/purchase-orders', ($this->payload)(['warehouse' => 'NOPE']), idemKey())->assertStatus(422)->assertJsonPath('error.code', 'procurement.warehouse_required');
        asApi($this->user)->postJson('/api/v1/purchase-orders', ($this->payload)(['lines' => [['item' => $this->item->code, 'qty' => 'abc']]]), idemKey())->assertStatus(422);

        $id = asApi($this->user)->postJson('/api/v1/purchase-orders', ($this->payload)(), idemKey())->json('data.id');
        asApi($this->user)->putJson("/api/v1/purchase-orders/{$id}", ($this->payload)(['version' => 1]), idemKey())->assertOk()->assertJsonPath('data.version', 2);
        asApi($this->user)->putJson("/api/v1/purchase-orders/{$id}", ($this->payload)(['version' => 1]), idemKey())->assertStatus(409)->assertJsonPath('error.code', 'concurrency.stale_version');
    });

    it('repeats a POST with the same idempotency key without a second document', function () {
        $key = idemKey();
        asApi($this->user)->postJson('/api/v1/purchase-orders', ($this->payload)(), $key)->assertCreated();
        asApi($this->user)->postJson('/api/v1/purchase-orders', ($this->payload)(), $key)->assertCreated();
        expect(PurchaseOrder::count())->toBe(1);
    });
});

describe('screens', function () {
    beforeEach(function () {
        $orders = app(PurchaseOrderService::class);
        $this->order = $orders->submit($orders->save(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '10', 'unit_price' => '100']]]), $this->user->id);
        $this->receipt = app(GoodsReceiptService::class)->post($this->order, []);
        $this->bill = app(SupplierBillService::class)->create($this->order->refresh(), ['supplier_invoice_no' => 'SUP-1'], null, false);
        $this->return = app(PurchaseReturnService::class)->create($this->order->refresh(), ['reason' => 'Damaged', 'bill' => 'SUP-1', 'lines' => [['line' => 1, 'qty' => '1']]]);
        $this->draft = $orders->save(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '1', 'unit_price' => '100']]]);
        $this->open = $orders->submit($orders->save(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '5', 'unit_price' => '100']]]), $this->user->id);
    });

    it('renders every screen in all three languages without raw translation keys', function (string $locale) {
        $this->user->forceFill(['locale' => $locale])->save();
        $urls = ['/procurement/orders', '/procurement/orders?status=approved&q=po', '/procurement/orders/create', "/procurement/orders/{$this->order->public_id}", "/procurement/orders/{$this->order->public_id}/print",
            "/procurement/orders/{$this->draft->public_id}", "/procurement/orders/{$this->draft->public_id}/edit", "/procurement/orders/{$this->open->public_id}",
            '/procurement/receipts', "/procurement/receipts/{$this->receipt->public_id}", '/procurement/bills', "/procurement/bills/{$this->bill->public_id}",
            '/procurement/returns', "/procurement/returns/{$this->return->public_id}"];

        foreach ($urls as $url) {
            $body = $this->actingAs($this->user->fresh())->get($url)->assertOk()->getContent();
            expect($body)->not->toContain('procurement::')->not->toContain('procurement.ui.', "raw key on {$url}");
        }
    })->with(['en', 'si', 'ta']);

    it('has identical translation keys in every language', function () {
        $keys = fn (string $locale) => collect(array_keys(Arr::dot(require app_path("Modules/Procurement/Lang/{$locale}/ui.php"))))->sort()->values()->all();

        foreach (['si', 'ta'] as $locale) {
            expect($keys($locale))->toBe($keys('en'), "{$locale} differs from en");
        }
    });

    it('prints a purchase order only once it is approved', function () {
        $this->actingAs($this->user)->get("/procurement/orders/{$this->order->public_id}/print")->assertOk()->assertSee($this->order->document_no)->assertSee('Purchase order')->assertSee('ACME Supplies')->assertSee('1,180.00');
        $this->actingAs($this->user)->get("/procurement/orders/{$this->draft->public_id}/print")->assertNotFound();
    });

    it('creates and acts through the forms and keeps what was typed when the rules say no', function () {
        $this->actingAs($this->user)->post('/procurement/orders', ['supplier' => $this->supplier->code, 'warehouse' => 'MAIN', 'lines' => [['item' => $this->item->code, 'qty' => '3', 'unit_price' => '100']]])->assertRedirect()->assertSessionHas('status');
        expect(PurchaseOrder::count())->toBe(4);

        $this->actingAs($this->user)->from('/procurement/orders/create')->post('/procurement/orders', ['supplier' => $this->supplier->code, 'warehouse' => 'MAIN', 'lines' => [['item' => 'NOPE', 'qty' => '3']]])
            ->assertRedirect('/procurement/orders/create')->assertSessionHasErrors('lines.0.item')->assertSessionHasInput('supplier', $this->supplier->code);

        $this->actingAs($this->user)->post("/procurement/orders/{$this->draft->public_id}/submit")->assertRedirect()->assertSessionHas('status');
        expect($this->draft->refresh()->status)->toBe('approved');
        $this->actingAs($this->user)->post("/procurement/orders/{$this->open->public_id}/receipts", ['lines' => [['line' => 1, 'qty' => '']]])->assertSessionHasErrors('lines');
        $this->actingAs($this->user)->post("/procurement/orders/{$this->open->public_id}/receipts", ['lines' => [['line' => 1, 'qty' => '5']]])->assertRedirect();
        $this->actingAs($this->user)->post("/procurement/orders/{$this->open->public_id}/bills", ['supplier_invoice_no' => 'SUP-2', 'lines' => [['line' => 1, 'qty' => '5']]])->assertRedirect();
        expect(GoodsReceipt::count())->toBe(2)->and(SupplierBill::count())->toBe(2)->and(PurchaseReturn::count())->toBe(1);
        $this->actingAs($this->user)->post("/procurement/receipts/{$this->receipt->public_id}/reverse", ['note' => 'x'])->assertSessionHasErrors('form');
    });

    it('shows only what the person may do', function () {
        $reader = userWithPermissions('procurement.view');
        $this->actingAs($reader)->get('/procurement/orders')->assertOk()->assertDontSee('New purchase order');
        $this->actingAs($reader)->get('/procurement/orders/create')->assertForbidden();
        $this->actingAs($reader)->get("/procurement/orders/{$this->draft->public_id}")->assertOk()->assertDontSee('Submit for approval');
        $this->actingAs($reader)->post("/procurement/orders/{$this->draft->public_id}/submit")->assertForbidden();
        $this->actingAs($reader)->post("/procurement/receipts/{$this->receipt->public_id}/reverse")->assertForbidden();
        $this->actingAs(userWithPermissions('masterdata.item.view'))->get('/procurement/orders')->assertForbidden();
        $this->actingAs($this->user)->get('/')->assertSee('Purchase orders')->assertSee('Supplier bills');
    });

    it('gives purchasing staff the buying permissions but not bills, price overrides or reversing', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $granted = Role::findByName('purchasing', 'web')->permissions->pluck('name');

        expect($granted)->toContain('procurement.view', 'procurement.order.manage', 'procurement.receipt.post', 'procurement.return.post', 'masterdata.supplier.view')
            ->not->toContain('procurement.bill.post', 'procurement.bill.override', 'procurement.reverse', 'masterdata.supplier.manage');
        expect(Role::findByName('accountant', 'web')->permissions->pluck('name'))->toContain('procurement.bill.post', 'procurement.view')->not->toContain('procurement.bill.override');
    });
});
