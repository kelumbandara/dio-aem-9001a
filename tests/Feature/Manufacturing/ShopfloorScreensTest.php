<?php

use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Manufacturing\Models\DowntimeEvent;
use App\Modules\Manufacturing\Models\ProductionReport;
use App\Modules\Manufacturing\Services\MaterialService;
use App\Modules\Manufacturing\Services\SetupService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Asia/Colombo'));
    $this->wh = invWarehouse(['code' => 'RAW']);
    $this->fg = invWarehouse(['code' => 'FG']);
    $this->shirt = mdItem(['name' => 'Shirt', 'base_uom' => 'PCS']);
    $this->fabric = mdItem(['name' => 'Cotton fabric', 'base_uom' => 'KG']);
    mfgCenter('CUT');
    $setup = app(SetupService::class);
    $setup->save('machines', ['code' => 'M1', 'name' => 'Cutter', 'work_center' => 'CUT', 'status' => 'available']);
    $setup->save('shifts', ['code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'days' => '1111111']);
    $setup->save('downtime-reasons', ['code' => 'BRK', 'name' => 'Breakdown', 'category' => 'breakdown']);
    mfgBom($this->shirt, [['item' => $this->fabric, 'qty' => '2']]);
    invReceive($this->fabric, $this->wh, '1000', '400');
    $this->operator = userWithRole('operator');
    $this->planner = userWithRole('production');
    $this->admin = userWithPermissions('manufacturing.view', 'manufacturing.output.report', 'manufacturing.output.void', 'manufacturing.downtime.report', 'manufacturing.qc.inspect', 'manufacturing.receipt.post', 'manufacturing.order.complete', 'manufacturing.setup.manage', 'manufacturing.issue.post', 'manufacturing.order.release');
    $order = mfgReleased($this->shirt, '10', $this->wh, ['output_warehouse_id' => $this->fg->id]);
    app(MaterialService::class)->issue($order, [['material' => 1, 'qty' => '20']], $this->admin->id);
    $this->order = $order->fresh();
    $this->url = "/manufacturing/orders/{$this->order->public_id}";
    $this->report = "/manufacturing/report/{$this->order->public_id}";
});

describe('the operator screens', function () {
    it('lists the open orders and shows the shift that is running', function () {
        $this->actingAs($this->operator)->get('/manufacturing/report')->assertOk()->assertSee($this->order->order_no)->assertSee('Day')->assertSee('2026-11-04');
        $this->actingAs($this->operator)->get($this->report)->assertOk()->assertSee('Units made')->assertSee('Save output')->assertSee('Save downtime')
            ->assertSee('value="2026-11-04"', false)->assertSee('value="DAY" selected', false);
    });

    it('lets an operator report output and downtime for the shift', function () {
        $this->actingAs($this->operator)->post("{$this->report}/output", ['shift' => 'DAY', 'date' => '2026-11-04', 'produced' => '6', 'rejected' => '1', 'rework' => '0', 'machine' => 'M1'])->assertSessionHasNoErrors()->assertSessionHas('status');
        $report = ProductionReport::sole();
        expect((string) $report->produced_qty)->toBe('6.000000')->and($report->reported_by)->toBe($this->operator->id);

        $this->actingAs($this->operator)->post("{$this->report}/downtime", ['shift' => 'DAY', 'date' => '2026-11-04', 'machine' => 'M1', 'reason' => 'BRK', 'from' => '09:00', 'minutes' => '30'])->assertSessionHasNoErrors();
        expect(DowntimeEvent::sole()->order_id)->toBe($this->order->id);
        $this->actingAs($this->operator)->get($this->report)->assertOk()->assertSee('Made')->assertSee('6');
    });

    it('says what is wrong on the field it belongs to', function () {
        $this->actingAs($this->operator)->from($this->report)->post("{$this->report}/output", ['shift' => 'DAY', 'produced' => '3', 'rejected' => '5'])->assertRedirect($this->report)->assertSessionHasErrors('rejected');
        $this->actingAs($this->operator)->from($this->report)->post("{$this->report}/output", ['shift' => 'NOPE', 'produced' => '3'])->assertSessionHasErrors('shift');
        $this->actingAs($this->operator)->from($this->report)->post("{$this->report}/downtime", ['shift' => 'DAY', 'machine' => 'M1', 'reason' => 'BRK', 'from' => '09:00', 'minutes' => '0'])->assertSessionHasErrors('minutes');
        expect(ProductionReport::count())->toBe(0)->and(DowntimeEvent::count())->toBe(0);
    });

    it('keeps cancelling, inspecting, receiving and completing away from operators', function () {
        $this->actingAs($this->operator)->post("{$this->report}/output", ['shift' => 'DAY', 'produced' => '4'])->assertSessionHasNoErrors();
        $report = ProductionReport::sole();
        $this->actingAs($this->operator)->post("/manufacturing/reports/{$report->public_id}/void", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->operator)->post("{$this->url}/inspections", ['accepted' => '4'])->assertForbidden();
        $this->actingAs($this->operator)->post("{$this->url}/receipts", ['qty' => '1'])->assertForbidden();
        $this->actingAs($this->operator)->post("{$this->url}/complete")->assertForbidden();
        $this->actingAs($this->operator)->post("{$this->url}/issue", ['lines' => [['material' => 1, 'qty' => '1']]])->assertForbidden();
        $this->actingAs($this->operator)->post('/manufacturing/orders', ['item' => $this->shirt->code, 'qty' => '1', 'source_warehouse' => 'RAW', 'output_warehouse' => 'FG'])->assertForbidden();
        $this->actingAs(userWithPermissions('sales.view'))->get('/manufacturing/report')->assertForbidden();
    });

    it('runs an order from output to completion on the order page', function () {
        $this->actingAs($this->operator)->post("{$this->report}/output", ['shift' => 'DAY', 'produced' => '10', 'rejected' => '1', 'rework' => '1'])->assertSessionHasNoErrors();

        $this->actingAs($this->planner)->get($this->url)->assertOk()->assertSee('Quality inspection')->assertSee('Record inspection')->assertSee('Complete order');
        $this->actingAs($this->planner)->post("{$this->url}/inspections", ['rejected' => '1'])->assertSessionHasErrors('reject_reason');
        $this->actingAs($this->planner)->post("{$this->url}/inspections", ['accepted' => '8'])->assertSessionHasNoErrors();

        $this->actingAs($this->planner)->post("{$this->url}/rework-done", ['shift' => 'DAY', 'qty' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->planner)->post("{$this->url}/inspections", ['accepted' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->planner)->post("{$this->url}/complete")->assertSessionHasErrors();           // 9 accepted units are not in stock yet
        $this->actingAs($this->planner)->post("{$this->url}/receipts", ['qty' => '9', 'date' => '2026-11-04'])->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->actingAs($this->planner)->post("{$this->url}/complete")->assertSessionHasNoErrors();

        $done = $this->order->fresh();
        expect($done->status)->toBe('completed')->and($done->received_qty)->toBe('9.000000');
        $page = $this->actingAs($this->planner)->get($this->url)->assertOk()->assertSee('Completed on')->assertSee('Variance');
        expect($page->getContent())->not->toContain('Record inspection');
        $this->actingAs($this->operator)->get($this->report)->assertStatus(409);
    });

    it('lets a supervisor cancel a report from the order page', function () {
        $this->actingAs($this->operator)->post("{$this->report}/output", ['shift' => 'DAY', 'produced' => '4'])->assertSessionHasNoErrors();
        $report = ProductionReport::sole();
        $this->actingAs($this->admin)->post("/manufacturing/reports/{$report->public_id}/void", ['reason' => 'typed twice'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get($this->url)->assertOk()->assertSee('typed twice');
        expect($this->order->fresh()->reported_qty)->toBe('0.000000');

        $this->actingAs($this->operator)->post("{$this->report}/downtime", ['shift' => 'DAY', 'machine' => 'M1', 'reason' => 'BRK', 'from' => '09:00', 'minutes' => '30']);
        $this->actingAs($this->admin)->post('/manufacturing/downtime/'.DowntimeEvent::sole()->public_id.'/void', ['reason' => 'wrong machine'])->assertSessionHasNoErrors();
        expect(DowntimeEvent::sole()->voided_at)->not->toBeNull();
    });

    it('shows output and downtime by shift', function () {
        $this->actingAs($this->operator)->post("{$this->report}/output", ['shift' => 'DAY', 'produced' => '6', 'rejected' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->operator)->post("{$this->report}/downtime", ['shift' => 'DAY', 'machine' => 'M1', 'reason' => 'BRK', 'from' => '09:00', 'minutes' => '30'])->assertSessionHasNoErrors();

        $this->actingAs($this->operator)->get('/manufacturing/shift-report?from=2026-11-01&to=2026-11-07')->assertOk()->assertSee('2026-11-04')->assertSee('30 min');
        $this->actingAs($this->operator)->get('/manufacturing/shift-report?from=2026-11-01&to=2026-11-07&shift=DAY&day=2026-11-04')->assertOk()->assertSee('Breakdown')->assertSee($this->order->order_no);
        $this->actingAs($this->operator)->get('/manufacturing/shift-report?from=2026-12-01&to=2026-12-07')->assertOk()->assertSee('Nothing reported');
        $this->actingAs(userWithPermissions('sales.view'))->get('/manufacturing/shift-report')->assertForbidden();
    });

    it('offers downtime reasons in setup and shows the new menu entries to operators only as far as they may go', function () {
        $this->actingAs($this->admin)->get('/manufacturing/setup/downtime-reasons')->assertOk()->assertSee('Breakdown');
        $this->actingAs($this->admin)->post('/manufacturing/setup/downtime-reasons', ['code' => 'chg', 'name' => 'Changeover', 'category' => 'changeover', 'is_active' => '1'])->assertRedirect('/manufacturing/setup/downtime-reasons');
        $this->actingAs($this->admin)->post('/manufacturing/setup/downtime-reasons', ['code' => 'x', 'name' => 'X', 'category' => 'nonsense'])->assertSessionHasErrors('category');

        $nav = function ($user) {
            preg_match('/<nav [^>]*aria-label="Main".*?<\/nav>/s', $this->actingAs($user)->get('/')->getContent(), $m);

            return $m[0] ?? '';
        };
        expect($nav($this->operator))->toContain('Report production')->toContain('Shift report')->toContain('Production orders');
    });
});

describe('API', function () {
    it('runs output, inspection, receipt and completion over HTTP', function () {
        $id = $this->order->public_id;
        asApi($this->operator)->postJson("/api/v1/production-orders/{$id}/output", ['shift' => 'DAY', 'produced' => '10', 'rejected' => '2', 'rework' => '1', 'machine' => 'M1', 'external_id' => 'CLIENT-1'], idemKey())->assertCreated()
            ->assertJsonPath('data.reported_qty', '10')->assertJsonPath('data.rejected_qty', '2')->assertJsonPath('data.pending_qty', '7')->assertJsonPath('data.reports.0.shift', 'DAY');
        asApi($this->operator)->postJson("/api/v1/production-orders/{$id}/output", ['shift' => 'DAY', 'produced' => '10', 'external_id' => 'CLIENT-1'], idemKey())->assertCreated()->assertJsonPath('data.reported_qty', '10');   // same client reference: nothing new

        asApi($this->operator)->postJson("/api/v1/production-orders/{$id}/inspections", ['accepted' => '7'], idemKey())->assertForbidden();
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/inspections", ['accepted' => '6', 'rework' => '1', 'reject_reason' => 'seam'], idemKey())->assertCreated()->assertJsonPath('data.accepted_qty', '6')->assertJsonPath('data.rework_qty', '2');
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/rework-done", ['shift' => 'DAY', 'qty' => '2'], idemKey())->assertCreated()->assertJsonPath('data.rework_qty', '0');
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/inspections", ['accepted' => '2'], idemKey())->assertCreated();
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/receipts", ['qty' => '8', 'date' => '2026-11-04'], idemKey())->assertCreated()
            ->assertJsonPath('data.received_qty', '8')->assertJsonPath('data.receipts.0.unit_cost', '800')->assertJsonPath('data.fg_value', '6400.00');
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/complete", [], idemKey())->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.variance_value', '1600.00');
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/complete", [], idemKey())->assertStatus(409)->assertJsonPath('error.code', 'manufacturing.invalid_state');
    });

    it('answers with the rule that was broken', function () {
        $id = $this->order->public_id;
        asApi($this->operator)->postJson("/api/v1/production-orders/{$id}/output", ['shift' => 'DAY', 'produced' => '50'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'manufacturing.over_production');
        asApi($this->operator)->postJson("/api/v1/production-orders/{$id}/output", ['produced' => '1'], idemKey())->assertStatus(422);
        asApi($this->operator)->postJson("/api/v1/production-orders/{$id}/output", ['shift' => 'DAY', 'produced' => 'abc'], idemKey())->assertStatus(422);
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/receipts", ['qty' => '1'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'manufacturing.receipt_too_much');
        asApi($this->planner)->postJson("/api/v1/production-orders/{$id}/complete", [], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'manufacturing.nothing_made');
        asApi(userWithPermissions('sales.view'))->postJson("/api/v1/production-orders/{$id}/output", ['shift' => 'DAY', 'produced' => '1'], idemKey())->assertForbidden();
    });

    it('records and cancels downtime and reads the shift report', function () {
        $event = asApi($this->operator)->postJson('/api/v1/downtime-events', ['shift' => 'DAY', 'machine' => 'M1', 'reason' => 'BRK', 'from' => '09:00', 'minutes' => '20', 'order' => $this->order->order_no, 'external_id' => 'DT-1'], idemKey())->assertCreated()
            ->assertJsonPath('data.minutes', '20.00')->assertJsonPath('data.shift_date', '2026-11-04')->json('data.id');
        asApi($this->operator)->postJson('/api/v1/downtime-events', ['shift' => 'DAY', 'machine' => 'M1', 'reason' => 'BRK', 'from' => '09:10', 'minutes' => '5'], idemKey())->assertStatus(422)->assertJsonPath('error.code', 'manufacturing.downtime_overlap');
        asApi($this->operator)->postJson('/api/v1/downtime-events', ['shift' => 'DAY', 'machine' => 'M1', 'reason' => 'BRK', 'started_at' => '2026-11-04 08:00', 'ended_at' => '2026-11-04 08:20'], idemKey())->assertCreated()->assertJsonPath('data.minutes', '20.00');

        asApi($this->operator)->postJson("/api/v1/downtime-events/{$event}/void", ['reason' => 'x'], idemKey())->assertForbidden();
        asApi($this->admin)->postJson("/api/v1/downtime-events/{$event}/void", ['reason' => 'wrong'], idemKey())->assertOk()->assertJsonPath('data.voided', true);
        asApi($this->operator)->postJson("/api/v1/production-orders/{$this->order->public_id}/output", ['shift' => 'DAY', 'produced' => '5'], idemKey())->assertCreated();

        asApi($this->operator)->getJson('/api/v1/shift-report?from=2026-11-01&to=2026-11-07')->assertOk()->assertJsonPath('data.0.shift', 'DAY')->assertJsonPath('data.0.produced', '5')->assertJsonPath('data.0.downtime_minutes', '20')
            ->assertJsonPath('meta.from', '2026-11-01');
        $report = ProductionReport::sole();
        asApi($this->admin)->postJson("/api/v1/production-reports/{$report->public_id}/void", ['reason' => 'typed twice'], idemKey())->assertOk()->assertJsonPath('data.voided', true);
        asApi($this->operator)->getJson('/api/v1/shift-report?shift=NOPE')->assertOk()->assertJsonPath('meta.shift', null);
    });

    it('manages downtime reasons like the other setup lists', function () {
        asApi($this->admin)->postJson('/api/v1/manufacturing/downtime-reasons', ['code' => 'chg', 'name' => 'Changeover', 'category' => 'changeover'])->assertCreated()->assertJsonPath('data.code', 'CHG')->assertJsonPath('data.category', 'changeover');
        asApi($this->admin)->postJson('/api/v1/manufacturing/downtime-reasons', ['code' => 'bad', 'name' => 'Bad', 'category' => 'nonsense'])->assertStatus(422);
        asApi($this->operator)->getJson('/api/v1/manufacturing/downtime-reasons')->assertOk()->assertJsonCount(2, 'data');
    });
});
