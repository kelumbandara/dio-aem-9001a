<?php

use App\Modules\Core\Models\AuditLog;
use App\Modules\MasterData\Database\Seeders\ReferenceDataSeeder;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\TaxRate;
use App\Modules\MasterData\Models\UnitOfMeasure;

beforeEach(fn () => $this->user = masterDataUser());

describe('access', function () {
    it('lets viewers read every kind and blocks writes without the manage permission', function (string $path) {
        $viewer = userWithPermissions('masterdata.reference.view');

        asApi($viewer)->getJson("/api/v1/{$path}")->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
        asApi($viewer)->postJson("/api/v1/{$path}", ['code' => 'ZZZ', 'name' => 'x'], idemKey())->assertForbidden();
        asApi(userWithPermissions('masterdata.item.view'))->getJson("/api/v1/{$path}")->assertForbidden();
    })->with(['units-of-measure', 'item-categories', 'currencies', 'tax-codes', 'payment-terms']);

    it('ships the starter data', function () {
        $codes = fn (string $path) => collect(asApi($this->user)->getJson("/api/v1/{$path}?per_page=200")->json('data'))->pluck('code')->all();

        expect($codes('units-of-measure'))->toContain('PCS', 'KG', 'L', 'M')
            ->and($codes('currencies'))->toContain('LKR', 'USD')
            ->and($codes('payment-terms'))->toContain('CASH', 'NET30')
            ->and($codes('tax-codes'))->toContain('VAT', 'EXEMPT', 'NBT', 'SSCL')
            ->and($codes('item-categories'))->toBe(['GENERAL']);
        expect(Currency::where('is_base', true)->pluck('code')->all())->toBe(['LKR']);
    });

    it('seeds idempotently without overwriting local changes', function () {
        UnitOfMeasure::where('code', 'KG')->update(['name' => 'Kilos (our name)', 'decimals' => 2]);
        TaxCode::where('code', 'NBT')->update(['is_active' => true]);

        $this->seed(ReferenceDataSeeder::class);

        expect(UnitOfMeasure::where('code', 'KG')->first())->name->toBe('Kilos (our name)')->decimals->toBe(2)
            ->and(TaxCode::where('code', 'NBT')->value('is_active'))->toBeTrue()
            ->and(UnitOfMeasure::where('code', 'PCS')->count())->toBe(1)->and(TaxRate::count())->toBe(2);
    });
});

describe('units of measure', function () {
    it('creates, reads by id or code, and updates', function () {
        $id = asApi($this->user)->postJson('/api/v1/units-of-measure', ['code' => 'bag', 'name' => 'Bag', 'decimals' => 0], idemKey())->assertCreated()
            ->assertJsonPath('data.code', 'BAG')->json('data.id');

        asApi($this->user)->getJson("/api/v1/units-of-measure/{$id}")->assertOk()->assertJsonPath('data.name', 'Bag');
        asApi($this->user)->getJson('/api/v1/units-of-measure/bag')->assertOk()->assertJsonPath('data.id', $id);
        asApi($this->user)->patchJson('/api/v1/units-of-measure/BAG', ['name' => 'Sack'])->assertOk()->assertJsonPath('data.name', 'Sack');
        asApi($this->user)->getJson('/api/v1/units-of-measure/NOPE')->assertNotFound()->assertJsonPath('error.code', 'masterdata.not_found');
    });

    it('keeps codes unique and fixed, and validates input', function () {
        asApi($this->user)->postJson('/api/v1/units-of-measure', ['code' => 'KG', 'name' => 'Dup', 'decimals' => 0], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'masterdata.code_taken');
        asApi($this->user)->patchJson('/api/v1/units-of-measure/KG', ['code' => 'KILO'])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.code_locked');
        asApi($this->user)->postJson('/api/v1/units-of-measure', ['code' => 'A B', 'name' => 'x', 'decimals' => 0], idemKey())->assertStatus(422)->assertJsonPath('error.details.0.field', 'code');
        asApi($this->user)->postJson('/api/v1/units-of-measure', ['code' => 'OK', 'name' => 'x', 'decimals' => 9], idemKey())->assertStatus(422)->assertJsonPath('error.details.0.field', 'decimals');
    });

    it('protects quantities: decimals cannot shrink once the unit is used, nor can a used base unit be deactivated', function () {
        mdItem(['base_uom' => 'KG']);

        asApi($this->user)->patchJson('/api/v1/units-of-measure/KG', ['decimals' => 1])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.uom_decimals_in_use');
        asApi($this->user)->patchJson('/api/v1/units-of-measure/KG', ['decimals' => 4])->assertOk();
        asApi($this->user)->patchJson('/api/v1/units-of-measure/KG', ['is_active' => false])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.in_use');
        asApi($this->user)->patchJson('/api/v1/units-of-measure/SQM', ['is_active' => false])->assertOk();   // unused: fine
    });
});

describe('item categories', function () {
    it('builds a tree and refuses cycles', function () {
        asApi($this->user)->postJson('/api/v1/item-categories', ['code' => 'RAW', 'name' => 'Raw materials'], idemKey())->assertCreated();
        asApi($this->user)->postJson('/api/v1/item-categories', ['code' => 'FAB', 'name' => 'Fabric', 'parent' => 'RAW'], idemKey())->assertCreated()->assertJsonPath('data.parent', 'RAW');
        asApi($this->user)->postJson('/api/v1/item-categories', ['code' => 'COT', 'name' => 'Cotton', 'parent' => 'FAB'], idemKey())->assertCreated();

        asApi($this->user)->patchJson('/api/v1/item-categories/RAW', ['parent' => 'COT'])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.category_cycle');
        asApi($this->user)->patchJson('/api/v1/item-categories/RAW', ['parent' => 'RAW'])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.category_cycle');
        asApi($this->user)->patchJson('/api/v1/item-categories/COT', ['parent' => null])->assertOk()->assertJsonPath('data.parent', null);
        asApi($this->user)->postJson('/api/v1/item-categories', ['code' => 'BAD', 'name' => 'x', 'parent' => 'NOPE'], idemKey())->assertStatus(422)->assertJsonPath('error.details.0.field', 'parent');
    });

    it('keeps a partial update from losing the parent', function () {
        asApi($this->user)->postJson('/api/v1/item-categories', ['code' => 'RAW', 'name' => 'Raw'], idemKey());
        asApi($this->user)->postJson('/api/v1/item-categories', ['code' => 'FAB', 'name' => 'Fabric', 'parent' => 'RAW'], idemKey());

        asApi($this->user)->patchJson('/api/v1/item-categories/FAB', ['name' => 'Fabrics'])->assertOk()->assertJsonPath('data.parent', 'RAW');
    });

    it('does not deactivate a category that still has active items or sub-categories', function () {
        asApi($this->user)->postJson('/api/v1/item-categories', ['code' => 'RAW', 'name' => 'Raw'], idemKey());
        asApi($this->user)->postJson('/api/v1/item-categories', ['code' => 'FAB', 'name' => 'Fabric', 'parent' => 'RAW'], idemKey());
        mdItem(['category' => 'FAB']);

        asApi($this->user)->patchJson('/api/v1/item-categories/RAW', ['is_active' => false])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.in_use');
        asApi($this->user)->patchJson('/api/v1/item-categories/FAB', ['is_active' => false])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.in_use');
        expect(ItemCategory::where('is_active', false)->count())->toBe(0);
    });
});

describe('currencies and exchange rates', function () {
    it('adds a currency and manages its rates', function () {
        asApi($this->user)->postJson('/api/v1/currencies', ['code' => 'aud', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimals' => 2], idemKey())->assertCreated()
            ->assertJsonPath('data.code', 'AUD')->assertJsonPath('data.is_base', false)->assertJsonPath('data.latest_rate', null);

        asApi($this->user)->postJson('/api/v1/currencies/AUD/rates', ['effective_on' => '2026-03-01', 'rate' => '195.25'], idemKey())->assertCreated()->assertJsonPath('data.rate', '195.25000000');
        asApi($this->user)->postJson('/api/v1/currencies/AUD/rates', ['effective_on' => '2026-04-01', 'rate' => '198'])->assertCreated();
        asApi($this->user)->getJson('/api/v1/currencies/AUD/rates')->assertOk()->assertJsonPath('data.0.effective_on', '2026-04-01')->assertJsonCount(2, 'data');
        asApi($this->user)->getJson('/api/v1/currencies/AUD')->assertOk()->assertJsonPath('data.latest_rate.rate', '198.00000000');
    });

    it('protects the base currency', function () {
        asApi($this->user)->patchJson('/api/v1/currencies/LKR', ['is_active' => false])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.base_currency_locked');
        asApi($this->user)->patchJson('/api/v1/currencies/LKR', ['decimals' => 0])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.base_currency_locked');
        asApi($this->user)->postJson('/api/v1/currencies/LKR/rates', ['effective_on' => '2026-01-01', 'rate' => '2'])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.base_currency_locked');
        asApi($this->user)->postJson('/api/v1/currencies', ['code' => 'LKR', 'name' => 'Again', 'decimals' => 2], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'masterdata.code_taken');
        asApi($this->user)->patchJson('/api/v1/currencies/USD', ['is_base' => true])->assertOk();    // ignored, not an error
        expect(Currency::where('is_base', true)->pluck('code')->all())->toBe(['LKR']);
    });

    it('validates codes and rates', function () {
        asApi($this->user)->postJson('/api/v1/currencies', ['code' => 'AU', 'name' => 'x', 'decimals' => 2], idemKey())->assertStatus(422);
        asApi($this->user)->postJson('/api/v1/currencies', ['code' => 'A1B', 'name' => 'x', 'decimals' => 2], idemKey())->assertStatus(422);
        asApi($this->user)->postJson('/api/v1/currencies/USD/rates', ['effective_on' => '2026-01-01', 'rate' => '0'])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.rate_invalid');
        asApi($this->user)->postJson('/api/v1/currencies/USD/rates', ['effective_on' => '1999-01-01', 'rate' => '1'])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.date_invalid');
        asApi($this->user)->postJson('/api/v1/currencies/USD/rates', ['effective_on' => now()->addDays(30)->toDateString(), 'rate' => '1'])->assertStatus(422);
        asApi($this->user)->postJson('/api/v1/currencies/XXX/rates', ['effective_on' => '2026-01-01', 'rate' => '1'])->assertNotFound();
    });
});

describe('tax codes and rates', function () {
    it('shows the current rate and the history', function () {
        asApi($this->user)->getJson('/api/v1/tax-codes/VAT')->assertOk()->assertJsonPath('data.current_rate', '18.0000')->assertJsonPath('data.rates.0.effective_from', '2024-01-01');
    });

    it('closes the old rate when a new one starts, and refuses overlaps', function () {
        $id = TaxCode::where('code', 'VAT')->value('public_id');

        asApi($this->user)->postJson("/api/v1/tax-codes/{$id}/rates", ['rate' => '20', 'effective_from' => '2030-01-01'])->assertCreated()->assertJsonPath('data.rate', '20.0000');
        $rates = asApi($this->user)->getJson('/api/v1/tax-codes/VAT')->json('data.rates');
        expect($rates)->toHaveCount(2)->and($rates[1]['effective_to'])->toBe('2029-12-31')->and($rates[0]['effective_to'])->toBeNull();

        asApi($this->user)->postJson("/api/v1/tax-codes/{$id}/rates", ['rate' => '15', 'effective_from' => '2025-06-01', 'effective_to' => '2025-12-31'])
            ->assertStatus(422)->assertJsonPath('error.code', 'masterdata.tax_rate_overlap');
        asApi($this->user)->postJson("/api/v1/tax-codes/{$id}/rates", ['rate' => '101', 'effective_from' => '2031-01-01'])->assertStatus(422);
        asApi($this->user)->postJson("/api/v1/tax-codes/{$id}/rates", ['rate' => '5', 'effective_from' => '2031-01-01', 'effective_to' => '2030-01-01'])->assertStatus(422);
    });

    it('only removes a rate that has not started', function () {
        $code = TaxCode::where('code', 'VAT')->first();
        asApi($this->user)->postJson("/api/v1/tax-codes/{$code->public_id}/rates", ['rate' => '20', 'effective_from' => '2030-01-01']);
        $future = TaxRate::where('tax_code_id', $code->id)->where('effective_from', '2030-01-01')->first();
        $started = TaxRate::where('tax_code_id', $code->id)->where('effective_from', '2024-01-01')->first();

        asApi($this->user)->deleteJson("/api/v1/tax-codes/{$code->public_id}/rates/{$started->id}")->assertStatus(422)->assertJsonPath('error.code', 'masterdata.tax_rate_started');
        asApi($this->user)->deleteJson("/api/v1/tax-codes/{$code->public_id}/rates/{$future->id}")->assertNoContent();
        asApi($this->user)->deleteJson("/api/v1/tax-codes/{$code->public_id}/rates/99999")->assertNotFound();
        expect(TaxRate::where('tax_code_id', $code->id)->count())->toBe(1);
    });

    it('lets an accountant switch NBT on with a rate, and keeps used codes from being deactivated', function () {
        $nbt = TaxCode::where('code', 'NBT')->first();
        asApi($this->user)->patchJson('/api/v1/tax-codes/NBT', ['is_active' => true])->assertOk()->assertJsonPath('data.current_rate', null);
        asApi($this->user)->postJson("/api/v1/tax-codes/{$nbt->public_id}/rates", ['rate' => '2', 'effective_from' => '2024-01-01'])->assertCreated();

        mdItem(['default_tax_code' => 'NBT']);
        asApi($this->user)->patchJson('/api/v1/tax-codes/NBT', ['is_active' => false])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.in_use');
        asApi($this->user)->postJson('/api/v1/tax-codes', ['code' => 'X', 'name' => 'x', 'kind' => 'magic'], idemKey())->assertStatus(422)->assertJsonPath('error.details.0.field', 'kind');
    });
});

describe('payment terms', function () {
    it('creates terms and keeps in-use ones active', function () {
        asApi($this->user)->postJson('/api/v1/payment-terms', ['code' => 'net90', 'name' => 'Net 90', 'days' => 90], idemKey())->assertCreated()->assertJsonPath('data.days', 90);
        asApi($this->user)->postJson('/api/v1/customers', ['name' => 'Slow payer', 'payment_terms' => 'NET90'], idemKey())->assertCreated();

        asApi($this->user)->patchJson('/api/v1/payment-terms/NET90', ['is_active' => false])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.in_use');
        asApi($this->user)->patchJson('/api/v1/payment-terms/NET60', ['is_active' => false])->assertOk();
        asApi($this->user)->postJson('/api/v1/payment-terms', ['code' => 'NEG', 'name' => 'x', 'days' => -1], idemKey())->assertStatus(422);
    });
});

it('audits reference data changes', function () {
    asApi($this->user)->postJson('/api/v1/units-of-measure', ['code' => 'BAG', 'name' => 'Bag', 'decimals' => 0], idemKey());
    asApi($this->user)->patchJson('/api/v1/units-of-measure/BAG', ['name' => 'Sack']);

    $events = AuditLog::where('auditable_type', (new UnitOfMeasure)->getMorphClass())->orderBy('id')->pluck('event')->all();
    expect(array_slice($events, -2))->toBe(['created', 'updated']);
});
