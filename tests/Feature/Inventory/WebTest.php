<?php

use App\Modules\Inventory\Actions\SaveReorderRule;
use App\Modules\Inventory\Approvals\StockAdjustmentPost;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Arr;

beforeEach(function () {
    $this->user = invUser();
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->item = mdItem(['name' => 'Widget']);
});

describe('pages open and show the data', function () {
    it('renders every screen without raw translation keys', function (string $locale) {
        $this->user->forceFill(['locale' => $locale])->save();
        $lot = mdItem(['name' => 'Yoghurt', 'tracking' => 'lot', 'shelf_life_days' => 10]);
        $posted = invReceive($this->item, $this->wh, '10', '100');
        invReceive($lot, $this->wh, '5', '20', ['line' => ['lotNo' => 'L1']]);
        app(SaveReorderRule::class)(['item_id' => $this->item->id, 'warehouse_id' => null, 'min_qty' => '50']);

        $urls = ['/inventory/stock', '/inventory/stock?below=1&q=wid&warehouse=MAIN', "/inventory/stock/items/{$this->item->code}", '/inventory/ledger', "/inventory/ledger?item={$this->item->code}&from=2020-01-01",
            '/inventory/lots', '/inventory/lots?days=30', '/inventory/documents', '/inventory/documents?type=receipt&q=src', "/inventory/documents/{$posted->publicId}",
            ...array_map(fn ($t) => "/inventory/documents/new/{$t}", StockDocument::TYPES),
            '/inventory/reorder', '/inventory/warehouses', '/inventory/warehouses/create', "/inventory/warehouses/{$this->wh->public_id}", "/inventory/warehouses/{$this->wh->public_id}/edit"];

        foreach ($urls as $url) {
            $body = $this->actingAs($this->user->fresh())->get($url)->assertOk()->getContent();
            expect($body)->not->toContain('inventory::')->not->toContain('inventory.ui.', "raw key on {$url}");
        }
    })->with(['en', 'si', 'ta']);

    it('has identical translation keys in every language', function () {
        $keys = fn (string $locale) => collect(array_keys(Arr::dot(require app_path("Modules/Inventory/Lang/{$locale}/ui.php"))))->sort()->values()->all();

        foreach (['si', 'ta'] as $locale) {
            expect($keys($locale))->toBe($keys('en'), "{$locale} differs from en");
        }
    });

    it('shows the menu entries and the right buttons to people who may use them', function () {
        $this->actingAs($this->user)->get('/')->assertSee('Stock')->assertSee('Stock documents')->assertSee('Warehouses');
        $this->actingAs($this->user)->get('/inventory/stock')->assertSee('Receive stock')->assertSee('Adjust stock');
        $reader = userWithPermissions('inventory.stock.view');
        $this->actingAs($reader)->get('/inventory/stock')->assertOk()->assertDontSee('Receive stock');
        $this->actingAs($reader)->get('/inventory/documents/new/receipt')->assertForbidden();
        $this->actingAs($reader)->get('/inventory/warehouses')->assertForbidden();
        $this->actingAs(userWithPermissions('inventory.receive'))->get('/inventory/stock')->assertForbidden();
    });

    it('needs a login', function () {
        $this->get('/inventory/stock')->assertRedirect('/login');
    });
});

describe('stock screens', function () {
    it('shows what is on hand, filters it, and the item card', function () {
        $shop = invWarehouse(['code' => 'SHOP']);
        $other = mdItem(['name' => 'Gadget']);
        invReceive($this->item, $this->wh, '10', '100');
        invReceive($other, $shop, '3', '50');

        $this->actingAs($this->user)->get('/inventory/stock')->assertSee('Widget')->assertSee('Gadget');
        $this->actingAs($this->user)->get('/inventory/stock?warehouse=SHOP')->assertSee('Gadget')->assertDontSee('Widget');
        $this->actingAs($this->user)->get('/inventory/stock?q=widg')->assertSee('Widget')->assertDontSee('Gadget');
        $this->actingAs($this->user)->get("/inventory/stock/items/{$this->item->code}")->assertSee('MAIN')->assertSee('Recent movements')->assertSee('SRC/');
        $this->actingAs($this->user)->get('/inventory/stock/items/NOPE')->assertNotFound();
    });
});

describe('posting from the forms', function () {
    it('receives stock from the form, ignoring empty rows', function () {
        $this->actingAs($this->user)->post('/inventory/documents', [
            'type' => 'receipt', 'reason' => 'purchase', 'posting_date' => now('Asia/Colombo')->toDateString(), 'reference' => 'GRN-77',
            'lines' => [
                ['item' => $this->item->code, 'qty' => '10', 'uom' => '', 'warehouse' => 'MAIN', 'location' => '', 'lot' => '', 'unit_cost' => '100', 'note' => ''],
                ['item' => '', 'qty' => '', 'warehouse' => '', 'unit_cost' => ''],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $doc = StockDocument::sole();
        expect($doc->reference)->toBe('GRN-77')->and(invOnHand($this->item))->toBe('10.000000');
        $this->actingAs($this->user)->get("/inventory/documents/{$doc->public_id}")->assertSee($doc->document_no)->assertSee('GRN-77');
    });

    it('returns to the form with the message on the line when something is wrong', function () {
        $this->actingAs($this->user)->from('/inventory/documents/new/issue')->post('/inventory/documents', [
            'type' => 'issue', 'reason' => 'sale', 'lines' => [['item' => $this->item->code, 'qty' => '5', 'warehouse' => 'MAIN']],
        ])->assertRedirect('/inventory/documents/new/issue')->assertSessionHasErrors('lines.0');

        $this->actingAs($this->user)->from('/inventory/documents/new/issue')->post('/inventory/documents', [
            'type' => 'issue', 'reason' => 'sale', 'lines' => [['item' => 'NOPE', 'qty' => '5', 'warehouse' => 'MAIN']],
        ])->assertSessionHasErrors('lines.0.item');
        expect(StockDocument::count())->toBe(0);
    });

    it('transfers, adjusts and reverses', function () {
        $shop = invWarehouse(['code' => 'SHOP']);
        invReceive($this->item, $this->wh, '10', '100');

        $this->actingAs($this->user)->post('/inventory/documents', ['type' => 'transfer', 'reason' => 'transfer', 'lines' => [['item' => $this->item->code, 'qty' => '4', 'warehouse' => 'MAIN', 'to_warehouse' => 'SHOP']]])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post('/inventory/documents', ['type' => 'adjustment', 'reason' => 'damage', 'lines' => [['item' => $this->item->code, 'qty' => '1', 'warehouse' => 'SHOP', 'direction' => 'out']]])->assertSessionHasNoErrors();
        expect(invOnHand($this->item, $shop))->toBe('3.000000');

        $adjustment = StockDocument::where('doc_type', 'adjustment')->sole();
        $this->actingAs($this->user)->post("/inventory/documents/{$adjustment->public_id}/reverse", ['note' => 'Miscounted'])->assertSessionHas('status');
        expect(invOnHand($this->item, $shop))->toBe('4.000000')->and($adjustment->fresh()->status)->toBe('reversed');
        $this->actingAs($this->user)->from('/x')->post("/inventory/documents/{$adjustment->public_id}/reverse", [])->assertSessionHasErrors();
    });

    it('does not let someone without the permission post, or reverse', function () {
        $receiver = userWithPermissions('inventory.receive', 'inventory.stock.view');
        $line = ['item' => $this->item->code, 'qty' => '1', 'warehouse' => 'MAIN', 'unit_cost' => '5'];

        $this->actingAs($receiver)->post('/inventory/documents', ['type' => 'receipt', 'reason' => 'purchase', 'lines' => [$line]])->assertSessionHasNoErrors();
        $this->actingAs($receiver)->post('/inventory/documents', ['type' => 'issue', 'reason' => 'sale', 'lines' => [$line]])->assertForbidden();
        $this->actingAs($receiver)->post('/inventory/documents/'.StockDocument::sole()->public_id.'/reverse', [])->assertForbidden();
    });

    it('tells the person when an adjustment is waiting for approval', function () {
        userWithRole('finance-approver');
        paymentPolicy(['name' => 'Adjustments', 'action_key' => StockAdjustmentPost::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);
        invReceive($this->item, $this->wh, '10', '100');

        $this->actingAs($this->user)->post('/inventory/documents', ['type' => 'adjustment', 'reason' => 'loss', 'lines' => [['item' => $this->item->code, 'qty' => '1', 'warehouse' => 'MAIN', 'direction' => 'out']]])
            ->assertRedirect('/inventory/documents')->assertSessionHas('status', fn ($m) => str_contains($m, 'needs approval') && str_contains($m, 'APR/'));
        expect(invOnHand($this->item))->toBe('10.000000');
    });
});

describe('warehouses and reorder levels', function () {
    it('creates and edits a warehouse and its locations', function () {
        $this->actingAs($this->user)->post('/inventory/warehouses', ['code' => 'shop', 'name' => 'Shop', 'uses_locations' => '1', 'is_active' => '1'])->assertSessionHasNoErrors();
        $shop = Warehouse::where('code', 'SHOP')->first();
        expect($shop->uses_locations)->toBeTrue();

        $this->actingAs($this->user)->post("/inventory/warehouses/{$shop->public_id}/locations", ['code' => 'a1', 'name' => 'Rack A1'])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->put("/inventory/warehouses/{$shop->public_id}", ['name' => 'Retail shop', 'version' => $shop->version, 'uses_locations' => '1', 'is_active' => '1'])->assertRedirect();
        $this->actingAs($this->user)->get("/inventory/warehouses/{$shop->public_id}")->assertSee('Retail shop')->assertSee('A1');

        $location = $shop->locations()->where('code', 'A1')->first();
        $this->actingAs($this->user)->put("/inventory/warehouses/{$shop->public_id}/locations/{$location->id}", ['is_active' => '0'])->assertSessionHasNoErrors();
        expect($location->fresh()->is_active)->toBeFalse();
        $default = $shop->locations()->where('is_default', true)->first();
        $this->actingAs($this->user)->from('/x')->put("/inventory/warehouses/{$shop->public_id}/locations/{$default->id}", ['is_active' => '0'])->assertSessionHasErrors('is_active');
        $this->actingAs($this->user)->from('/x')->put("/inventory/warehouses/{$shop->public_id}", ['name' => 'Stale', 'version' => 99])->assertSessionHasErrors('form');
    });

    it('manages reorder rules and shows what is below the minimum', function () {
        invReceive($this->item, $this->wh, '2', '10');

        $this->actingAs($this->user)->post('/inventory/reorder', ['item' => $this->item->code, 'min_qty' => '5', 'reorder_qty' => '20'])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->get('/inventory/reorder')->assertSee('Widget')->assertSee('Below the minimum now');
        $rule = ReorderRule::sole();
        $this->actingAs($this->user)->post("/inventory/reorder/{$rule->id}", ['min_qty' => '1', 'reorder_qty' => ''])->assertSessionHasNoErrors();
        expect($rule->fresh())->min_qty->toBe('1.000000')->is_active->toBeFalse();   // an unchecked box switches the rule off
        $this->actingAs(userWithPermissions('inventory.stock.view'))->post('/inventory/reorder', ['item' => $this->item->code, 'min_qty' => '1'])->assertForbidden();
    });
});
