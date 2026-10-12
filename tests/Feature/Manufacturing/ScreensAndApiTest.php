<?php

use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\WorkCenter;
use App\Modules\Manufacturing\Services\SetupService;
use Illuminate\Support\Arr;

const PLANNER_PERMISSIONS = ['manufacturing.view', 'manufacturing.bom.manage', 'manufacturing.order.manage', 'manufacturing.order.release', 'manufacturing.issue.post'];

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->wh = invWarehouse(['code' => 'RAW']);
    $this->shirt = mdItem(['name' => 'Shirt', 'base_uom' => 'PCS']);
    $this->fabric = mdItem(['name' => 'Cotton fabric', 'base_uom' => 'KG']);
    $this->button = mdItem(['name' => 'Button', 'base_uom' => 'PCS']);
    mfgCenter('CUT');
    $this->admin = userWithPermissions(...[...PLANNER_PERMISSIONS, 'manufacturing.setup.manage', 'manufacturing.bom.activate', 'manufacturing.issue.over']);
    $this->planner = userWithPermissions(...PLANNER_PERMISSIONS);
    $this->bomForm = fn (array $o = []) => array_merge([
        'item' => $this->shirt->code, 'base_qty' => '1', 'description' => 'Plain',
        'components' => [['item' => $this->fabric->code, 'qty' => '1.2', 'uom' => '', 'scrap_pct' => '5', 'notes' => ''], ['item' => $this->button->code, 'qty' => '6'], ['item' => '', 'qty' => '']],
        'operations' => [['op_no' => '', 'name' => 'Cut', 'work_center' => 'CUT', 'machine' => '', 'setup_minutes' => '10', 'run_minutes' => '2'], ['name' => '', 'work_center' => '']],
    ], $o);
});

describe('screens', function () {
    it('sends signed-out people to the sign-in page', function () {
        foreach (['/manufacturing/boms', '/manufacturing/boms/create', '/manufacturing/orders', '/manufacturing/orders/create', '/manufacturing/setup', '/manufacturing/setup/machines'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    });

    it('shows nothing to someone without the permission', function () {
        $nobody = userWithPermissions('sales.view');
        foreach (['/manufacturing/boms', '/manufacturing/orders', '/manufacturing/setup', '/manufacturing/setup/work-centers'] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
        $this->actingAs($nobody)->post('/manufacturing/boms', ($this->bomForm)())->assertForbidden();
        $this->actingAs($this->planner)->get('/manufacturing/setup/work-centers/create')->assertForbidden();   // setup needs its own permission
        $this->actingAs($this->planner)->get('/manufacturing/setup/nonsense')->assertNotFound();
    });

    it('drafts, activates and copies a bill of materials from the form, leaving blank spare rows out', function () {
        $this->actingAs($this->planner)->get('/manufacturing/boms/create')->assertOk()->assertSee('Add component');
        $this->actingAs($this->planner)->post('/manufacturing/boms', ($this->bomForm)())->assertSessionHasNoErrors();

        $v = BomVersion::sole();
        expect($v->status)->toBe('draft')->and($v->components)->toHaveCount(2)->and($v->operations)->toHaveCount(1);
        $this->actingAs($this->planner)->get("/manufacturing/boms/{$v->public_id}")->assertOk()->assertSee('Cotton fabric')->assertSee('Edit')->assertDontSee('Activate')->assertSee('Cut');
        expect($this->actingAs($this->planner)->get("/manufacturing/boms/{$v->public_id}/edit")->assertOk()->getContent())->toMatch('/qty.{1,20}1\.2/');   // the rows are handed to the page as data

        // drafting is open to the planner, activating is not
        $this->actingAs($this->planner)->post("/manufacturing/boms/{$v->public_id}/activate")->assertForbidden();
        $this->actingAs($this->admin)->post("/manufacturing/boms/{$v->public_id}/activate")->assertSessionHasNoErrors()->assertSessionHas('status');
        expect($v->fresh()->status)->toBe('active');

        $this->actingAs($this->admin)->put("/manufacturing/boms/{$v->public_id}", ($this->bomForm)())->assertSessionHasErrors();   // an active version cannot be edited
        $this->actingAs($this->admin)->post("/manufacturing/boms/{$v->public_id}/copy")->assertRedirect();
        $copy = BomVersion::where('version_no', 2)->sole();
        $this->actingAs($this->admin)->put("/manufacturing/boms/{$copy->public_id}", ($this->bomForm)(['item' => null, 'components' => [['item' => $this->fabric->code, 'qty' => '2']]]))->assertSessionHasNoErrors();
        expect($copy->fresh()->components)->toHaveCount(1);
        $this->actingAs($this->admin)->get('/manufacturing/boms?q=shirt')->assertOk()->assertSee('Shirt');
        $this->actingAs($this->admin)->get('/manufacturing/boms?status=retired')->assertOk()->assertDontSee('Shirt');
        $this->actingAs($this->admin)->delete("/manufacturing/boms/{$copy->public_id}")->assertRedirect('/manufacturing/boms');
        expect(BomVersion::count())->toBe(1);
    });

    it('shows a form error on the field it belongs to and keeps what was typed', function () {
        $this->actingAs($this->planner)->from('/manufacturing/boms/create')->post('/manufacturing/boms', ($this->bomForm)(['components' => [['item' => $this->fabric->code, 'qty' => '0']]]))
            ->assertRedirect('/manufacturing/boms/create')->assertSessionHasErrors('components.0.qty');
        expect(BomVersion::count())->toBe(0);
    });

    it('runs an order from draft to materials issued and returned', function () {
        mfgBom($this->shirt, [['item' => $this->fabric, 'qty' => '1.2', 'scrap_pct' => '5'], ['item' => $this->button, 'qty' => '6']]);
        invReceive($this->fabric, $this->wh, '100', '400');
        invReceive($this->button, $this->wh, '1000', '2');

        $this->actingAs($this->planner)->get('/manufacturing/orders/create?item='.$this->shirt->code)->assertOk()->assertSee($this->shirt->code);
        $this->actingAs($this->planner)->post('/manufacturing/orders', ['item' => $this->shirt->code, 'qty' => '50', 'source_warehouse' => 'RAW', 'output_warehouse' => 'RAW'])->assertSessionHasNoErrors();
        $order = ProductionOrder::sole();
        $show = "/manufacturing/orders/{$order->public_id}";
        $this->actingAs($this->planner)->get($show)->assertOk()->assertSee($order->order_no)->assertSee('63')->assertSee('Release');
        $this->actingAs($this->planner)->get("{$show}/edit")->assertOk();
        $this->actingAs($this->planner)->put($show, ['qty' => '60', 'source_warehouse' => 'RAW', 'output_warehouse' => 'RAW', 'version' => $order->version])->assertSessionHasNoErrors();

        $this->actingAs($this->planner)->post("{$show}/issue", ['lines' => [['material' => 1, 'qty' => '1']]])->assertSessionHasErrors();   // still a draft
        $this->actingAs($this->planner)->post("{$show}/release")->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->actingAs($this->planner)->get($show)->assertOk()->assertSee('Issue materials')->assertSee('Return materials');

        // blank rows are ignored; one line is issued
        $this->actingAs($this->planner)->post("{$show}/issue", ['date' => now('Asia/Colombo')->toDateString(), 'lines' => [['material' => 1, 'qty' => '30'], ['material' => 2, 'qty' => '']]])->assertSessionHasNoErrors();
        expect((string) $order->materials()->where('line_no', 1)->first()->issued_qty)->toBe('30.000000')->and((string) $order->materials()->where('line_no', 2)->first()->issued_qty)->toBe('0.000000');

        // more than needed: the planner is refused, the one with the extra permission may with a reason
        $this->actingAs($this->planner)->post("{$show}/issue", ['lines' => [['material' => 1, 'qty' => '50']]])->assertSessionHasErrors('lines.0.qty');
        $this->actingAs($this->admin)->post("{$show}/issue", ['lines' => [['material' => 1, 'qty' => '50']]])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post("{$show}/issue", ['reason' => 'recut', 'lines' => [['material' => 1, 'qty' => '50']]])->assertSessionHasNoErrors();

        $this->actingAs($this->planner)->post("{$show}/return", ['lines' => [['material' => 1, 'qty' => '10']]])->assertSessionHasErrors('reason');
        $this->actingAs($this->planner)->post("{$show}/return", ['reason' => 'spoiled', 'lines' => [['material' => 1, 'qty' => '10']]])->assertSessionHasNoErrors();
        $this->actingAs($this->planner)->get($show)->assertOk()->assertSee('spoiled')->assertSee('recut')->assertSee('over requirement');

        $this->actingAs($this->planner)->post("{$show}/cancel", ['reason' => 'stopped'])->assertSessionHasErrors();   // material is still with the order
        $this->actingAs($this->planner)->get('/manufacturing/orders?q='.urlencode($order->order_no))->assertOk()->assertSee($order->order_no);
        $this->actingAs($this->planner)->get('/manufacturing/orders?status=draft')->assertOk()->assertDontSee($order->order_no);
        $this->actingAs($this->planner)->get('/manufacturing/orders?q=shirt&status=in_progress')->assertOk()->assertSee($order->order_no);
    });

    it('manages work centres, lines, machines and shifts', function () {
        foreach (['work-centers', 'lines', 'machines', 'shifts'] as $entity) {
            $this->actingAs($this->admin)->get("/manufacturing/setup/{$entity}")->assertOk();
            $this->actingAs($this->admin)->get("/manufacturing/setup/{$entity}/create")->assertOk();
        }
        $this->actingAs($this->admin)->get('/manufacturing/setup')->assertOk()->assertSee('Work centres');

        $this->actingAs($this->admin)->post('/manufacturing/setup/lines', ['code' => 'l1', 'name' => 'Line 1', 'is_active' => '1'])->assertRedirect('/manufacturing/setup/lines');
        $this->actingAs($this->admin)->post('/manufacturing/setup/machines', ['code' => 'm1', 'name' => 'Cutter', 'work_center' => 'cut', 'production_line' => 'L1', 'status' => 'available'])->assertRedirect('/manufacturing/setup/machines');
        $this->actingAs($this->admin)->post('/manufacturing/setup/shifts', ['code' => 'day', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'day' => ['1', '2', '3', '4', '5'], 'is_active' => '1'])->assertRedirect('/manufacturing/setup/shifts');
        $this->actingAs($this->admin)->post('/manufacturing/setup/shifts', ['code' => 'none', 'name' => 'None', 'start_time' => '08:00', 'end_time' => '17:00'])->assertSessionHasErrors('days');
        $this->actingAs($this->admin)->post('/manufacturing/setup/work-centers', ['code' => 'CUT', 'name' => 'Dup', 'capacity_hours_per_day' => '8', 'hourly_rate' => '1'])->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->get('/manufacturing/setup');   // lets the form's remembered input from the failed saves go

        $machine = Machine::where('code', 'M1')->sole();
        $this->actingAs($this->admin)->get("/manufacturing/setup/machines/{$machine->public_id}/edit")->assertOk()->assertSee('value="M1"', false);
        $this->actingAs($this->admin)->put("/manufacturing/setup/machines/{$machine->public_id}", ['code' => 'M1', 'name' => 'Cutter', 'work_center' => 'CUT', 'status' => 'maintenance'])->assertRedirect();
        expect($machine->fresh()->status)->toBe('maintenance')->and($machine->fresh()->production_line_id)->toBeNull();
        $this->actingAs($this->admin)->get('/manufacturing/setup/shifts')->assertOk()->assertSee('08:00 – 17:00');

        $wc = WorkCenter::where('code', 'CUT')->sole();
        $this->actingAs($this->admin)->put("/manufacturing/setup/work-centers/{$wc->public_id}", ['code' => 'CUT', 'name' => 'Cutting', 'capacity_hours_per_day' => '8', 'hourly_rate' => '650'])->assertRedirect();
        expect($wc->fresh()->is_active)->toBeFalse();   // an unticked box switches it off
    });

    it('appears in the sidebar under Manufacturing for those who may see it', function () {
        $nav = function ($user) {
            preg_match('/<nav [^>]*aria-label="Main".*?<\/nav>/s', $this->actingAs($user)->get('/')->getContent(), $m);

            return $m[0] ?? '';
        };
        expect($nav($this->planner))->toContain('>Manufacturing<')->toContain('Production orders')->toContain('Bills of materials');
        expect($nav(userWithPermissions('sales.view')))->not->toContain('>Manufacturing<')->not->toContain('Production orders');
    });
});

describe('API', function () {
    it('runs a bill and an order over HTTP with codes and line numbers', function () {
        $bom = asApi($this->admin)->postJson('/api/v1/boms', [
            'item' => $this->shirt->code, 'base_qty' => '1', 'components' => [['item' => $this->fabric->code, 'qty' => '1.2', 'scrap_pct' => '5'], ['item' => $this->button->code, 'qty' => '6']],
            'operations' => [['name' => 'Cut', 'work_center' => 'CUT', 'setup_minutes' => '10', 'run_minutes' => '2']],
        ], idemKey())->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version_no', 1)->assertJsonPath('data.components.0.item', $this->fabric->code)
            ->assertJsonPath('data.operations.0.work_center', 'CUT')->json('data.id');

        asApi($this->planner)->postJson("/api/v1/boms/{$bom}/activate", [], idemKey())->assertForbidden();
        asApi($this->admin)->postJson("/api/v1/boms/{$bom}/activate", [], idemKey())->assertOk()->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.tree.0.qty', '1.260000')->assertJsonPath('data.tree.1.qty', '6.000000');
        asApi($this->planner)->getJson('/api/v1/boms?filter[status]=active&filter[item]='.$this->shirt->code)->assertOk()->assertJsonCount(1, 'data');
        asApi($this->planner)->getJson("/api/v1/boms/{$bom}")->assertOk()->assertJsonPath('data.item', $this->shirt->code);

        invReceive($this->fabric, $this->wh, '100', '400');
        invReceive($this->button, $this->wh, '1000', '2');
        $id = asApi($this->planner)->postJson('/api/v1/production-orders', ['item' => $this->shirt->code, 'qty' => '50', 'source_warehouse' => 'RAW', 'output_warehouse' => 'RAW'], idemKey())->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.materials.0.required_qty', '63')->assertJsonPath('data.operations.0.run_minutes', '100')->json('data.id');
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/release", [], idemKey())->assertOk()->assertJsonPath('data.status', 'released')->assertJsonPath('meta.shortages', []);
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/issues", ['lines' => [['item' => $this->fabric->code, 'qty' => '40'], ['material' => 2, 'qty' => '300']]], idemKey())->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.materials.0.issued_qty', '40')->assertJsonPath('data.wip_value', '16600.00');
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/returns", ['reason' => 'spare', 'lines' => [['material' => 1, 'qty' => '5']]], idemKey())->assertCreated()
            ->assertJsonPath('data.materials.0.net_qty', '35')->assertJsonPath('data.wip_value', '14600.00');
        asApi($this->planner)->getJson('/api/v1/production-orders?filter[status]=in_progress')->assertOk()->assertJsonCount(1, 'data');
        asApi($this->planner)->getJson("/api/v1/production-orders/{$id}")->assertOk()->assertJsonPath('data.bom', 'v1');
    });

    it('answers with the rule that was broken', function () {
        asApi($this->planner)->postJson('/api/v1/boms', ['item' => $this->shirt->code, 'components' => [['item' => $this->shirt->code, 'qty' => '1']]], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'manufacturing.component_self');
        asApi($this->planner)->postJson('/api/v1/boms', ['components' => [['item' => $this->fabric->code, 'qty' => '1']]], idemKey())->assertStatus(422);
        asApi($this->planner)->postJson('/api/v1/boms', ['item' => $this->shirt->code, 'components' => [['item' => $this->fabric->code, 'qty' => 'abc']]], idemKey())->assertStatus(422);
        asApi($this->planner)->postJson('/api/v1/production-orders', ['item' => $this->shirt->code, 'qty' => '5', 'source_warehouse' => 'RAW', 'output_warehouse' => 'RAW'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'manufacturing.no_active_bom');
        asApi($this->planner)->postJson('/api/v1/production-orders', ['item' => $this->shirt->code, 'qty' => '5', 'source_warehouse' => 'NOPE', 'output_warehouse' => 'RAW'], idemKey())->assertStatus(422);

        mfgBom($this->shirt, [['item' => $this->fabric, 'qty' => '1']]);
        $order = mfgOrder($this->shirt, '5', $this->wh);
        asApi($this->planner)->putJson("/api/v1/production-orders/{$order->public_id}", ['qty' => '6', 'source_warehouse' => 'RAW', 'output_warehouse' => 'RAW', 'version' => 99], idemKey())->assertStatus(409)->assertJsonPath('error.code', 'concurrency.stale_version');
        asApi($this->planner)->postJson("/api/v1/production-orders/{$order->public_id}/issues", ['lines' => [['material' => 1, 'qty' => '1']]], idemKey())->assertStatus(409)->assertJsonPath('error.code', 'manufacturing.invalid_state');
        asApi(userWithPermissions('sales.view'))->getJson('/api/v1/production-orders')->assertForbidden();
    });

    it('refuses a call without a token', function () {
        $this->getJson('/api/v1/boms')->assertUnauthorized();
        $this->postJson('/api/v1/production-orders', [])->assertUnauthorized();
    });

    it('manages the setup lists', function () {
        asApi($this->admin)->postJson('/api/v1/manufacturing/lines', ['code' => 'l9', 'name' => 'Line 9'])->assertCreated()->assertJsonPath('data.code', 'L9')->assertJsonPath('data.is_active', true);
        $id = asApi($this->admin)->postJson('/api/v1/manufacturing/machines', ['code' => 'm9', 'name' => 'Press', 'work_center' => 'CUT', 'production_line' => 'L9', 'status' => 'available'])->assertCreated()
            ->assertJsonPath('data.work_center', 'CUT')->assertJsonPath('data.production_line', 'L9')->json('data.id');
        asApi($this->admin)->putJson("/api/v1/manufacturing/machines/{$id}", ['code' => 'M9', 'name' => 'Press', 'work_center' => 'CUT', 'status' => 'retired'])->assertOk()->assertJsonPath('data.status', 'retired');
        asApi($this->admin)->postJson('/api/v1/manufacturing/shifts', ['code' => 'N', 'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'days' => '1111100'])->assertCreated();
        asApi($this->planner)->getJson('/api/v1/manufacturing/work-centers')->assertOk()->assertJsonPath('data.0.code', 'CUT');
        asApi($this->planner)->postJson('/api/v1/manufacturing/lines', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        asApi($this->admin)->postJson('/api/v1/manufacturing/lines', ['code' => 'L9', 'name' => 'Dup'])->assertStatus(422);
        asApi($this->admin)->getJson('/api/v1/manufacturing/nonsense')->assertNotFound();
    });
});

describe('languages', function () {
    it('has every text in English, Sinhala and Tamil', function () {
        $flat = function (array $a, string $p = '') use (&$flat) {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? $out += $flat($v, "{$p}{$k}.") : $out["{$p}{$k}"] = $v;
            }

            return $out;
        };
        $en = $flat(require app_path('Modules/Manufacturing/Lang/en/ui.php'));
        foreach (['si', 'ta'] as $lang) {
            $other = $flat(require app_path("Modules/Manufacturing/Lang/{$lang}/ui.php"));
            expect(array_keys($other))->toEqualCanonicalizing(array_keys($en));
            expect(collect($other)->filter(fn ($v) => trim((string) $v) === ''))->toBeEmpty();
            // every :placeholder in English is in the translation too
            foreach ($en as $key => $text) {
                preg_match_all('/:[a-z_]+/', $text, $m);
                foreach ($m[0] as $placeholder) {
                    expect($other[$key])->toContain($placeholder);
                }
            }
        }
    });

    it('uses a key for every text the screens ask for', function () {
        $files = array_merge(glob(app_path('Modules/Manufacturing/Views/*/*.blade.php')), glob(app_path('Modules/Manufacturing/Http/Controllers/*/*.php')), glob(app_path('Modules/Manufacturing/Services/*.php')));
        $keys = [];
        foreach ($files as $f) {
            preg_match_all('/manufacturing::ui\.([A-Za-z0-9_.\-]+)/', file_get_contents($f), $m);
            $keys = array_merge($keys, $m[1]);
        }
        $en = require app_path('Modules/Manufacturing/Lang/en/ui.php');
        foreach (array_unique($keys) as $key) {
            if (str_ends_with($key, '.')) {
                continue;   // a prefix completed at run time ("status." + the status): checked by the screens tests
            }
            expect(Arr::has($en, $key))->toBeTrue("missing text: {$key}");
        }
        foreach (array_merge(ProductionOrder::STATUSES, ['active', 'inactive', 'retired', 'available', 'maintenance', 'pending', 'done']) as $s) {
            expect(Arr::has($en, "status.{$s}"))->toBeTrue("missing status: {$s}");
        }
    });
});

it('hands the setup service the same rules as the screens', function () {
    expect(array_keys(SetupService::ENTITIES))->toBe(['work-centers', 'lines', 'machines', 'shifts']);
});
