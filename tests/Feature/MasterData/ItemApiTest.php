<?php

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\OutboxEvent;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Models\UnitOfMeasure;

function itemPayload(array $o = []): array
{
    return array_merge(['name' => 'Cotton Fabric', 'category' => 'GENERAL', 'base_uom' => 'M'], $o);
}

describe('permissions', function () {
    it('keeps items from people without the permission', function () {
        $item = mdItem();
        $user = userWithPermissions('masterdata.customer.view');

        asApi($user)->getJson('/api/v1/items')->assertForbidden()->assertJsonPath('error.code', 'auth.forbidden');
        asApi($user)->getJson("/api/v1/items/{$item->public_id}")->assertForbidden();
        asApi($user)->postJson('/api/v1/items', itemPayload(), idemKey())->assertForbidden();
        asApi($user)->patchJson("/api/v1/items/{$item->public_id}", ['version' => 1, 'name' => 'x'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/items')->assertUnauthorized();
    });

    it('lets viewers read but not write', function () {
        $item = mdItem();
        $viewer = userWithPermissions('masterdata.item.view');

        asApi($viewer)->getJson('/api/v1/items')->assertOk();
        asApi($viewer)->getJson("/api/v1/items/{$item->public_id}")->assertOk()->assertJsonPath('data.code', $item->code);
        asApi($viewer)->postJson('/api/v1/items', itemPayload(), idemKey())->assertForbidden();
        asApi($viewer)->patchJson("/api/v1/items/{$item->public_id}", ['version' => 1, 'name' => 'x'])->assertForbidden();
    });

    it('is unavailable when the module is switched off', function () {
        app(ModuleRegistry::class)->disable('manufacturing');
        app(ModuleRegistry::class)->disable('procurement');
        app(ModuleRegistry::class)->disable('sales');
        app(ModuleRegistry::class)->disable('finance');     // these depend on Master Data
        app(ModuleRegistry::class)->disable('inventory');
        app(ModuleRegistry::class)->disable('communications');
        app(ModuleRegistry::class)->disable('masterdata');

        asApi(masterDataUser())->getJson('/api/v1/items')->assertForbidden()->assertJsonPath('error.code', 'core.module_disabled');
    });
});

describe('creating', function () {
    beforeEach(fn () => $this->user = masterDataUser());

    it('creates an item with a generated code and sensible defaults', function () {
        $response = asApi($this->user)->postJson('/api/v1/items', itemPayload(), idemKey())->assertCreated()
            ->assertJsonPath('data.code', 'ITM-00001')
            ->assertJsonPath('data.category.code', 'GENERAL')
            ->assertJsonPath('data.base_uom.code', 'M')->assertJsonPath('data.base_uom.decimals', 3)
            ->assertJsonPath('data.item_type', 'stock')->assertJsonPath('data.tracking', 'none')
            ->assertJsonPath('data.is_sellable', true)->assertJsonPath('data.is_purchasable', true)->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.version', 1)
            ->assertHeader('Location');

        asApi($this->user)->postJson('/api/v1/items', itemPayload(['name' => 'Second']), idemKey())->assertJsonPath('data.code', 'ITM-00002');
        expect(Item::count())->toBe(2)->and($response->json('data.id'))->toMatch('/^[0-9a-f-]{36}$/');
    });

    it('accepts a code you choose, upper-cases it, and refuses duplicates', function () {
        asApi($this->user)->postJson('/api/v1/items', itemPayload(['code' => 'fab-001']), idemKey())->assertCreated()->assertJsonPath('data.code', 'FAB-001');
        asApi($this->user)->postJson('/api/v1/items', itemPayload(['code' => 'FAB-001']), idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'masterdata.code_taken');
    });

    it('skips generated codes that were already taken by hand', function () {
        asApi($this->user)->postJson('/api/v1/items', itemPayload(['code' => 'ITM-00001']), idemKey())->assertCreated();

        asApi($this->user)->postJson('/api/v1/items', itemPayload(), idemKey())->assertCreated()->assertJsonPath('data.code', 'ITM-00002');
    });

    it('stores additional units with their factors', function () {
        asApi($this->user)->postJson('/api/v1/items', itemPayload([
            'base_uom' => 'PCS', 'uoms' => [['uom' => 'BOX', 'factor' => '12'], ['uom' => 'DOZ', 'factor' => '12.000000', 'for_sale' => false]],
        ]), idemKey())->assertCreated()
            ->assertJsonPath('data.uoms.0.uom', 'BOX')->assertJsonPath('data.uoms.0.factor', '12.000000')
            ->assertJsonPath('data.uoms.1.for_sale', false);
    });

    it('supports lot tracking with shelf life, tax code and barcode', function () {
        asApi($this->user)->postJson('/api/v1/items', itemPayload([
            'base_uom' => 'KG', 'tracking' => 'lot', 'shelf_life_days' => 365, 'barcode' => '4791234567890', 'default_tax_code' => 'VAT',
        ]), idemKey())->assertCreated()
            ->assertJsonPath('data.tracking', 'lot')->assertJsonPath('data.shelf_life_days', 365)
            ->assertJsonPath('data.barcode', '4791234567890')->assertJsonPath('data.default_tax_code', 'VAT');
    });

    it('replays an identical request with the same idempotency key', function () {
        $key = idemKey();
        $first = asApi($this->user)->postJson('/api/v1/items', itemPayload(), $key)->assertCreated();
        $again = asApi($this->user)->postJson('/api/v1/items', itemPayload(), $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        expect($again->json('data.id'))->toBe($first->json('data.id'))->and(Item::count())->toBe(1);
        asApi($this->user)->postJson('/api/v1/items', itemPayload(), [])->assertStatus(400)->assertJsonPath('error.code', 'idempotency.key_required');
    });

    it('audits the change and publishes an integration event', function () {
        $id = asApi($this->user)->postJson('/api/v1/items', itemPayload(), idemKey())->json('data.id');
        $item = Item::where('public_id', $id)->first();

        expect(AuditLog::where('auditable_type', $item->getMorphClass())->where('auditable_id', $item->id)->where('event', 'created')->count())->toBe(1);
        $event = OutboxEvent::where('event_type', 'masterdata.item.changed')->sole();
        expect($event->payload)->toMatchArray(['item_id' => $item->id, 'code' => $item->code, 'created' => true]);
    });
});

describe('validation', function () {
    beforeEach(fn () => $this->user = masterDataUser());

    it('rejects bad input with field details', function (array $override, string $field) {
        asApi($this->user)->postJson('/api/v1/items', itemPayload($override), idemKey())
            ->assertStatus(422)->assertJsonPath('error.details.0.field', $field);
    })->with([
        'missing name' => [['name' => ''], 'name'],
        'unknown category' => [['category' => 'NOPE'], 'category'],
        'unknown base unit' => [['base_uom' => 'ZZZ'], 'base_uom'],
        'unknown tax code' => [['default_tax_code' => 'NOPE'], 'default_tax_code'],
        'bad type' => [['item_type' => 'magic'], 'item_type'],
        'lot tracking on a service' => [['item_type' => 'service', 'tracking' => 'lot'], 'tracking'],
        'shelf life without lot tracking' => [['shelf_life_days' => 30], 'shelf_life_days'],
        'zero shelf life' => [['tracking' => 'lot', 'shelf_life_days' => 0], 'shelf_life_days'],
        'factor with too many decimals' => [['uoms' => [['uom' => 'BOX', 'factor' => '1.1234567']]], 'uoms.0.factor'],
        'zero factor' => [['uoms' => [['uom' => 'BOX', 'factor' => '0']]], 'uoms.0.factor'],
        'unit equal to base' => [['uoms' => [['uom' => 'M', 'factor' => '1']]], 'uoms.0.uom_id'],
        'same unit twice' => [['uoms' => [['uom' => 'BOX', 'factor' => '2'], ['uom' => 'BOX', 'factor' => '3']]], 'uoms.1.uom_id'],
        'unknown extra unit' => [['uoms' => [['uom' => 'ZZZ', 'factor' => '2']]], 'uoms.0.uom'],
    ]);

    it('refuses a duplicate barcode and inactive references', function () {
        asApi($this->user)->postJson('/api/v1/items', itemPayload(['barcode' => '123']), idemKey())->assertCreated();
        asApi($this->user)->postJson('/api/v1/items', itemPayload(['barcode' => '123']), idemKey())->assertStatus(422)->assertJsonPath('error.code', 'masterdata.barcode_taken');

        UnitOfMeasure::where('code', 'SQM')->update(['is_active' => false]);
        ItemCategory::where('code', 'GENERAL')->update(['is_active' => false]);
        asApi($this->user)->postJson('/api/v1/items', itemPayload(['base_uom' => 'SQM', 'category' => 'GENERAL']), idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'masterdata.reference_invalid');
    });

    it('reports validation problems in the standard envelope', function () {
        asApi($this->user)->postJson('/api/v1/items', ['version' => 3], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);
    });
});

describe('listing and reading', function () {
    beforeEach(function () {
        $this->user = masterDataUser();
        $this->rice = mdItem(['name' => 'Basmati Rice', 'base_uom' => 'KG', 'barcode' => '111']);
        $this->soap = mdItem(['name' => 'Soap bar', 'base_uom' => 'PCS']);
        $this->consult = mdItem(['name' => 'Consulting', 'item_type' => 'service', 'base_uom' => 'HR', 'is_purchasable' => false]);
        $this->old = mdItem(['name' => 'Retired thing', 'is_active' => false]);
    });

    it('lists with the standard envelope and sorts', function () {
        asApi($this->user)->getJson('/api/v1/items?sort=-name&per_page=2')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'category', 'base_uom', 'uoms', 'version']], 'links', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.total', 4)->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Soap bar');
    });

    it('filters by text, category, type and flags', function () {
        $ids = fn (string $qs) => collect(asApi($this->user)->getJson("/api/v1/items?{$qs}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        expect($ids('filter[q]=rice'))->toBe(['Basmati Rice'])
            ->and($ids('filter[q]=111'))->toBe(['Basmati Rice'])                      // barcode
            ->and($ids('filter[q]='.$this->soap->code))->toBe(['Soap bar'])
            ->and($ids('filter[item_type]=service'))->toBe(['Consulting'])
            ->and($ids('filter[is_active]=false'))->toBe(['Retired thing'])
            ->and($ids('filter[is_purchasable]=false'))->toBe(['Consulting'])
            ->and($ids('filter[category]=GENERAL'))->toHaveCount(4);
        asApi($this->user)->getJson('/api/v1/items?filter[category]=NOPE')->assertStatus(422);
    });

    it('treats LIKE wildcards in the search literally and caps the page size', function () {
        asApi($this->user)->getJson('/api/v1/items?filter[q]=%25')->assertOk()->assertJsonPath('meta.total', 0);
        asApi($this->user)->getJson('/api/v1/items?per_page=500')->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        asApi($this->user)->getJson('/api/v1/items?sort=password')->assertOk();            // unknown sort falls back, no SQL error
    });

    it('answers 404 for an unknown id', function () {
        asApi($this->user)->getJson('/api/v1/items/'.Str::uuid())->assertNotFound()->assertJsonPath('error.code', 'resource.not_found');
    });
});

describe('updating', function () {
    beforeEach(function () {
        $this->user = masterDataUser();
        $this->item = mdItem(['name' => 'Original', 'base_uom' => 'PCS', 'uoms' => [['uom' => 'BOX', 'factor' => '12']]]);
    });

    it('changes only what was sent, keeps units, and bumps the version', function () {
        asApi($this->user)->patchJson("/api/v1/items/{$this->item->public_id}", ['version' => 1, 'name' => 'Renamed', 'barcode' => '999'])->assertOk()
            ->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.barcode', '999')->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.code', $this->item->code)->assertJsonPath('data.uoms.0.uom', 'BOX');
    });

    it('detects a stale version', function () {
        asApi($this->user)->patchJson("/api/v1/items/{$this->item->public_id}", ['version' => 1, 'name' => 'A'])->assertOk();

        asApi($this->user)->patchJson("/api/v1/items/{$this->item->public_id}", ['version' => 1, 'name' => 'B'])
            ->assertStatus(409)->assertJsonPath('error.code', 'concurrency.stale_version');
        expect($this->item->fresh()->name)->toBe('A');
        asApi($this->user)->patchJson("/api/v1/items/{$this->item->public_id}", ['name' => 'C'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'version');
    });

    it('locks code, type, tracking and base unit after creation', function (array $change) {
        asApi($this->user)->patchJson("/api/v1/items/{$this->item->public_id}", ['version' => 1, ...$change])
            ->assertStatus(422)->assertJsonPath('error.code', 'masterdata.item_field_locked');
        expect($this->item->fresh()->version)->toBe(1);
    })->with([[['base_uom' => 'KG']], [['item_type' => 'service']], [['tracking' => 'lot']], [['code' => 'OTHER']]]);

    it('replaces the unit list when one is sent and updates a factor', function () {
        asApi($this->user)->patchJson("/api/v1/items/{$this->item->public_id}", ['version' => 1, 'uoms' => [['uom' => 'BOX', 'factor' => '24'], ['uom' => 'DOZ', 'factor' => '12']]])
            ->assertOk()->assertJsonCount(2, 'data.uoms')->assertJsonPath('data.uoms.0.factor', '24.000000');

        asApi($this->user)->patchJson("/api/v1/items/{$this->item->public_id}", ['version' => 2, 'uoms' => []])->assertOk()->assertJsonCount(0, 'data.uoms');
        expect(AuditLog::where('event', 'item.uoms_changed')->count())->toBe(3);   // creation + the two updates
    });

    it('will not remove a unit that a price list still prices in', function () {
        $list = mdPriceList();
        mdPrices($list, [['item' => $this->item->code, 'uom' => 'BOX', 'unit_price' => '1000']]);

        asApi($this->user)->patchJson("/api/v1/items/{$this->item->public_id}", ['version' => 1, 'uoms' => []])
            ->assertStatus(422)->assertJsonPath('error.code', 'masterdata.uom_in_use');
    });

    it('deactivates an item without losing it', function () {
        asApi($this->user)->patchJson("/api/v1/items/{$this->item->public_id}", ['version' => 1, 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        expect(Item::count())->toBe(1);
    });
});
