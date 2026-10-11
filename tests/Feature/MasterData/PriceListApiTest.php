<?php

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Core\Models\AuditLog;
use App\Modules\MasterData\Approvals\PriceListPriceChange;
use App\Modules\MasterData\Models\PriceListItem;

beforeEach(function () {
    $this->user = masterDataUser();
    $this->widget = mdItem(['name' => 'Widget', 'uoms' => [['uom' => 'BOX', 'factor' => '12']]]);
    $this->gadget = mdItem(['name' => 'Gadget']);
});

describe('price list header', function () {
    it('creates sales and purchase lists with generated codes', function () {
        asApi($this->user)->postJson('/api/v1/price-lists', ['name' => 'Retail', 'type' => 'sales', 'currency' => 'LKR'], idemKey())->assertCreated()
            ->assertJsonPath('data.code', 'PLS-00001')->assertJsonPath('data.currency', 'LKR')->assertJsonPath('data.tax_inclusive', false)->assertJsonPath('data.version', 1);
        asApi($this->user)->postJson('/api/v1/price-lists', ['name' => 'Wholesale buy', 'type' => 'purchase', 'currency' => 'USD', 'tax_inclusive' => true], idemKey())->assertCreated()
            ->assertJsonPath('data.code', 'PLP-00001')->assertJsonPath('data.tax_inclusive', true);
    });

    it('keeps a single default per type', function () {
        $a = mdPriceList(['is_default' => true]);
        $b = mdPriceList(['is_default' => true]);
        $purchase = mdPriceList(['type' => 'purchase', 'is_default' => true]);

        expect($a->fresh()->is_default)->toBeFalse()->and($b->fresh()->is_default)->toBeTrue()->and($purchase->fresh()->is_default)->toBeTrue();
        asApi($this->user)->postJson('/api/v1/price-lists', ['name' => 'Off', 'type' => 'sales', 'currency' => 'LKR', 'is_default' => true, 'is_active' => false], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'masterdata.default_inactive');
    });

    it('validates dates, currency and type', function (array $override, string $field) {
        asApi($this->user)->postJson('/api/v1/price-lists', ['name' => 'L', 'type' => 'sales', 'currency' => 'LKR', ...$override], idemKey())
            ->assertStatus(422)->assertJsonPath('error.details.0.field', $field);
    })->with([
        'end before start' => [['valid_from' => '2026-05-01', 'valid_to' => '2026-04-01'], 'valid_to'],
        'unknown currency' => [['currency' => 'ZZZ'], 'currency'],
        'bad type' => [['type' => 'barter'], 'type'],
    ]);

    it('locks type and code, and the currency once there are prices', function () {
        $list = mdPriceList();
        mdPrices($list, [['item' => $this->widget->code, 'unit_price' => '10']]);
        $url = "/api/v1/price-lists/{$list->public_id}";

        asApi($this->user)->patchJson($url, ['version' => 2, 'type' => 'purchase'])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.price_list_type_locked');
        asApi($this->user)->patchJson($url, ['version' => 2, 'code' => 'NEW'])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.code_locked');
        asApi($this->user)->patchJson($url, ['version' => 2, 'currency' => 'USD'])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.price_list_currency_locked');
        asApi($this->user)->patchJson($url, ['version' => 2, 'name' => 'Renamed'])->assertOk()->assertJsonPath('data.version', 3);
        asApi($this->user)->patchJson($url, ['version' => 2, 'name' => 'Stale'])->assertStatus(409);
    });

    it('will not deactivate a list that active customers use', function () {
        $list = mdPriceList();
        asApi($this->user)->postJson('/api/v1/customers', ['name' => 'Buyer', 'price_list' => $list->code], idemKey())->assertCreated();

        asApi($this->user)->patchJson("/api/v1/price-lists/{$list->public_id}", ['version' => 1, 'is_active' => false])
            ->assertStatus(422)->assertJsonPath('error.code', 'masterdata.in_use');
        expect($list->fresh()->is_active)->toBeTrue();
    });

    it('is visible to viewers but writable only with the manage permission', function () {
        $list = mdPriceList();
        $viewer = userWithPermissions('masterdata.price_list.view');

        asApi($viewer)->getJson('/api/v1/price-lists')->assertOk()->assertJsonPath('meta.total', 1);
        asApi($viewer)->postJson('/api/v1/price-lists', ['name' => 'x', 'type' => 'sales', 'currency' => 'LKR'], idemKey())->assertForbidden();
        asApi($viewer)->postJson("/api/v1/price-lists/{$list->public_id}/prices", ['changes' => [['item' => $this->widget->code, 'unit_price' => '1']]], idemKey())->assertForbidden();
        asApi(userWithPermissions('masterdata.item.view'))->getJson('/api/v1/price-lists')->assertForbidden();
    });
});

describe('changing prices without an approval policy', function () {
    beforeEach(function () {
        $this->list = mdPriceList();
        $this->url = "/api/v1/price-lists/{$this->list->public_id}/prices";
    });

    it('applies the prices immediately and reports the new version', function () {
        asApi($this->user)->postJson($this->url, ['changes' => [
            ['item' => $this->widget->code, 'unit_price' => '100'],
            ['item' => $this->widget->code, 'min_qty' => '10', 'unit_price' => '90.5'],
            ['item' => $this->widget->code, 'uom' => 'BOX', 'unit_price' => '1100'],
            ['item' => $this->gadget->code, 'unit_price' => '5.123456'],
        ]], idemKey())->assertOk()->assertJsonPath('data.status', 'applied')->assertJsonPath('data.price_list_version', 2);

        $rows = asApi($this->user)->getJson("{$this->url}")->assertOk()->json('data');
        expect($rows)->toHaveCount(4)
            ->and(collect($rows)->firstWhere('uom', 'BOX')['unit_price'])->toBe('1100.000000')
            ->and(collect($rows)->firstWhere('item', $this->gadget->code)['unit_price'])->toBe('5.123456');
    });

    it('updates and removes existing rows, and only bumps the version when something changed', function () {
        mdPrices($this->list, [['item' => $this->widget->code, 'unit_price' => '100'], ['item' => $this->gadget->code, 'unit_price' => '5']]);
        $version = $this->list->fresh()->version;

        asApi($this->user)->postJson($this->url, ['changes' => [['item' => $this->widget->code, 'unit_price' => '100']]], idemKey())
            ->assertOk()->assertJsonPath('data.price_list_version', $version);   // identical price: no change

        asApi($this->user)->postJson($this->url, ['changes' => [['item' => $this->widget->code, 'unit_price' => '120'], ['item' => $this->gadget->code, 'unit_price' => null]]], idemKey())
            ->assertOk()->assertJsonPath('data.price_list_version', $version + 1);

        expect(PriceListItem::where('price_list_id', $this->list->id)->count())->toBe(1)
            ->and(PriceListItem::sole()->unit_price)->toBe('120.000000');
        $log = AuditLog::where('event', 'price_list.prices_changed')->latest('id')->first();
        expect($log->metadata)->toMatchArray(['added' => 0, 'updated' => 1, 'removed' => 1]);
    });

    it('rejects a bad batch as a whole and applies none of it', function (array $bad, string $field) {
        $bad = array_map(fn ($v) => $v === 'WIDGET' ? $this->widget->code : $v, $bad);   // datasets are built before the item exists
        asApi($this->user)->postJson($this->url, ['changes' => [['item' => $this->widget->code, 'unit_price' => '10'], $bad]], idemKey())
            ->assertStatus(422)->assertJsonPath('error.details.0.field', $field);

        expect(PriceListItem::count())->toBe(0);
    })->with([
        'unknown item' => [['item' => 'NOPE', 'unit_price' => '1'], 'changes.1.item'],
        'unknown unit' => [['item' => 'WIDGET', 'uom' => 'ZZZ', 'unit_price' => '1'], 'changes.1.uom'],
        'negative price' => [['item' => 'WIDGET', 'unit_price' => '-1'], 'changes.1.unit_price'],
        'price with 7 decimals' => [['item' => 'WIDGET', 'unit_price' => '1.1234567'], 'changes.1.unit_price'],
        'bad minimum quantity' => [['item' => 'WIDGET', 'min_qty' => 'x', 'unit_price' => '1'], 'changes.1.min_qty'],
    ]);

    it('refuses items that cannot be on this kind of list, units the item lacks, and duplicates', function () {
        $service = mdItem(['name' => 'Consulting', 'item_type' => 'service', 'base_uom' => 'HR', 'is_sellable' => false]);
        $retired = mdItem(['name' => 'Retired', 'is_active' => false]);
        $post = fn (array $changes) => asApi($this->user)->postJson($this->url, ['changes' => $changes], idemKey());

        $post([['item' => $service->code, 'unit_price' => '1']])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.item_not_usable');
        $post([['item' => $retired->code, 'unit_price' => '1']])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.item_not_usable');
        $post([['item' => $this->gadget->code, 'uom' => 'BOX', 'unit_price' => '1']])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.uom_not_convertible');
        $post([['item' => $this->widget->code, 'unit_price' => '1'], ['item' => $this->widget->code, 'unit_price' => '2']])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.duplicate_change');
        $post([])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

        // a service that is not sellable can still be bought
        $purchase = mdPriceList(['type' => 'purchase']);
        $pf = fn () => asApi($this->user)->postJson("/api/v1/price-lists/{$purchase->public_id}/prices", ['changes' => [['item' => $service->code, 'unit_price' => '40']]], idemKey());
        $pf()->assertOk();
    });

    it('will not change an inactive list', function () {
        $this->list->forceFill(['is_active' => false])->save();

        asApi($this->user)->postJson($this->url, ['changes' => [['item' => $this->widget->code, 'unit_price' => '1']]], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'masterdata.price_list_inactive');
    });

    it('lists prices filtered by item', function () {
        mdPrices($this->list, [['item' => $this->widget->code, 'unit_price' => '100'], ['item' => $this->gadget->code, 'unit_price' => '5']]);

        asApi($this->user)->getJson("{$this->url}?filter[item]={$this->gadget->code}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.item', $this->gadget->code);
        asApi($this->user)->getJson("{$this->url}?filter[item]=NOPE")->assertStatus(422);
    });
});

describe('changing prices with an approval policy', function () {
    beforeEach(function () {
        $this->list = mdPriceList();
        mdPrices($this->list, [['item' => $this->widget->code, 'unit_price' => '100']]);
        $this->url = "/api/v1/price-lists/{$this->list->public_id}/prices";
        $this->approver = userWithRole('finance-approver');
        paymentPolicy(['name' => 'Price changes', 'action_key' => PriceListPriceChange::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);
        $this->change = ['changes' => [['item' => $this->widget->code, 'unit_price' => '150'], ['item' => $this->gadget->code, 'unit_price' => '9']]];
    });

    it('holds the whole change until approved', function () {
        $r = asApi($this->user)->postJson($this->url, $this->change, idemKey())->assertStatus(202)->assertJsonPath('data.status', 'pending');

        $request = ApprovalRequest::where('public_id', $r->json('data.approval_request_id'))->sole();
        expect(PriceListPriceChange::KEY)->toBe($request->action_key)
            ->and($request->summary)->toBe("Change 2 price(s) on price list {$this->list->code}")
            ->and(PriceListItem::count())->toBe(1)->and(PriceListItem::sole()->unit_price)->toBe('100.000000');

        asApi($this->approver)->postJson("/api/v1/approvals/{$request->public_id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'executed');

        expect(PriceListItem::count())->toBe(2)->and(PriceListItem::where('item_id', $this->widget->id)->value('unit_price'))->toBe('150.000000');
    });

    it('validates before opening a request, so rubbish never reaches an approver', function () {
        asApi($this->user)->postJson($this->url, ['changes' => [['item' => $this->widget->code, 'unit_price' => '-5']]], idemKey())->assertStatus(422);

        expect(ApprovalRequest::count())->toBe(0);
    });

    it('re-validates on execution: an item retired while waiting fails the request, not the data', function () {
        $id = asApi($this->user)->postJson($this->url, $this->change, idemKey())->json('data.approval_request_id');
        $this->gadget->forceFill(['is_active' => false])->save();

        app(ApprovalEngine::class)->approve($id, $this->approver->id);

        expect(ApprovalRequest::where('public_id', $id)->value('status'))->toBe('execution_failed')
            ->and(PriceListItem::where('item_id', $this->widget->id)->value('unit_price'))->toBe('100.000000');   // nothing was half-applied
    });

    it('still lets prices be read and the list edited without approval', function () {
        asApi($this->user)->patchJson("/api/v1/price-lists/{$this->list->public_id}", ['version' => 2, 'name' => 'Renamed'])->assertOk();
        asApi($this->user)->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
    });
});
