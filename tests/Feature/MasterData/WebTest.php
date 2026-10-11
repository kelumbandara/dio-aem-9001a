<?php

use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\MasterData\Approvals\SupplierBankAccountChange;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\PriceListItem;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\SupplierBankAccount;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\UnitOfMeasure;
use Illuminate\Support\Arr;

beforeEach(fn () => $this->user = masterDataUser());

describe('pages open and show the data', function () {
    it('renders every list, form and detail page without raw translation keys', function (string $locale) {
        $this->user->forceFill(['locale' => $locale])->save();
        $item = mdItem(['name' => 'Blue Widget', 'uoms' => [['uom' => 'BOX', 'factor' => '12']]]);
        $customer = app(SaveCustomer::class)(['name' => 'Lanka Traders', 'contacts' => [['name' => 'Nimal', 'is_primary' => true]]]);
        $supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
        $list = mdPriceList(['name' => 'Retail']);
        mdPrices($list, [['item' => $item->code, 'unit_price' => '125.5']]);

        $urls = ['/items', '/items/create', "/items/{$item->public_id}", "/items/{$item->public_id}/edit",
            '/customers', '/customers/create', "/customers/{$customer->public_id}", "/customers/{$customer->public_id}/edit",
            '/suppliers', '/suppliers/create', "/suppliers/{$supplier->public_id}", "/suppliers/{$supplier->public_id}/edit",
            '/price-lists', '/price-lists/create', "/price-lists/{$list->public_id}", "/price-lists/{$list->public_id}/edit",
            '/masterdata/import', ...array_map(fn ($k) => "/masterdata/reference/{$k}", ['units-of-measure', 'item-categories', 'currencies', 'tax-codes', 'payment-terms']),
            '/masterdata/reference/tax-codes?detail=VAT', '/masterdata/reference/currencies?detail=USD', '/masterdata/reference/units-of-measure?edit=KG'];

        foreach ($urls as $url) {
            $body = $this->actingAs($this->user->fresh())->get($url)->assertOk()->getContent();
            expect($body)->not->toContain('masterdata::')->not->toContain('masterdata.ui.', "raw key on {$url}");
        }
    })->with(['en', 'si', 'ta']);

    it('has identical translation keys in every language', function () {
        $keys = fn (string $locale) => collect(array_keys(Arr::dot(require app_path("Modules/MasterData/Lang/{$locale}/ui.php"))))->sort()->values()->all();

        foreach (['si', 'ta'] as $locale) {
            expect($keys($locale))->toBe($keys('en'), "{$locale} differs from en");
        }
    });

    it('lists and filters', function () {
        mdItem(['name' => 'Blue Widget']);
        $gone = mdItem(['name' => 'Retired Gadget', 'is_active' => false]);

        $this->actingAs($this->user)->get('/items')->assertSee('Blue Widget')->assertDontSee('Retired Gadget');
        $this->actingAs($this->user)->get('/items?status=all&q=gadget')->assertSee('Retired Gadget')->assertDontSee('Blue Widget');
        $this->actingAs($this->user)->get('/items?status=inactive')->assertSee($gone->code);
    });

    it('shows the menu entries to people who may see them', function () {
        $this->actingAs($this->user)->get('/')->assertSee('Items')->assertSee('Price lists')->assertSee('Reference data')->assertSee('Import');
        $this->actingAs(userWithPermissions('masterdata.item.view'))->get('/')->assertSee('Items')->assertDontSee('Price lists');
    });
});

describe('access', function () {
    it('keeps screens from people without permission', function (string $url) {
        $this->actingAs(userWithPermissions('masterdata.reference.view'))->get($url)->assertForbidden();
    })->with(['/items', '/customers', '/suppliers', '/price-lists', '/items/create', '/masterdata/import']);

    it('needs a login', function () {
        $this->get('/items')->assertRedirect('/login');
    });

    it('does not let a viewer edit or save', function () {
        $item = mdItem();
        $viewer = userWithPermissions('masterdata.item.view');

        $this->actingAs($viewer)->get("/items/{$item->public_id}")->assertOk()->assertDontSee('/edit');
        $this->actingAs($viewer)->get("/items/{$item->public_id}/edit")->assertForbidden();
        $this->actingAs($viewer)->post('/items', ['name' => 'x', 'category' => 'GENERAL', 'base_uom' => 'PCS'])->assertForbidden();
    });
});

describe('items', function () {
    it('creates an item from the form and numbers it', function () {
        $this->actingAs($this->user)->post('/items', [
            'name' => 'Cotton Fabric', 'category' => 'GENERAL', 'item_type' => 'stock', 'base_uom' => 'M', 'tracking' => 'none',
            'is_sellable' => '1', 'is_purchasable' => '0', 'is_active' => '1', 'default_tax_code' => 'VAT',
            'uoms' => [['uom' => 'CM', 'factor' => '0.01', 'for_purchase' => '0', 'for_sale' => '1'], ['uom' => '', 'factor' => '']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $item = Item::sole();
        expect($item->code)->toStartWith('ITM-')->and($item->is_purchasable)->toBeFalse()->and($item->uoms)->toHaveCount(1)
            ->and($item->defaultTaxCode->code)->toBe('VAT');
        $this->actingAs($this->user)->get("/items/{$item->public_id}")->assertSee('Cotton Fabric')->assertSee('CM');
    });

    it('sends a business-rule failure back to the form with what was typed', function () {
        $this->actingAs($this->user)->from('/items/create')->post('/items', ['name' => 'Bad', 'category' => 'NOPE', 'base_uom' => 'PCS'])
            ->assertRedirect('/items/create')->assertSessionHasErrors('category');
        expect(Item::count())->toBe(0);
    });

    it('edits an item but never the locked fields', function () {
        $item = mdItem(['name' => 'Widget', 'base_uom' => 'PCS']);

        $this->actingAs($this->user)->get("/items/{$item->public_id}/edit")->assertOk()->assertSee('cannot be changed after creation');
        $this->actingAs($this->user)->put("/items/{$item->public_id}", ['name' => 'Widget Mk2', 'category' => 'GENERAL', 'is_sellable' => '1', 'is_purchasable' => '1', 'is_active' => '1', 'version' => $item->version])
            ->assertRedirect(route('items.show', $item));

        expect($item->fresh())->name->toBe('Widget Mk2')->base_uom_id->toBe($item->base_uom_id)->version->toBe($item->version + 1);

        $this->actingAs($this->user)->from('/x')->put("/items/{$item->public_id}", ['name' => 'Sneaky', 'category' => 'GENERAL', 'base_uom' => 'KG', 'version' => $item->fresh()->version])
            ->assertSessionHasErrors();
        expect($item->fresh()->name)->toBe('Widget Mk2');
    });

    it('refuses a stale form instead of overwriting someone else', function () {
        $item = mdItem(['name' => 'Widget']);
        $this->actingAs($this->user)->put("/items/{$item->public_id}", ['name' => 'First', 'category' => 'GENERAL', 'version' => $item->version]);

        $this->actingAs($this->user)->from('/items/edit')->put("/items/{$item->public_id}", ['name' => 'Second', 'category' => 'GENERAL', 'version' => $item->version])
            ->assertRedirect('/items/edit')->assertSessionHasErrors('form');
        expect($item->fresh()->name)->toBe('First');
    });
});

describe('customers and suppliers', function () {
    it('creates a customer with contacts and addresses, dropping blank rows', function () {
        $this->actingAs($this->user)->post('/customers', [
            'name' => 'Lanka Traders', 'kind' => 'company', 'currency' => 'LKR', 'payment_terms' => 'NET30', 'credit_limit' => '500000', 'is_active' => '1',
            'contacts' => [['id' => '', 'name' => 'Nimal', 'phone' => '0771234567', 'is_primary' => '1'], ['id' => '', 'name' => '', 'is_primary' => '0']],
            'addresses' => [['id' => '', 'type' => 'billing', 'line1' => '12 Galle Road', 'city' => 'Colombo', 'country' => 'LK', 'is_default' => '1'], ['id' => '', 'type' => 'billing', 'country' => 'LK', 'is_default' => '0']],
        ])->assertRedirect();

        $customer = Customer::with(['contacts', 'addresses'])->sole();
        expect($customer->code)->toStartWith('CUS-')->and($customer->contacts)->toHaveCount(1)->and($customer->addresses)->toHaveCount(1)
            ->and($customer->credit_limit)->toBe('500000.0000');
    });

    it('keeps existing contacts by id when the form is saved again', function () {
        $customer = app(SaveCustomer::class)(['name' => 'Lanka Traders', 'contacts' => [['name' => 'Nimal', 'is_primary' => true]]]);
        $contactId = $customer->contacts()->value('id');

        $this->actingAs($this->user)->put("/customers/{$customer->public_id}", [
            'name' => 'Lanka Traders (Pvt) Ltd', 'version' => $customer->version, 'is_active' => '1',
            'contacts' => [['id' => $contactId, 'name' => 'Nimal Perera', 'is_primary' => '1']],
        ])->assertRedirect();

        expect($customer->contacts()->pluck('id')->all())->toBe([$contactId])->and($customer->contacts()->value('name'))->toBe('Nimal Perera');
    });

    it('creates a supplier and manages its bank accounts', function () {
        $this->actingAs($this->user)->post('/suppliers', ['name' => 'ACME Supplies', 'lead_time_days' => '14', 'is_active' => '1'])->assertRedirect();
        $supplier = Supplier::sole();

        $this->actingAs($this->user)->post("/suppliers/{$supplier->public_id}/bank-accounts", [
            'bank_name' => 'Commercial Bank', 'account_name' => 'ACME', 'account_no' => '8001234567', 'currency' => 'LKR', 'is_default' => '1',
        ])->assertSessionHas('status');

        $page = $this->actingAs($this->user)->get("/suppliers/{$supplier->public_id}")->assertOk();
        $page->assertSee('••••4567')->assertDontSee('8001234567');
        $this->actingAs(userWithPermissions('masterdata.supplier.view'))->get("/suppliers/{$supplier->public_id}")->assertOk()->assertDontSee('Bank accounts');

        $account = SupplierBankAccount::sole();
        $this->actingAs($this->user)->delete("/suppliers/{$supplier->public_id}/bank-accounts/{$account->id}")->assertSessionHas('status');
        expect($account->fresh()->is_active)->toBeFalse();
    });

    it('tells the person when a bank change is waiting for approval', function () {
        userWithRole('finance-approver');
        paymentPolicy(['name' => 'Bank changes', 'action_key' => SupplierBankAccountChange::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);
        $supplier = Supplier::query()->create(['code' => 'SUP-1', 'name' => 'ACME']);

        $this->actingAs($this->user)->post("/suppliers/{$supplier->public_id}/bank-accounts", ['bank_name' => 'HNB', 'account_name' => 'ACME', 'account_no' => '123456'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'needs approval') && str_contains($m, 'APR/'));
        expect(SupplierBankAccount::count())->toBe(0);
    });
});

describe('price lists', function () {
    it('creates a list, sets prices from pasted lines, and removes one', function () {
        $item = mdItem(['code' => 'WIDGET', 'uoms' => [['uom' => 'BOX', 'factor' => '12']]]);
        $this->actingAs($this->user)->post('/price-lists', ['name' => 'Retail', 'type' => 'sales', 'currency' => 'LKR', 'is_active' => '1', 'is_default' => '1', 'tax_inclusive' => '0'])->assertRedirect();
        $list = PriceList::sole();

        $this->actingAs($this->user)->post("/price-lists/{$list->public_id}/prices", ['lines' => "WIDGET,125.50\nWIDGET,980,BOX\nWIDGET,950,BOX,10\n"])->assertSessionHas('status');
        expect(PriceListItem::count())->toBe(3);
        $this->actingAs($this->user)->get("/price-lists/{$list->public_id}")->assertSee('WIDGET')->assertSee('125.5')->assertSee('980');

        $this->actingAs($this->user)->post("/price-lists/{$list->public_id}/prices", ['lines' => 'WIDGET,'])->assertSessionHas('status');
        expect(PriceListItem::where('item_id', $item->id)->count())->toBe(2);   // a bare code removes only the base-unit price
    });

    it('reports a bad line and applies nothing', function () {
        mdItem(['code' => 'WIDGET']);
        $list = mdPriceList();

        $this->actingAs($this->user)->from('/p')->post("/price-lists/{$list->public_id}/prices", ['lines' => "WIDGET,10\nNOPE,5"])->assertRedirect('/p')->assertSessionHasErrors();
        $this->actingAs($this->user)->from('/p')->post("/price-lists/{$list->public_id}/prices", ['lines' => "  \n"])->assertSessionHasErrors('lines');
        expect(PriceListItem::count())->toBe(0);
    });
});

describe('reference data screens', function () {
    it('adds and edits a unit', function () {
        $this->actingAs($this->user)->post('/masterdata/reference/units-of-measure', ['code' => 'BAG', 'name' => 'Bag', 'decimals' => '0', 'is_active' => '1'])->assertRedirect('/masterdata/reference/units-of-measure');
        $this->actingAs($this->user)->put('/masterdata/reference/units-of-measure/BAG', ['name' => 'Sack', 'decimals' => '0', 'is_active' => '0'])->assertSessionHas('status');

        expect(UnitOfMeasure::where('code', 'BAG')->first())->name->toBe('Sack')->is_active->toBeFalse();
    });

    it('lets an accountant add a tax rate and an exchange rate', function () {
        $vat = TaxCode::where('code', 'VAT')->first();
        $this->actingAs($this->user)->post("/masterdata/tax-codes/{$vat->public_id}/rates", ['rate' => '20', 'effective_from' => '2031-01-01'])->assertSessionHas('status');
        expect($vat->rates()->count())->toBe(2);

        $this->actingAs($this->user)->post('/masterdata/currencies/USD/rates', ['effective_on' => '2026-05-01', 'rate' => '300.5'])->assertSessionHas('status');
        $this->actingAs($this->user)->get('/masterdata/reference/currencies?detail=USD')->assertSee('300.5');
        $this->actingAs($this->user)->post('/masterdata/currencies/LKR/rates', ['effective_on' => '2026-05-01', 'rate' => '2'])->assertSessionHasErrors();
    });

    it('is read-only for viewers', function () {
        $viewer = userWithPermissions('masterdata.reference.view');

        $this->actingAs($viewer)->get('/masterdata/reference/currencies')->assertOk()->assertDontSee('Add');
        $this->actingAs($viewer)->post('/masterdata/reference/units-of-measure', ['code' => 'BAG', 'name' => 'Bag', 'decimals' => '0'])->assertForbidden();
        $this->actingAs($viewer)->get('/masterdata/reference/units-of-measure?edit=KG')->assertForbidden();
    });
});
