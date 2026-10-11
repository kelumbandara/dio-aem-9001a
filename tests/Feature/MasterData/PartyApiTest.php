<?php

use App\Modules\Core\Models\AuditLog;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Supplier;

/** Customers and suppliers share their behaviour; the datasets run every check against both. */
$parties = [
    'customer' => ['customers', Customer::class, 'CUS-', 'masterdata.customer'],
    'supplier' => ['suppliers', Supplier::class, 'SUP-', 'masterdata.supplier'],
];

dataset('parties', $parties);

describe('permissions', function () {
    it('separates reading from writing and keeps others out', function (string $path, string $model, string $prefix, string $perm) {
        $record = $model::query()->create(['code' => 'X1', 'name' => 'Existing']);
        $viewer = userWithPermissions("{$perm}.view");

        asApi(userWithPermissions('masterdata.item.view'))->getJson("/api/v1/{$path}")->assertForbidden();
        asApi($viewer)->getJson("/api/v1/{$path}")->assertOk();
        asApi($viewer)->getJson("/api/v1/{$path}/{$record->public_id}")->assertOk();
        asApi($viewer)->postJson("/api/v1/{$path}", ['name' => 'New'], idemKey())->assertForbidden();
        asApi($viewer)->patchJson("/api/v1/{$path}/{$record->public_id}", ['version' => 1, 'name' => 'New'])->assertForbidden();
    })->with('parties');
});

describe('creating and updating', function () {
    beforeEach(fn () => $this->user = masterDataUser());

    it('creates a record with a generated code, defaults and a full profile', function (string $path, string $model, string $prefix) {
        asApi($this->user)->postJson("/api/v1/{$path}", [
            'name' => 'Lanka Traders', 'legal_name' => 'Lanka Traders (Pvt) Ltd', 'tax_id' => '134567890-7000', 'business_reg_no' => 'PV 12345',
            'email' => 'accounts@lanka.example', 'phone' => '+94 11 234 5678', 'currency' => 'LKR', 'payment_terms' => 'NET30',
            'contacts' => [['name' => 'Nimal Perera', 'job_title' => 'Accountant', 'email' => 'nimal@lanka.example']],
            'addresses' => [['line1' => '12 Galle Road', 'city' => 'Colombo', 'postal_code' => '00300']],
        ], idemKey())->assertCreated()
            ->assertJsonPath('data.code', $prefix.'00001')->assertJsonPath('data.kind', 'company')->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.payment_terms', 'NET30')->assertJsonPath('data.currency', 'LKR')
            ->assertJsonPath('data.contacts.0.is_primary', true)->assertJsonPath('data.addresses.0.is_default', true)
            ->assertJsonPath('data.addresses.0.country', 'LK')->assertJsonPath('data.addresses.0.type', 'billing')
            ->assertHeader('Location');
    })->with('parties');

    it('normalises contacts and addresses: one primary, one default, blanks dropped', function (string $path) {
        $r = asApi($this->user)->postJson("/api/v1/{$path}", [
            'name' => 'Multi',
            'contacts' => [['name' => 'A', 'is_primary' => true], ['name' => 'B', 'is_primary' => true], ['name' => '  '], ['name' => 'C']],
            'addresses' => [['line1' => 'One'], ['line1' => 'Two', 'is_default' => true, 'type' => 'shipping'], ['line1' => '']],
        ], idemKey())->assertCreated();

        expect(collect($r->json('data.contacts'))->pluck('name')->all())->toBe(['A', 'B', 'C'])
            ->and(collect($r->json('data.contacts'))->where('is_primary', true))->toHaveCount(1)
            ->and(collect($r->json('data.addresses'))->where('is_default', true)->pluck('line1')->all())->toBe(['Two'])
            ->and($r->json('data.addresses'))->toHaveCount(2);
    })->with(['customers', 'suppliers']);

    it('updates child rows by id, adds new ones and removes the ones left out', function (string $path) {
        $created = asApi($this->user)->postJson("/api/v1/{$path}", [
            'name' => 'Kids', 'contacts' => [['name' => 'Keep'], ['name' => 'Drop']], 'addresses' => [['line1' => 'Old street']],
        ], idemKey())->json('data');
        [$keep, $drop] = $created['contacts'];

        $updated = asApi($this->user)->patchJson("/api/v1/{$path}/{$created['id']}", [
            'version' => 1,
            'contacts' => [['id' => $keep['id'], 'name' => 'Keep (renamed)'], ['name' => 'Brand new']],
            'addresses' => [['id' => $created['addresses'][0]['id'], 'line1' => 'New street']],
        ])->assertOk()->json('data');

        expect(collect($updated['contacts'])->pluck('name')->all())->toBe(['Keep (renamed)', 'Brand new'])
            ->and($updated['contacts'][0]['id'])->toBe($keep['id'])                       // same row, not re-created
            ->and(collect($updated['contacts'])->pluck('id'))->not->toContain($drop['id'])
            ->and($updated['addresses'][0]['id'])->toBe($created['addresses'][0]['id'])->and($updated['addresses'][0]['line1'])->toBe('New street');
        expect(AuditLog::where('event', 'like', '%.contacts_changed')->count())->toBe(2);
    })->with(['customers', 'suppliers']);

    it('does not touch child rows when they are not sent, and ignores ids of other parties', function (string $path, string $model) {
        $a = asApi($this->user)->postJson("/api/v1/{$path}", ['name' => 'A', 'contacts' => [['name' => 'Mine']]], idemKey())->json('data');
        $b = asApi($this->user)->postJson("/api/v1/{$path}", ['name' => 'B', 'contacts' => [['name' => 'Theirs']]], idemKey())->json('data');

        asApi($this->user)->patchJson("/api/v1/{$path}/{$a['id']}", ['version' => 1, 'name' => 'A2'])->assertOk()->assertJsonCount(1, 'data.contacts');

        // an id that belongs to B must not let A steal or rewrite B's contact
        asApi($this->user)->patchJson("/api/v1/{$path}/{$a['id']}", ['version' => 2, 'contacts' => [['id' => $b['contacts'][0]['id'], 'name' => 'Hijacked']]])->assertOk();
        asApi($this->user)->getJson("/api/v1/{$path}/{$b['id']}")->assertJsonPath('data.contacts.0.name', 'Theirs');
    })->with('parties');

    it('detects a stale version and keeps the code fixed', function (string $path) {
        $id = asApi($this->user)->postJson("/api/v1/{$path}", ['name' => 'V'], idemKey())->json('data.id');
        asApi($this->user)->patchJson("/api/v1/{$path}/{$id}", ['version' => 1, 'name' => 'V2'])->assertOk()->assertJsonPath('data.version', 2);

        asApi($this->user)->patchJson("/api/v1/{$path}/{$id}", ['version' => 1, 'name' => 'V3'])->assertStatus(409)->assertJsonPath('error.code', 'concurrency.stale_version');
        asApi($this->user)->patchJson("/api/v1/{$path}/{$id}", ['version' => 2, 'code' => 'HACKED'])->assertStatus(422)->assertJsonPath('error.code', 'masterdata.code_locked');
    })->with(['customers', 'suppliers']);

    it('rejects bad references and malformed values', function (string $path, string $model) {
        $post = fn (array $o) => asApi($this->user)->postJson("/api/v1/{$path}", ['name' => 'X', ...$o], idemKey());

        $post(['currency' => 'ZZZ'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'currency');
        $post(['payment_terms' => 'NOPE'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'payment_terms');
        $post(['email' => 'not-an-email'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'email');
        $post(['kind' => 'robot'])->assertStatus(422);
        $post(['contacts' => [['name' => 'Bad', 'email' => 'nope']]])->assertStatus(422);
        $post(['addresses' => [['line1' => 'x', 'country' => 'LKA']]])->assertStatus(422);
        $post(['addresses' => [['line1' => 'x', 'type' => 'moon']]])->assertStatus(422);
        expect($model::count())->toBe(0);
    })->with('parties');

    it('rejects a price list of the wrong kind', function () {
        $purchase = mdPriceList(['type' => 'purchase']);
        $sales = mdPriceList(['type' => 'sales']);

        asApi($this->user)->postJson('/api/v1/customers', ['name' => 'C', 'price_list' => $purchase->code], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'masterdata.reference_invalid');
        asApi($this->user)->postJson('/api/v1/suppliers', ['name' => 'S', 'price_list' => $sales->code], idemKey())->assertStatus(422);
        asApi($this->user)->postJson('/api/v1/customers', ['name' => 'C', 'price_list' => $sales->code], idemKey())->assertCreated()->assertJsonPath('data.price_list', $sales->code);
        asApi($this->user)->postJson('/api/v1/suppliers', ['name' => 'S', 'price_list' => $purchase->code], idemKey())->assertCreated();
    });

    it('limits each type to its own fields', function () {
        asApi($this->user)->postJson('/api/v1/customers', ['name' => 'C', 'credit_limit' => '250000.50'], idemKey())->assertCreated()->assertJsonPath('data.credit_limit', '250000.5000');
        asApi($this->user)->postJson('/api/v1/customers', ['name' => 'C', 'credit_limit' => '-1'], idemKey())->assertStatus(422);
        asApi($this->user)->postJson('/api/v1/customers', ['name' => 'C', 'credit_limit' => '1e5'], idemKey())->assertStatus(422);
        asApi($this->user)->postJson('/api/v1/suppliers', ['name' => 'S', 'lead_time_days' => 14], idemKey())->assertCreated()->assertJsonPath('data.lead_time_days', 14);
        asApi($this->user)->postJson('/api/v1/suppliers', ['name' => 'S', 'lead_time_days' => 99999], idemKey())->assertStatus(422);
    });

    it('can be deactivated but never deleted', function (string $path) {
        $id = asApi($this->user)->postJson("/api/v1/{$path}", ['name' => 'Bye'], idemKey())->json('data.id');

        asApi($this->user)->patchJson("/api/v1/{$path}/{$id}", ['version' => 1, 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        asApi($this->user)->deleteJson("/api/v1/{$path}/{$id}")->assertStatus(405);
    })->with(['customers', 'suppliers']);
});

describe('personal data', function () {
    it('stores NIC encrypted, only for individuals, and shows it only to people who may edit', function (string $path, string $model, string $prefix, string $perm) {
        $manager = masterDataUser();
        $id = asApi($manager)->postJson("/api/v1/{$path}", ['name' => 'Sunil', 'kind' => 'individual', 'nic' => '901234567V'], idemKey())->assertCreated()
            ->assertJsonPath('data.nic', '901234567V')->json('data.id');
        $company = asApi($manager)->postJson("/api/v1/{$path}", ['name' => 'Co', 'kind' => 'company', 'nic' => '901234567V'], idemKey())->assertCreated()
            ->assertJsonPath('data.nic', null)->json('data.id');

        $raw = DB::table($path)->where('public_id', $id)->value('nic');
        expect($raw)->not->toContain('901234567V')->and($company)->not->toBe($id);

        asApi(userWithPermissions("{$perm}.view"))->getJson("/api/v1/{$path}/{$id}")->assertOk()->assertJsonPath('data.nic', null);

        $entry = AuditLog::where('auditable_type', $model::query()->where('public_id', $id)->first()->getMorphClass())->where('event', 'created')->first();
        expect(json_encode($entry->new_values))->not->toContain('901234567V');
    })->with('parties');
});

describe('listing', function () {
    it('searches name, code, tax id and filters by status', function (string $path, string $model) {
        $user = masterDataUser();
        foreach ([['Alpha Stores', 'T-1'], ['Beta Mills', 'T-2'], ['Gamma Foods', null]] as [$name, $tax]) {
            asApi($user)->postJson("/api/v1/{$path}", ['name' => $name, 'tax_id' => $tax], idemKey())->assertCreated();
        }
        $model::where('name', 'Gamma Foods')->update(['is_active' => false]);
        $names = fn (string $qs) => collect(asApi($user)->getJson("/api/v1/{$path}?{$qs}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        expect($names('filter[q]=alpha'))->toBe(['Alpha Stores'])
            ->and($names('filter[q]=T-2'))->toBe(['Beta Mills'])
            ->and($names('filter[is_active]=false'))->toBe(['Gamma Foods'])
            ->and($names('sort=-name&per_page=2'))->toBe(['Beta Mills', 'Gamma Foods']);
        asApi($user)->getJson("/api/v1/{$path}?filter[q]=%25")->assertOk()->assertJsonPath('meta.total', 0);
        asApi($user)->getJson("/api/v1/{$path}?per_page=101")->assertStatus(422);
    })->with('parties');
});
