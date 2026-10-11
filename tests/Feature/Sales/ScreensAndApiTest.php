<?php

use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\MasterData\Actions\Reference\AddTaxRate;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\Sales\Models\CreditNote;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\QuotationService;
use App\Modules\Sales\Services\SalesCreditNoteService;
use Illuminate\Support\Arr;

const SALES_PERMISSIONS = ['sales.view', 'sales.quotation.manage', 'sales.order.manage', 'sales.order.confirm', 'sales.delivery.post', 'sales.reverse', 'sales.invoice.post', 'sales.credit_note.post'];

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    TaxCode::where('code', 'SSCL')->update(['is_active' => true]);
    app(AddTaxRate::class)(TaxCode::where('code', 'SSCL')->first(), '2.5', '2024-01-01');
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->item = mdItem(['name' => 'Widget', 'default_tax_code' => 'VAT']);
    invReceive($this->item, $this->wh, '100', '60');
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
    $this->user = userWithPermissions(...SALES_PERMISSIONS);
    $this->lines = [['item' => $this->item->code, 'qty' => '10', 'unit_price' => '100']];
    $this->payload = fn (array $o = []) => array_merge(['customer' => $this->customer->code, 'warehouse' => 'MAIN', 'lines' => $this->lines], $o);
});

describe('API', function () {
    it('runs quotation to credit note over HTTP with codes, line numbers and the same rules', function () {
        $q = asApi($this->user)->postJson('/api/v1/quotations', Arr::except(($this->payload)(), 'warehouse'), idemKey())->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.totals.gross', '1205.0000')->assertJsonPath('data.lines.0.item', $this->item->code)->json('data.id');
        asApi($this->user)->postJson("/api/v1/quotations/{$q}/sent", [], idemKey())->assertOk()->assertJsonPath('data.status', 'sent');
        asApi($this->user)->postJson("/api/v1/quotations/{$q}/accepted", [], idemKey())->assertOk();
        $order = asApi($this->user)->postJson("/api/v1/quotations/{$q}/convert", ['warehouse' => 'MAIN'], idemKey())->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        asApi($this->user)->postJson("/api/v1/orders/{$order}/confirm", [], idemKey())->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.lines.0.reserved', true);
        $delivery = asApi($this->user)->postJson("/api/v1/orders/{$order}/deliveries", ['lines' => [['line' => 1, 'qty' => '10']]], idemKey())->assertCreated()->assertJsonPath('data.lines.0.qty', '10.000000')->json('data.id');
        $invoice = asApi($this->user)->postJson("/api/v1/orders/{$order}/invoices", [], idemKey())->assertCreated()->assertJsonPath('data.totals.gross', '1205.0000')->assertJsonPath('data.taxes.1.kind', 'sscl')->json('data.id');
        asApi($this->user)->getJson("/api/v1/invoices/{$invoice}")->assertOk()->assertJsonPath('data.company.legal_name', 'My Company (Pvt) Ltd')->assertJsonPath('data.bill_to.code', $this->customer->code);
        asApi($this->user)->postJson("/api/v1/invoices/{$invoice}/credit-notes", ['reason' => 'Damaged', 'restock' => true, 'lines' => [['line' => 1, 'qty' => '2']]], idemKey())->assertCreated()
            ->assertJsonPath('data.totals.gross', '241.0000')->assertJsonPath('data.restock', true);

        asApi($this->user)->getJson('/api/v1/invoices?filter[q]=INV')->assertOk()->assertJsonCount(1, 'data');
        asApi($this->user)->getJson('/api/v1/orders?filter[status]=completed')->assertOk()->assertJsonCount(1, 'data');
        asApi($this->user)->getJson("/api/v1/deliveries/{$delivery}")->assertOk()->assertJsonPath('data.status', 'posted');
        asApi($this->user)->getJson('/api/v1/credit-notes')->assertOk()->assertJsonCount(1, 'data');
    });

    it('blocks over the credit limit with a clear error, and lets only an overrider through', function () {
        $small = app(SaveCustomer::class)(['name' => 'Small Shop', 'credit_limit' => '500']);
        $id = asApi($this->user)->postJson('/api/v1/orders', ($this->payload)(['customer' => $small->code]), idemKey())->assertCreated()->json('data.id');
        asApi($this->user)->postJson("/api/v1/orders/{$id}/confirm", ['override_reason' => 'trust me'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'sales.credit_limit_exceeded');

        $boss = userWithPermissions(...[...SALES_PERMISSIONS, 'sales.credit.override']);
        asApi($boss)->postJson("/api/v1/orders/{$id}/confirm", ['override_reason' => 'Paid in advance'], idemKey())->assertOk()->assertJsonPath('data.credit_override_reason', 'Paid in advance');
    });

    it('answers 403 without the permission, 422 with details for bad input, 409 for a stale edit', function () {
        $reader = userWithPermissions('sales.view');
        asApi($reader)->getJson('/api/v1/orders')->assertOk();
        asApi($reader)->postJson('/api/v1/orders', ($this->payload)(), idemKey())->assertForbidden();
        asApi(userWithPermissions('masterdata.item.view'))->getJson('/api/v1/orders')->assertForbidden();

        asApi($this->user)->postJson('/api/v1/orders', ($this->payload)(['lines' => [['item' => 'NOPE', 'qty' => '1']]]), idemKey())->assertStatus(422)->assertJsonPath('error.code', 'sales.item_not_found');
        asApi($this->user)->postJson('/api/v1/orders', ($this->payload)(['lines' => [['item' => $this->item->code, 'qty' => 'abc']]]), idemKey())->assertStatus(422);
        asApi($this->user)->postJson('/api/v1/orders', ($this->payload)(['warehouse' => 'NOPE']), idemKey())->assertStatus(422)->assertJsonPath('error.code', 'sales.warehouse_required');

        $id = asApi($this->user)->postJson('/api/v1/orders', ($this->payload)(), idemKey())->json('data.id');
        asApi($this->user)->putJson("/api/v1/orders/{$id}", ($this->payload)(['version' => 1]), idemKey())->assertOk()->assertJsonPath('data.version', 2);
        asApi($this->user)->putJson("/api/v1/orders/{$id}", ($this->payload)(['version' => 1]), idemKey())->assertStatus(409)->assertJsonPath('error.code', 'concurrency.stale_version');
    });

    it('refuses to deliver stock that is not there and reports it', function () {
        $id = asApi($this->user)->postJson('/api/v1/orders', ($this->payload)(['lines' => [['item' => $this->item->code, 'qty' => '500', 'unit_price' => '1']]]), idemKey())->json('data.id');
        asApi($this->user)->postJson("/api/v1/orders/{$id}/confirm", [], idemKey())->assertOk();
        asApi($this->user)->postJson("/api/v1/orders/{$id}/deliveries", [], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'inventory.insufficient_stock');
    });

    it('repeats a POST with the same idempotency key without a second document', function () {
        $key = idemKey();
        asApi($this->user)->postJson('/api/v1/orders', ($this->payload)(), $key)->assertCreated();
        asApi($this->user)->postJson('/api/v1/orders', ($this->payload)(), $key)->assertCreated();
        expect(Order::count())->toBe(1);
    });
});

describe('screens', function () {
    beforeEach(function () {
        $this->quotation = app(QuotationService::class)->save(['customer_id' => $this->customer->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '10', 'unit_price' => '100']]]);
        $this->order = app(OrderService::class)->confirm(app(OrderService::class)->save(['customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '10', 'unit_price' => '100']]]));
        $this->delivery = app(DeliveryService::class)->post($this->order, []);
        $this->invoice = app(InvoiceService::class)->create($this->order->refresh(), []);
        $this->note = app(SalesCreditNoteService::class)->create($this->invoice, ['reason' => 'Damaged', 'lines' => [['line' => 1, 'qty' => '1']]]);
        $this->draftOrder = app(OrderService::class)->save(['customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '1', 'unit_price' => '100']]]);
        BankAccount::where('currency', 'LKR')->first()->update(['show_on_invoices' => true]);
    });

    it('renders every screen in all three languages without raw translation keys', function (string $locale) {
        $this->user->forceFill(['locale' => $locale])->save();
        $urls = ['/sales/quotations', '/sales/quotations?status=draft&q=quo', '/sales/quotations/create', "/sales/quotations/{$this->quotation->public_id}", "/sales/quotations/{$this->quotation->public_id}/edit",
            '/sales/orders', '/sales/orders?status=completed', '/sales/orders/create', "/sales/orders/{$this->order->public_id}", "/sales/orders/{$this->draftOrder->public_id}", "/sales/orders/{$this->draftOrder->public_id}/edit",
            '/sales/deliveries', "/sales/deliveries/{$this->delivery->public_id}", '/sales/invoices', "/sales/invoices/{$this->invoice->public_id}", "/sales/invoices/{$this->invoice->public_id}/print",
            '/sales/credit-notes', "/sales/credit-notes/{$this->note->public_id}"];

        foreach ($urls as $url) {
            $body = $this->actingAs($this->user->fresh())->get($url)->assertOk()->getContent();
            expect($body)->not->toContain('sales::')->not->toContain('sales.ui.', "raw key on {$url}");
        }
    })->with(['en', 'si', 'ta']);

    it('has identical translation keys in every language', function () {
        $keys = fn (string $locale) => collect(array_keys(Arr::dot(require app_path("Modules/Sales/Lang/{$locale}/ui.php"))))->sort()->values()->all();

        foreach (['si', 'ta'] as $locale) {
            expect($keys($locale))->toBe($keys('en'), "{$locale} differs from en");
        }
    });

    it('prints a tax invoice with the company, customer, taxes, bank and totals', function () {
        $this->actingAs($this->user)->get("/sales/invoices/{$this->invoice->public_id}/print")->assertOk()
            ->assertSee($this->invoice->document_no)->assertSee('Tax invoice')->assertSee('Lanka Traders')->assertSee('Social Security Contribution Levy')
            ->assertSee('1,205.00')->assertSee('Please pay to')->assertSee('My Company (Pvt) Ltd');
    });

    it('creates documents through the forms and keeps what was typed when the rules say no', function () {
        $this->actingAs($this->user)->post('/sales/orders', ['customer' => $this->customer->code, 'warehouse' => 'MAIN', 'lines' => [['item' => $this->item->code, 'qty' => '3', 'unit_price' => '100']]])
            ->assertRedirect()->assertSessionHas('status');
        expect(Order::count())->toBe(3);

        $this->actingAs($this->user)->from('/sales/orders/create')->post('/sales/orders', ['customer' => $this->customer->code, 'warehouse' => 'MAIN', 'lines' => [['item' => 'NOPE', 'qty' => '3']]])
            ->assertRedirect('/sales/orders/create')->assertSessionHasErrors('lines.0.item')->assertSessionHasInput('customer', $this->customer->code);
        expect(Order::count())->toBe(3);

        $this->actingAs($this->user)->post("/sales/invoices/{$this->invoice->public_id}/credit-notes", ['reason' => 'x', 'lines' => [['line' => 1, 'qty' => '']]])->assertSessionHasErrors('lines');
        $this->actingAs($this->user)->post("/sales/invoices/{$this->invoice->public_id}/credit-notes", ['reason' => 'Return', 'lines' => [['line' => 1, 'qty' => '2']]])->assertRedirect();
        expect(CreditNote::count())->toBe(2);
    });

    it('shows only what the person may do', function () {
        $reader = userWithPermissions('sales.view');
        $this->actingAs($reader)->get('/sales/orders')->assertOk()->assertDontSee('New sales order');
        $this->actingAs($reader)->get('/sales/orders/create')->assertForbidden();
        $this->actingAs($reader)->get("/sales/orders/{$this->draftOrder->public_id}")->assertOk()->assertDontSee('Confirm order');
        $this->actingAs($reader)->post("/sales/orders/{$this->draftOrder->public_id}/confirm")->assertForbidden();
        $this->actingAs($reader)->post("/sales/deliveries/{$this->delivery->public_id}/reverse")->assertForbidden();
        $this->actingAs(userWithPermissions('masterdata.item.view'))->get('/sales/orders')->assertForbidden();

        $this->actingAs($this->user)->get('/')->assertSee('Quotations')->assertSee('Sales orders')->assertSee('Invoices');
        expect(Quotation::count() + Delivery::count() + Invoice::count())->toBe(3);
    });
});
