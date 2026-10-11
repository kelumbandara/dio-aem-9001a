<?php

use App\Modules\Core\Models\Branch;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = invUser();
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->item = mdItem(['name' => 'Widget']);
});

describe('warehouses', function () {
    it('creates a warehouse with a default location and updates it', function () {
        $id = asApi($this->user)->postJson('/api/v1/warehouses', ['code' => 'shop', 'name' => 'Shop floor'], idemKey())->assertCreated()
            ->assertJsonPath('data.code', 'SHOP')->assertJsonPath('data.locations.0.code', 'MAIN')->assertJsonPath('data.locations.0.is_default', true)
            ->assertJsonPath('data.uses_locations', false)->json('data.id');

        asApi($this->user)->patchJson("/api/v1/warehouses/{$id}", ['name' => 'Retail shop', 'uses_locations' => true, 'version' => 1])->assertOk()
            ->assertJsonPath('data.name', 'Retail shop')->assertJsonPath('data.version', 2);
        asApi($this->user)->postJson("/api/v1/warehouses/{$id}/locations", ['code' => 'a1', 'name' => 'Rack A1'], idemKey())->assertCreated()->assertJsonCount(2, 'data.locations');
        asApi($this->user)->getJson('/api/v1/warehouses')->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
    });

    it('keeps codes unique and fixed, and refuses stale updates', function () {
        asApi($this->user)->postJson('/api/v1/warehouses', ['code' => 'MAIN', 'name' => 'Again'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'inventory.code_taken');
        asApi($this->user)->patchJson("/api/v1/warehouses/{$this->wh->public_id}", ['code' => 'OTHER', 'version' => 1])->assertStatus(422)->assertJsonPath('error.code', 'inventory.code_locked');
        asApi($this->user)->patchJson("/api/v1/warehouses/{$this->wh->public_id}", ['name' => 'x', 'version' => 7])->assertStatus(409)->assertJsonPath('error.code', 'concurrency.stale_version');
    });

    it('refuses to deactivate a warehouse that holds stock', function () {
        invReceive($this->item, $this->wh, '5', '10');

        asApi($this->user)->patchJson("/api/v1/warehouses/{$this->wh->public_id}", ['is_active' => false, 'version' => $this->wh->version])->assertStatus(422)->assertJsonPath('error.code', 'inventory.in_use');
    });

    it('links a branch by code', function () {
        $branch = Branch::factory()->create(['code' => 'COL']);

        asApi($this->user)->postJson('/api/v1/warehouses', ['code' => 'COL1', 'name' => 'Colombo', 'branch' => 'col'], idemKey())->assertCreated()->assertJsonPath('data.branch', "COL — {$branch->name}");
        asApi($this->user)->postJson('/api/v1/warehouses', ['code' => 'X', 'name' => 'x', 'branch' => 'NOPE'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'inventory.unknown_code');
    });

    it('needs the permissions', function () {
        $viewer = userWithPermissions('inventory.warehouse.view');

        asApi($viewer)->getJson('/api/v1/warehouses')->assertOk();
        asApi($viewer)->postJson('/api/v1/warehouses', ['code' => 'Z', 'name' => 'z'], idemKey())->assertForbidden();
        asApi(userWithPermissions('inventory.stock.view'))->getJson('/api/v1/warehouses')->assertForbidden();
    });
});

describe('stock documents', function () {
    it('posts each kind and shows the result', function () {
        $shop = invWarehouse(['code' => 'SHOP']);
        $code = $this->item->code;

        $receipt = asApi($this->user)->postJson('/api/v1/stock-documents', docPayload('receipt', ['item' => $code, 'qty' => '10', 'warehouse' => 'MAIN', 'unit_cost' => '100']), idemKey())
            ->assertCreated()->assertJsonPath('data.type', 'receipt')->assertJsonPath('data.total_value', '1000.0000')->assertJsonPath('data.lines.0.item_code', $code)
            ->assertJsonPath('data.lines.0.to.warehouse', 'MAIN');
        asApi($this->user)->postJson('/api/v1/stock-documents', docPayload('issue', ['item' => $code, 'qty' => '2', 'warehouse' => 'MAIN']), idemKey())->assertCreated()->assertJsonPath('data.total_value', '200.0000');
        asApi($this->user)->postJson('/api/v1/stock-documents', docPayload('transfer', ['item' => $code, 'qty' => '3', 'warehouse' => 'MAIN', 'to_warehouse' => 'SHOP']), idemKey())
            ->assertCreated()->assertJsonPath('data.lines.0.from.warehouse', 'MAIN')->assertJsonPath('data.lines.0.to.warehouse', 'SHOP');
        asApi($this->user)->postJson('/api/v1/stock-documents', docPayload('adjustment', ['item' => $code, 'qty' => '1', 'warehouse' => 'MAIN', 'direction' => 'out'], ['reason' => 'damage']), idemKey())
            ->assertCreated()->assertJsonPath('data.type', 'adjustment');

        asApi($this->user)->getJson('/api/v1/stock-documents/'.$receipt->json('data.id'))->assertOk()->assertJsonPath('data.document_no', $receipt->json('data.document_no'));
        asApi($this->user)->getJson('/api/v1/stock-documents?filter[type]=transfer')->assertOk()->assertJsonCount(1, 'data');
        expect(invOnHand($this->item))->toBe('7.000000')->and(invOnHand($this->item, $shop))->toBe('3.000000');
    });

    it('uses units, locations and lots by code', function () {
        $item = mdItem(['name' => 'Yoghurt', 'tracking' => 'lot', 'shelf_life_days' => 20, 'uoms' => [['uom' => 'BOX', 'factor' => '12']]]);
        $big = invWarehouse(['code' => 'BIG', 'uses_locations' => true]);
        app(SaveLocation::class)($big, ['code' => 'A1', 'name' => 'Rack']);

        asApi($this->user)->postJson('/api/v1/stock-documents', docPayload('receipt', ['item' => $item->code, 'qty' => '2', 'uom' => 'box', 'warehouse' => 'big', 'location' => 'a1', 'lot' => 'L-1', 'unit_cost' => '600']), idemKey())
            ->assertCreated()->assertJsonPath('data.lines.0.uom', 'BOX')->assertJsonPath('data.lines.0.base_qty', '24.000000')->assertJsonPath('data.lines.0.to.location', 'A1')->assertJsonPath('data.lines.0.lot', 'L-1');
        asApi($this->user)->getJson("/api/v1/stock/items/{$item->code}")->assertOk()->assertJsonPath('data.balances.0.lot', 'L-1')->assertJsonPath('data.balances.0.on_hand', '24.000000');
    });

    it('answers business errors in the standard format with the line and field', function () {
        asApi($this->user)->postJson('/api/v1/stock-documents', docPayload('issue', ['item' => $this->item->code, 'qty' => '1', 'warehouse' => 'MAIN']), idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'inventory.insufficient_stock')->assertJsonPath('error.details.0.field', 'lines.0');
        asApi($this->user)->postJson('/api/v1/stock-documents', docPayload('receipt', ['item' => 'NOPE', 'qty' => '1', 'warehouse' => 'MAIN', 'unit_cost' => '1']), idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'inventory.unknown_code')->assertJsonPath('error.details.0.field', 'lines.0.item');
        asApi($this->user)->postJson('/api/v1/stock-documents', docPayload('receipt', ['item' => $this->item->code, 'qty' => '1', 'warehouse' => 'NOPE', 'unit_cost' => '1']), idemKey())
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'lines.0.warehouse');
        asApi($this->user)->postJson('/api/v1/stock-documents', docPayload('receipt', ['item' => $this->item->code, 'qty' => 'abc', 'warehouse' => 'MAIN']), idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        expect(StockDocument::count())->toBe(0);
    });

    it('needs the permission of the document kind', function () {
        $receiver = userWithPermissions('inventory.receive', 'inventory.stock.view');
        $line = ['item' => $this->item->code, 'qty' => '1', 'warehouse' => 'MAIN', 'unit_cost' => '5'];

        asApi($receiver)->postJson('/api/v1/stock-documents', docPayload('receipt', $line), idemKey())->assertCreated();
        asApi($receiver)->postJson('/api/v1/stock-documents', docPayload('issue', $line), idemKey())->assertForbidden();
        asApi($receiver)->postJson('/api/v1/stock-documents', docPayload('adjustment', $line + ['direction' => 'out']), idemKey())->assertForbidden();
        asApi(userWithPermissions('inventory.receive'))->getJson('/api/v1/stock-documents')->assertForbidden();
    });

    it('replays the same request once (idempotency)', function () {
        $key = idemKey();
        $payload = docPayload('receipt', ['item' => $this->item->code, 'qty' => '4', 'warehouse' => 'MAIN', 'unit_cost' => '10']);

        $first = asApi($this->user)->postJson('/api/v1/stock-documents', $payload, $key)->assertCreated()->json('data.id');
        $again = asApi($this->user)->postJson('/api/v1/stock-documents', $payload, $key)->assertCreated()->json('data.id');

        expect($again)->toBe($first)->and(StockDocument::count())->toBe(1)->and(invOnHand($this->item))->toBe('4.000000');
    });

    it('reverses a document, once, with the reverse permission', function () {
        $id = asApi($this->user)->postJson('/api/v1/stock-documents', docPayload('receipt', ['item' => $this->item->code, 'qty' => '4', 'warehouse' => 'MAIN', 'unit_cost' => '10']), idemKey())->json('data.id');

        asApi(userWithPermissions('inventory.receive', 'inventory.stock.view'))->postJson("/api/v1/stock-documents/{$id}/reverse", [], idemKey())->assertForbidden();
        asApi($this->user)->postJson("/api/v1/stock-documents/{$id}/reverse", ['note' => 'Wrong supplier'], idemKey())->assertCreated()
            ->assertJsonPath('data.reason', 'reversal')->assertJsonPath('data.notes', 'Wrong supplier');
        asApi($this->user)->getJson("/api/v1/stock-documents/{$id}")->assertJsonPath('data.status', 'reversed');
        asApi($this->user)->postJson("/api/v1/stock-documents/{$id}/reverse", [], idemKey())->assertStatus(409)->assertJsonPath('error.code', 'inventory.already_reversed');
        expect(invOnHand($this->item))->toBe('0.000000');
    });
});

describe('reading stock', function () {
    it('lists stock per item with warehouse filtering, search, and the ledger', function () {
        $shop = invWarehouse(['code' => 'SHOP']);
        $other = mdItem(['name' => 'Gadget']);
        invReceive($this->item, $this->wh, '10', '100');
        invReceive($other, $shop, '3', '50');

        $all = asApi($this->user)->getJson('/api/v1/stock')->assertOk()->assertJsonCount(2, 'data');
        expect(collect($all->json('data'))->firstWhere('item_code', $this->item->code))->toMatchArray(['on_hand' => '10.000000', 'average_cost' => '100.000000', 'value' => '1000.0000', 'uom' => 'PCS']);
        asApi($this->user)->getJson('/api/v1/stock?filter[warehouse]=SHOP')->assertJsonCount(1, 'data')->assertJsonPath('data.0.item_code', $other->code);
        asApi($this->user)->getJson('/api/v1/stock?filter[q]=widg')->assertJsonCount(1, 'data')->assertJsonPath('data.0.item_name', 'Widget');
        asApi($this->user)->getJson("/api/v1/stock/ledger?filter[item]={$this->item->code}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.qty', '10.000000');
        asApi($this->user)->getJson('/api/v1/stock/ledger?filter[item]=NOPE')->assertNotFound();
    });

    it('lists lots by expiry and finds items below their minimum', function () {
        $lotItem = mdItem(['name' => 'Milk', 'tracking' => 'lot']);
        invReceive($lotItem, $this->wh, '5', '10', ['line' => ['lotNo' => 'A', 'expiryDate' => now('Asia/Colombo')->addDays(5)->toDateString()]]);
        invReceive($lotItem, $this->wh, '5', '10', ['line' => ['lotNo' => 'B', 'expiryDate' => now('Asia/Colombo')->addDays(200)->toDateString()]]);
        invReceive($this->item, $this->wh, '2', '10');

        asApi($this->user)->getJson('/api/v1/stock/lots')->assertOk()->assertJsonPath('data.0.lot', 'A')->assertJsonPath('data.1.lot', 'B');
        asApi($this->user)->getJson('/api/v1/stock/lots?filter[expiring_within_days]=30')->assertJsonCount(1, 'data');

        asApi($this->user)->postJson('/api/v1/reorder-rules', ['item' => $this->item->code, 'min_qty' => '5', 'reorder_qty' => '20'], idemKey())->assertCreated();
        asApi($this->user)->getJson('/api/v1/stock/below-reorder')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.item_code', $this->item->code)->assertJsonPath('data.0.shortfall', '3.000000');
        asApi($this->user)->getJson('/api/v1/stock?filter[below_reorder]=true')->assertJsonCount(1, 'data');
    });
});

describe('reorder rules', function () {
    it('creates, updates and validates rules', function () {
        $id = asApi($this->user)->postJson('/api/v1/reorder-rules', ['item' => $this->item->code, 'warehouse' => 'MAIN', 'min_qty' => '5'], idemKey())->assertCreated()->assertJsonPath('data.warehouse', 'MAIN')->json('data.id');

        asApi($this->user)->patchJson("/api/v1/reorder-rules/{$id}", ['min_qty' => '8', 'reorder_qty' => '30'])->assertOk()->assertJsonPath('data.min_qty', '8.000000');
        asApi($this->user)->postJson('/api/v1/reorder-rules', ['item' => $this->item->code, 'warehouse' => 'MAIN', 'min_qty' => '1'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'inventory.rule_exists');
        asApi($this->user)->postJson('/api/v1/reorder-rules', ['item' => $this->item->code, 'min_qty' => '1.5'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'inventory.qty_invalid');
        asApi(userWithPermissions('inventory.stock.view'))->postJson('/api/v1/reorder-rules', ['item' => $this->item->code, 'min_qty' => '1'], idemKey())->assertForbidden();
        expect(DB::table('reorder_rules')->count())->toBe(1);
    });
});

it('can be switched off as a module without touching master data', function () {
    $user = invUser();
    asApi($user)->getJson('/api/v1/stock')->assertOk();
    expect(Warehouse::count())->toBe(1);
});
