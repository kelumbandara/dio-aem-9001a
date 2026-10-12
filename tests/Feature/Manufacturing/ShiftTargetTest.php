<?php

use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Manufacturing\Models\Shift;
use App\Modules\Manufacturing\Models\ShiftTarget;
use App\Modules\Manufacturing\Services\OutputService;
use App\Modules\Manufacturing\Services\SetupService;
use App\Modules\Manufacturing\Services\ShiftReport;
use App\Modules\Manufacturing\Services\ShiftTargetService;
use App\Modules\MasterData\Models\Item;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00', 'Asia/Colombo'));             // a Wednesday
    $this->wh = invWarehouse(['code' => 'RAW']);
    $this->shirt = mdItem(['name' => 'Shirt', 'base_uom' => 'PCS']);
    $this->trouser = mdItem(['name' => 'Trouser', 'base_uom' => 'PCS']);
    $this->fabric = mdItem(['name' => 'Cotton fabric', 'base_uom' => 'KG']);
    $this->service = mdItem(['name' => 'Tailoring', 'item_type' => 'service', 'base_uom' => 'HR', 'is_purchasable' => false]);
    mfgBom($this->shirt, [['item' => $this->fabric, 'qty' => '2']]);
    mfgBom($this->trouser, [['item' => $this->fabric, 'qty' => '3']]);
    $setup = app(SetupService::class);
    $setup->save('shifts', ['code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'days' => '1111100']);        // Monday to Friday
    $setup->save('shifts', ['code' => 'NIGHT', 'name' => 'Night', 'start_time' => '20:00', 'end_time' => '05:00', 'days' => '1111111']);
    $this->targets = app(ShiftTargetService::class);
    $this->report = app(ShiftReport::class);
    $this->output = app(OutputService::class);
    $this->user = userWithPermissions('manufacturing.output.report', 'manufacturing.order.release');
    $this->make = function (Item $item, string $made, string $rej, string $rew, string $date, string $shift = 'DAY', string $qty = '100') {
        static $orders = [];
        $order = $orders[$item->id] ??= mfgReleased($item, '1000', $this->wh);

        return $this->output->report($order->fresh(), ['shift' => $shift, 'date' => $date, 'produced' => $made, 'rejected' => $rej, 'rework' => $rew], $this->user->id);
    };
    $this->rows = fn (string $from = '2026-11-02', string $to = '2026-11-04') => collect($this->report->summary($from, $to))->keyBy(fn ($r) => "{$r['date']} {$r['shift']}");
});

describe('setting a target', function () {
    it('creates it, changes its quantity and switches it off, keeping one per shift and product', function () {
        $a = $this->targets->save(['shift' => 'day', 'item' => $this->shirt->code, 'target' => '40']);
        expect((string) $a->target_qty)->toBe('40.000000')->and($a->is_active)->toBeTrue();

        $b = $this->targets->save(['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '55', 'is_active' => false]);
        expect($b->id)->toBe($a->id)->and((string) $b->target_qty)->toBe('55.000000')->and($b->is_active)->toBeFalse()->and(ShiftTarget::count())->toBe(1);
        $this->targets->save(['shift' => 'NIGHT', 'item' => $this->shirt->code, 'target' => '30']);
        $this->targets->save(['shift' => 'DAY', 'item' => $this->trouser->code, 'target' => '20']);
        expect(ShiftTarget::count())->toBe(3);
    });

    it('refuses what makes no sense', function (array $data, string $code) {
        mfgFails(fn () => $this->targets->save($data + ['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '10']), $code);
        expect(ShiftTarget::count())->toBe(0);
    })->with([
        'an unknown shift' => [['shift' => 'NOPE'], 'manufacturing.shift_required'],
        'no shift' => [['shift' => ''], 'manufacturing.shift_required'],
        'an unknown product' => [['item' => 'NOPE'], 'manufacturing.item_not_found'],
        'a service' => [['item' => 'SERVICE'], 'manufacturing.item_not_found'],
        'zero' => [['target' => '0'], 'manufacturing.qty_invalid'],
        'half a piece' => [['target' => '2.5'], 'manufacturing.qty_invalid'],
        'text' => [['target' => 'lots'], 'manufacturing.qty_invalid'],
    ]);

    it('is kept sound by the database', function () {
        $this->targets->save(['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '40']);
        expect(fn () => inSavepoint(fn () => DB::table('shift_targets')->update(['target_qty' => 0])))->toThrow(Exception::class);
        expect(fn () => inSavepoint(fn () => DB::table('shift_targets')->insert(['public_id' => (string) Str::uuid(), 'shift_id' => Shift::where('code', 'DAY')->value('id'), 'item_id' => $this->shirt->id, 'target_qty' => 5, 'created_at' => now(), 'updated_at' => now()])))->toThrow(Exception::class);
    });
});

describe('the shift report against the targets', function () {
    it('sets the good units of each product against the shift\'s target', function () {
        $this->targets->save(['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '40']);
        ($this->make)($this->shirt, '30', '2', '3', '2026-11-04');                         // 25 good
        ($this->make)($this->shirt, '10', '0', '0', '2026-11-04');                          // 35 good in all
        ($this->make)($this->trouser, '12', '1', '0', '2026-11-04');                        // no target: made, but nothing to measure against

        $row = ($this->rows)()['2026-11-04 DAY'];
        $byItem = collect($row['items'])->keyBy('item');
        expect($row['produced'])->toBe('52')->and($row['good'])->toBe('46');
        expect($byItem[$this->shirt->code])->toMatchArray(['produced' => '40', 'good' => '35', 'rejected' => '2', 'target' => '40', 'achievement' => '87.5'])
            ->and($byItem[$this->trouser->code])->toMatchArray(['good' => '11', 'target' => null, 'achievement' => null]);
    });

    it('goes above 100 % when the shift beats its target, and counts only good units', function () {
        $this->targets->save(['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '20']);
        ($this->make)($this->shirt, '30', '5', '0', '2026-11-04');                          // 25 good of 20
        expect(collect(($this->rows)()['2026-11-04 DAY']['items'])->first()['achievement'])->toBe('125.0');
    });

    it('shows a shift that was due to work and reported nothing as 0 %, but not a day it does not work', function () {
        $this->targets->save(['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '40']);
        ($this->make)($this->shirt, '40', '0', '0', '2026-11-02');                          // Monday: made the target
        // Tuesday and Wednesday: nothing reported. 2026-11-07 is a Saturday: the day shift does not work then.
        $rows = ($this->rows)('2026-11-02', '2026-11-04');
        expect($rows->keys()->all())->toBe(['2026-11-04 DAY', '2026-11-03 DAY', '2026-11-02 DAY'])
            ->and($rows['2026-11-03 DAY']['items'][0])->toMatchArray(['good' => '0', 'target' => '40', 'achievement' => '0.0'])
            ->and($rows['2026-11-03 DAY']['reports'])->toBe(0)->and($rows['2026-11-02 DAY']['items'][0]['achievement'])->toBe('100.0');

        $this->travelTo(CarbonImmutable::parse('2026-11-09 15:00', 'Asia/Colombo'));
        $weekend = collect($this->report->summary('2026-11-06', '2026-11-08'))->pluck('date')->all();
        expect($weekend)->toBe(['2026-11-06']);                                              // Friday only; Saturday and Sunday are off for the day shift
    });

    it('does not invent rows for days that have not come, or for targets that are switched off', function () {
        $this->targets->save(['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '40']);
        $this->targets->save(['shift' => 'NIGHT', 'item' => $this->shirt->code, 'target' => '40', 'is_active' => false]);
        $rows = ($this->rows)('2026-11-03', '2026-11-12');
        expect($rows->keys()->all())->toBe(['2026-11-04 DAY', '2026-11-03 DAY']);             // today is Wednesday the 4th: nothing after it, and the switched-off night target adds nothing
    });

    it('leaves cancelled reports out of the achievement', function () {
        $this->targets->save(['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '40']);
        ($this->make)($this->shirt, '30', '0', '0', '2026-11-04');
        $wrong = ($this->make)($this->shirt, '9', '0', '0', '2026-11-04');
        $this->output->void($wrong, 'typed twice', $this->user->id);
        expect(($this->rows)()['2026-11-04 DAY']['items'][0])->toMatchArray(['good' => '30', 'achievement' => '75.0']);
    });

    it('keeps each shift\'s target separate', function () {
        $this->targets->save(['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '40']);
        $this->targets->save(['shift' => 'NIGHT', 'item' => $this->shirt->code, 'target' => '10']);
        ($this->make)($this->shirt, '20', '0', '0', '2026-11-04', 'NIGHT');
        $rows = ($this->rows)();
        expect($rows['2026-11-04 NIGHT']['items'][0])->toMatchArray(['good' => '20', 'target' => '10', 'achievement' => '200.0'])
            ->and($rows['2026-11-04 DAY']['items'][0])->toMatchArray(['good' => '0', 'target' => '40', 'achievement' => '0.0']);
    });
});

describe('screens and API', function () {
    beforeEach(function () {
        $this->admin = userWithPermissions('manufacturing.view', 'manufacturing.setup.manage');
        $this->viewer = userWithPermissions('manufacturing.view');
    });

    it('lets those who manage setup set targets, and everybody with access read them', function () {
        $this->actingAs($this->admin)->post('/manufacturing/targets', ['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '40'])->assertRedirect('/manufacturing/targets')->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/manufacturing/targets', ['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '45', 'is_active' => '1'])->assertSessionHasNoErrors();
        expect(ShiftTarget::count())->toBe(1)->and((string) ShiftTarget::first()->target_qty)->toBe('45.000000');
        $this->actingAs($this->admin)->post('/manufacturing/targets', ['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '45', 'is_active' => '0'])->assertSessionHasNoErrors();   // the row form sends 0 when its box is not ticked
        expect(ShiftTarget::first()->is_active)->toBeFalse();

        $this->actingAs($this->admin)->from('/manufacturing/targets')->post('/manufacturing/targets', ['shift' => 'DAY', 'item' => 'NOPE', 'target' => '5'])->assertSessionHasErrors('item');
        $this->actingAs($this->viewer)->get('/manufacturing/targets')->assertOk()->assertSee('Shift targets')->assertSee($this->shirt->code)->assertDontSee('Save target');
        $this->actingAs($this->viewer)->post('/manufacturing/targets', ['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '5'])->assertForbidden();
        $this->actingAs($this->admin)->get('/manufacturing/targets')->assertOk()->assertSee('Save target');
        $this->actingAs($this->admin)->get('/manufacturing/setup')->assertOk()->assertSee('Shift targets');
        $this->actingAs(userWithPermissions('sales.view'))->get('/manufacturing/targets')->assertForbidden();
    });

    it('shows the achievement on the shift report', function () {
        $this->targets->save(['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '40']);
        ($this->make)($this->shirt, '30', '0', '0', '2026-11-04');
        $this->actingAs($this->viewer)->get('/manufacturing/shift-report?from=2026-11-02&to=2026-11-04')->assertOk()->assertSee('75.0 %')->assertSee('Target')->assertSee('2026-11-03');
        $this->actingAs($this->viewer)->get('/manufacturing/shift-report?from=2026-01-01&to=2026-11-04')->assertRedirect('/manufacturing/shift-report')->assertSessionHasErrors('from');
    });

    it('reads and sets targets over HTTP, and the shift report carries them', function () {
        asApi($this->admin)->putJson('/api/v1/shift-targets', ['shift' => 'day', 'item' => $this->shirt->code, 'target' => '40'])->assertOk()->assertJsonPath('data.shift', 'DAY')->assertJsonPath('data.target', '40')->assertJsonPath('data.is_active', true);
        asApi($this->admin)->putJson('/api/v1/shift-targets', ['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => 'x'])->assertStatus(422);
        asApi($this->admin)->putJson('/api/v1/shift-targets', ['shift' => 'NOPE', 'item' => $this->shirt->code, 'target' => '5'])->assertStatus(422)->assertJsonPath('error.code', 'manufacturing.shift_required');
        asApi($this->viewer)->putJson('/api/v1/shift-targets', ['shift' => 'DAY', 'item' => $this->shirt->code, 'target' => '5'])->assertForbidden();
        asApi($this->viewer)->getJson('/api/v1/shift-targets')->assertOk()->assertJsonPath('data.0.item', $this->shirt->code)->assertJsonPath('data.0.target', '40');

        ($this->make)($this->shirt, '30', '0', '0', '2026-11-04');
        asApi($this->viewer)->getJson('/api/v1/shift-report?from=2026-11-04&to=2026-11-04')->assertOk()->assertJsonPath('data.0.items.0.achievement', '75.0')->assertJsonPath('data.0.items.0.target', '40');
        asApi($this->viewer)->getJson('/api/v1/shift-report?from=2026-01-01&to=2026-11-04')->assertStatus(422)->assertJsonPath('error.code', 'manufacturing.range_invalid');
        asApi($this->viewer)->getJson('/api/v1/shift-report?from=2026-11-05&to=2026-11-04')->assertStatus(422);
    });
});
