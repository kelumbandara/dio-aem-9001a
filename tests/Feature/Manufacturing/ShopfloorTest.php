<?php

use App\Modules\Core\Services\OutboxRelay;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Services\StockReconciler;
use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Models\DowntimeEvent;
use App\Modules\Manufacturing\Models\FinishedGoodsReceipt;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionReport;
use App\Modules\Manufacturing\Models\Shift;
use App\Modules\Manufacturing\Services\BomService;
use App\Modules\Manufacturing\Services\DowntimeService;
use App\Modules\Manufacturing\Services\FinishedGoodsService;
use App\Modules\Manufacturing\Services\InspectionService;
use App\Modules\Manufacturing\Services\MaterialService;
use App\Modules\Manufacturing\Services\OutputService;
use App\Modules\Manufacturing\Services\ProductionOrderService;
use App\Modules\Manufacturing\Services\SetupService;
use App\Modules\Manufacturing\Services\ShiftReport;
use App\Modules\Manufacturing\Services\ShiftService;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->wh = invWarehouse(['code' => 'RAW']);
    $this->fg = invWarehouse(['code' => 'FG']);
    $this->shirt = mdItem(['name' => 'Shirt', 'base_uom' => 'PCS']);
    $this->fabric = mdItem(['name' => 'Cotton fabric', 'base_uom' => 'KG']);
    $this->button = mdItem(['name' => 'Button', 'base_uom' => 'PCS']);
    mfgCenter('CUT');
    $setup = app(SetupService::class);
    $setup->save('machines', ['code' => 'M1', 'name' => 'Cutter', 'work_center' => 'CUT', 'status' => 'available']);
    $setup->save('machines', ['code' => 'M2', 'name' => 'Sewer', 'work_center' => 'CUT', 'status' => 'available']);
    $setup->save('machines', ['code' => 'M3', 'name' => 'Old press', 'work_center' => 'CUT', 'status' => 'maintenance']);
    $setup->save('shifts', ['code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'days' => '1111111']);
    $setup->save('shifts', ['code' => 'NIGHT', 'name' => 'Night', 'start_time' => '20:00', 'end_time' => '05:00', 'days' => '1111111']);
    $setup->save('shifts', ['code' => 'WKDAY', 'name' => 'Weekday only', 'start_time' => '09:00', 'end_time' => '12:00', 'days' => '1111100']);
    $setup->save('downtime-reasons', ['code' => 'BRK', 'name' => 'Breakdown', 'category' => 'breakdown']);
    $setup->save('downtime-reasons', ['code' => 'MAT', 'name' => 'Waiting for fabric', 'category' => 'material']);
    $setup->save('downtime-reasons', ['code' => 'OLD', 'name' => 'Not used any more', 'category' => 'other', 'is_active' => false]);

    $this->output = app(OutputService::class);
    $this->qc = app(InspectionService::class);
    $this->goods = app(FinishedGoodsService::class);
    $this->downtime = app(DowntimeService::class);
    $this->material = app(MaterialService::class);
    $this->user = userWithPermissions('manufacturing.output.report', 'manufacturing.order.release');
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    $this->relay = fn () => app(OutboxRelay::class)->run(2000);
    $this->checks = function () {
        expect(app(FinanceReconciler::class)->discrepancies())->toBe([])->and(app(StockReconciler::class)->discrepancies())->toBe([]);
    };
    // 2 kg fabric (400 each) and 5 buttons (2 each) make a shirt: 810 a shirt
    mfgBom($this->shirt, [['item' => $this->fabric, 'qty' => '2'], ['item' => $this->button, 'qty' => '5']]);
    invReceive($this->fabric, $this->wh, '1000', '400');
    invReceive($this->button, $this->wh, '10000', '2');
    /** A released order of $qty shirts with all its material issued: 810 a shirt in work in progress. */
    $this->started = function (string $qty = '10') {
        $order = mfgReleased($this->shirt, $qty, $this->wh, ['output_warehouse_id' => $this->fg->id]);
        $this->material->issue($order, [['material' => 1, 'qty' => Decimal::trim(Decimal::mul($qty, '2'))], ['material' => 2, 'qty' => Decimal::trim(Decimal::mul($qty, '5'))]], $this->user->id);

        return $order->fresh();
    };
    $this->make = fn (ProductionOrder $o, string $made, string $rej = '0', string $rew = '0', array $more = []) => $this->output->report($o, array_merge(['shift' => 'DAY', 'produced' => $made, 'rejected' => $rej, 'rework' => $rew], $more), $this->user->id);
    $this->sums = fn (ProductionOrder $o) => collect($o->fresh()->only(['reported_qty', 'accepted_qty', 'rejected_qty', 'rework_qty', 'received_qty']))->map(fn ($v) => Decimal::trim((string) $v))->all();
});

describe('which shift is running', function () {
    it('finds the shift and its date, including a night shift after midnight', function () {
        $shifts = app(ShiftService::class);
        $at = fn (string $when) => $shifts->current(CarbonImmutable::parse($when, 'Asia/Colombo'));

        expect($at('2026-11-04 10:00')['shift']->code)->toBe('DAY')->and($at('2026-11-04 10:00')['date']->toDateString())->toBe('2026-11-04');
        expect($at('2026-11-04 21:30')['shift']->code)->toBe('NIGHT')->and($at('2026-11-04 21:30')['date']->toDateString())->toBe('2026-11-04');
        expect($at('2026-11-05 02:15')['shift']->code)->toBe('NIGHT')->and($at('2026-11-05 02:15')['date']->toDateString())->toBe('2026-11-04');   // still the shift of the evening before
        expect($at('2026-11-04 06:00'))->toBeNull();
        expect($at('2026-11-04 17:00'))->toBeNull();                                                                                              // a shift ends at its end time
        // the weekday-only shift runs Wednesday 09:00-12:00 but not Saturday; the day shift covers that hour
        expect($at('2026-11-04 11:00')['shift']->code)->toBe('DAY');
    });

    it('does not offer a shift on a day it does not work', function () {
        app(SetupService::class)->save('shifts', ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'days' => '1111100'], Shift::where('code', 'DAY')->first());
        $shifts = app(ShiftService::class);
        expect($shifts->current(CarbonImmutable::parse('2026-11-07 10:00', 'Asia/Colombo')))->toBeNull()                          // Saturday: neither day shift works
            ->and($shifts->current(CarbonImmutable::parse('2026-11-06 10:00', 'Asia/Colombo'))['shift']->code)->toBe('DAY')       // Friday
            ->and($shifts->current(CarbonImmutable::parse('2026-11-07 21:00', 'Asia/Colombo'))['shift']->code)->toBe('NIGHT');    // the night shift works every day
    });
});

describe('reporting output', function () {
    it('adds the units to the order, keeps the quantities apart and starts the order', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        $report = ($this->make)($order, '6', '1', '1', ['machine' => 'm1', 'operation' => null, 'notes' => 'first batch']);

        expect($report->kind)->toBe('output')->and((string) $report->produced_qty)->toBe('6.000000')->and($report->source)->toBe('manual')->and($report->machine_id)->toBe(Machine::where('code', 'M1')->value('id'));
        expect(($this->sums)($order))->toBe(['reported_qty' => '6', 'accepted_qty' => '0', 'rejected_qty' => '1', 'rework_qty' => '1', 'received_qty' => '0'])
            ->and($order->fresh()->status)->toBe('in_progress')->and($order->fresh()->pendingQty())->toBe('4.000000000000');
    });

    it('refuses what makes no sense, and records nothing', function (Closure $data, string $code) {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        mfgFails(fn () => $this->output->report($order, $data($this)), $code);
        expect(ProductionReport::count())->toBe(0)->and(($this->sums)($order)['reported_qty'])->toBe('0');
    })->with([
        'no shift' => [fn ($t) => ['produced' => '1'], 'manufacturing.shift_required'],
        'an unknown shift' => [fn ($t) => ['shift' => 'NOPE', 'produced' => '1'], 'manufacturing.shift_required'],
        'zero units' => [fn ($t) => ['shift' => 'DAY', 'produced' => '0'], 'manufacturing.qty_invalid'],
        'half a shirt' => [fn ($t) => ['shift' => 'DAY', 'produced' => '1.5'], 'manufacturing.qty_invalid'],
        'rejects and rework more than made' => [fn ($t) => ['shift' => 'DAY', 'produced' => '5', 'rejected' => '3', 'rework' => '3'], 'manufacturing.rejects_exceed'],
        'more than the order may make' => [fn ($t) => ['shift' => 'DAY', 'produced' => '12'], 'manufacturing.over_production'],
        'a shift that has not happened' => [fn ($t) => ['shift' => 'DAY', 'produced' => '1', 'date' => now('Asia/Colombo')->addDay()->toDateString()], 'manufacturing.date_future'],
        'a shift too long ago' => [fn ($t) => ['shift' => 'DAY', 'produced' => '1', 'date' => now('Asia/Colombo')->subDays(30)->toDateString()], 'manufacturing.date_too_old'],
        'a machine in maintenance' => [fn ($t) => ['shift' => 'DAY', 'produced' => '1', 'machine' => 'M3'], 'manufacturing.machine_unavailable'],
        'a step that is not on the order' => [fn ($t) => ['shift' => 'DAY', 'produced' => '1', 'operation' => 99], 'manufacturing.operation_invalid'],
    ]);

    it('allows up to the percentage over the plan and no more', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);                         // 10 planned, 10 % over = 11
        ($this->make)($order, '11');
        mfgFails(fn () => ($this->make)($order->fresh(), '1'), 'manufacturing.over_production');
    });

    it('works only on a released or running order', function () {
        mfgFails(fn () => ($this->make)(mfgOrder($this->shirt, '10', $this->wh), '1'), 'manufacturing.invalid_state');
        $cancelled = mfgReleased($this->shirt, '5', $this->wh);
        app(ProductionOrderService::class)->cancel($cancelled, 'not needed', 1);
        mfgFails(fn () => ($this->make)($cancelled->fresh(), '1'), 'manufacturing.invalid_state');
        expect(($this->make)(($this->started)('10'), '1'))->toBeInstanceOf(ProductionReport::class);
    });

    it('records a device\'s report once however often it is sent', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        $a = $this->output->report($order, ['shift' => 'DAY', 'produced' => '3'], null, 'device', 'EVT-1');
        $b = $this->output->report($order->fresh(), ['shift' => 'DAY', 'produced' => '3'], null, 'device', 'EVT-1');

        expect($b->id)->toBe($a->id)->and(ProductionReport::count())->toBe(1)->and(($this->sums)($order)['reported_qty'])->toBe('3')->and($a->source)->toBe('device');
    });

    it('marks the step in progress', function () {
        $service = app(BomService::class);
        $draft = $service->copyToDraft(BomVersion::where('item_id', $this->shirt->id)->where('status', 'active')->first());
        $service->save(['components' => [['item' => $this->fabric->code, 'qty' => '2'], ['item' => $this->button->code, 'qty' => '5']], 'operations' => [['name' => 'Cut', 'work_center' => 'CUT', 'op_no' => 10], ['name' => 'Sew', 'work_center' => 'CUT', 'op_no' => 20]]], $draft);
        $service->activate($draft->fresh());

        $order = mfgReleased($this->shirt, '10', $this->wh);
        expect($order->operations->pluck('status')->unique()->all())->toBe(['pending']);
        ($this->make)($order, '2', '0', '0', ['operation' => 20]);
        expect($order->operations()->where('op_no', 20)->value('status'))->toBe('in_progress')->and($order->operations()->where('op_no', 10)->value('status'))->toBe('pending');
    });
});

describe('rework and cancelling a report', function () {
    it('brings reworked units back for inspection and not more than are waiting', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        ($this->make)($order, '6', '0', '2');
        expect(($this->sums)($order)['rework_qty'])->toBe('2');

        mfgFails(fn () => $this->output->reworkDone($order->fresh(), ['shift' => 'DAY', 'qty' => '3']), 'manufacturing.rework_too_much');
        $done = $this->output->reworkDone($order->fresh(), ['shift' => 'DAY', 'qty' => '2']);
        expect($done->kind)->toBe('rework_done')->and(($this->sums)($order))->toMatchArray(['rework_qty' => '0', 'reported_qty' => '6'])->and($order->fresh()->pendingQty())->toBe('6.000000000000');
    });

    it('cancels a report that nobody has built on, and refuses one that has been inspected or reworked', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        $first = ($this->make)($order, '4', '1', '1');
        $this->output->void($first, 'typed wrongly', $this->user->id);
        expect($first->fresh()->voided_at)->not->toBeNull()->and($first->fresh()->void_reason)->toBe('typed wrongly')->and(($this->sums)($order))->toMatchArray(['reported_qty' => '0', 'rejected_qty' => '0', 'rework_qty' => '0']);
        mfgFails(fn () => $this->output->void($first->fresh(), 'again', $this->user->id), 'manufacturing.already_voided');
        mfgFails(fn () => $this->output->void(($this->make)($order->fresh(), '2'), '  ', $this->user->id), 'manufacturing.reason_required');

        $second = ($this->make)($order->fresh(), '6', '0', '2');
        $this->qc->inspect($order->fresh(), ['accepted' => '3'], $this->user->id);                       // 6 made, 2 rework, 3 accepted: 1 left unjudged (plus the 2 earlier)
        mfgFails(fn () => $this->output->void($second->fresh(), 'oops', $this->user->id), 'manufacturing.report_in_use');

        $order = mfgReleased($this->shirt, '10', $this->wh);
        ($this->make)($order, '4', '0', '2');
        $rework = $this->output->reworkDone($order->fresh(), ['shift' => 'DAY', 'qty' => '2']);
        $this->qc->inspect($order->fresh(), ['accepted' => '4'], $this->user->id);
        mfgFails(fn () => $this->output->void($rework->fresh(), 'oops', $this->user->id), 'manufacturing.report_in_use');                  // those units are already accepted
    });

    it('keeps a report as entered: the database refuses any change but cancelling it once', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        $report = ($this->make)($order, '4');
        $try = fn (Closure $sql, string $message) => expect(fn () => inSavepoint($sql))->toThrow(Exception::class, $message);

        $try(fn () => DB::table('production_reports')->where('id', $report->id)->update(['produced_qty' => '9']), 'cannot be changed');
        $try(fn () => DB::table('production_reports')->where('id', $report->id)->update(['shift_id' => 99]), 'cannot be changed');
        $try(fn () => DB::table('production_reports')->where('id', $report->id)->delete(), 'not allowed');
        $this->output->void($report, 'wrong', $this->user->id);
        $try(fn () => DB::table('production_reports')->where('id', $report->id)->update(['void_reason' => 'changed']), 'voided entry');
    });
});

describe('quality inspection', function () {
    it('judges the waiting units and keeps the six quantities consistent', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        ($this->make)($order, '8', '1', '1');                                                       // 6 wait for inspection

        $this->qc->inspect($order->fresh(), ['accepted' => '4', 'rejected' => '1', 'rework' => '1', 'reject_reason' => 'stitching'], $this->user->id);
        expect(($this->sums)($order))->toBe(['reported_qty' => '8', 'accepted_qty' => '4', 'rejected_qty' => '2', 'rework_qty' => '2', 'received_qty' => '0'])
            ->and($order->fresh()->pendingQty())->toBe('0.000000000000');
    });

    it('refuses to judge more than is waiting, nothing at all, or a reject without a reason', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        ($this->make)($order, '5');

        mfgFails(fn () => $this->qc->inspect($order->fresh(), ['accepted' => '5.5']), 'manufacturing.qty_invalid');
        mfgFails(fn () => $this->qc->inspect($order->fresh(), ['accepted' => '4', 'rejected' => '2', 'reject_reason' => 'x']), 'manufacturing.inspection_too_many');
        mfgFails(fn () => $this->qc->inspect($order->fresh(), []), 'manufacturing.inspection_empty');
        mfgFails(fn () => $this->qc->inspect($order->fresh(), ['rejected' => '1']), 'manufacturing.reason_required');
        mfgFails(fn () => $this->qc->inspect($order->fresh(), ['accepted' => '1', 'date' => '2999-01-01']), 'manufacturing.date_invalid');
        expect(($this->sums)($order)['accepted_qty'])->toBe('0');
        mfgFails(fn () => $this->qc->inspect(mfgReleased($this->shirt, '5', $this->wh), ['accepted' => '1']), 'manufacturing.invalid_state');   // nothing reported, still "released"
    });

    it('is kept as recorded', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        ($this->make)($order, '5');
        $i = $this->qc->inspect($order->fresh(), ['accepted' => '5']);
        expect(fn () => inSavepoint(fn () => DB::table('quality_inspections')->where('id', $i->id)->update(['accepted_qty' => '1'])))->toThrow(Exception::class, 'not allowed');
    });
});

describe('finished goods and cost', function () {
    it('takes accepted units into stock at the material cost of the order, and clears work in progress', function () {
        $order = ($this->started)('10');                                                           // 8,100 of material
        ($this->make)($order, '10');
        $this->qc->inspect($order->fresh(), ['accepted' => '10']);

        $first = $this->goods->receive($order->fresh(), ['qty' => '4']);
        ($this->relay)();
        expect((string) $first->unit_cost)->toBe('810.000000')->and((string) $first->value)->toBe('3240.0000')->and($first->stock_document_no)->toStartWith('SRC/')
            ->and(invOnHand($this->shirt, $this->fg))->toBe('4.000000')->and(($this->bal)('1105'))->toBe('4860.0000');

        $this->goods->receive($order->fresh(), ['qty' => '6']);
        ($this->relay)();
        expect(invOnHand($this->shirt, $this->fg))->toBe('10.000000')->and(($this->bal)('1105'))->toBe('0.0000')->and($order->fresh()->received_qty)->toBe('10.000000')
            ->and((string) $order->fresh()->fg_value)->toBe('8100.0000');
        ($this->checks)();

        $done = $this->goods->complete($order->fresh(), $this->user->id);
        ($this->relay)();
        expect($done->status)->toBe('completed')->and((string) $done->variance_value)->toBe('0.0000')->and($done->operations->pluck('status')->unique()->all())->not->toContain('pending')
            ->and(JournalEntry::where('source_type', 'production_order_completion')->count())->toBe(0);
        ($this->checks)();
    });

    it('spreads the cost of rejected units over the good ones, and posts what is left as the order\'s variance', function () {
        $order = ($this->started)('10');                                                           // 8,100 of material for 10 shirts
        ($this->make)($order, '10', '2');                                                          // 2 rejected: 8 good
        $this->qc->inspect($order->fresh(), ['accepted' => '8']);
        $receipt = $this->goods->receive($order->fresh(), ['qty' => '8']);
        // the plan was 10 shirts, so each good one carries 810 and the cost of the two lost shirts (1,620) stays behind
        expect((string) $receipt->unit_cost)->toBe('810.000000')->and((string) $receipt->value)->toBe('6480.0000');

        $done = $this->goods->complete($order->fresh(), $this->user->id);
        ($this->relay)();
        expect((string) $done->variance_value)->toBe('1620.0000')->and(($this->bal)('1105'))->toBe('0.0000')->and(($this->bal)('2208'))->toBe('1620.0000')
            ->and(JournalEntry::where('source_type', 'production_order_completion')->count())->toBe(1);
        ($this->checks)();
    });

    it('writes off all the material when every unit is rejected', function () {
        $order = ($this->started)('10');
        ($this->make)($order, '10', '10');
        $done = $this->goods->complete($order->fresh(), $this->user->id);
        ($this->relay)();
        expect((string) $done->variance_value)->toBe('8100.0000')->and(($this->bal)('1105'))->toBe('0.0000')->and(($this->bal)('2208'))->toBe('8100.0000')->and($done->received_qty)->toBe('0.000000');
        ($this->checks)();
    });

    it('lets rounding residue go to the variance instead of leaving it in work in progress', function () {
        // 1 kg of fabric for 3 shirts: 3,000 shirts need 1,000 kg at 400 = 400,000; 400,000 / 3,000 = 133.333333 a shirt
        $third = mdItem(['name' => 'Tee', 'base_uom' => 'PCS']);
        mfgBom($third, [['item' => $this->fabric, 'qty' => '1']], ['base_qty' => '3']);
        invReceive($this->fabric, $this->wh, '1000', '400');
        $order = mfgReleased($third, '3000', $this->wh, ['output_warehouse_id' => $this->fg->id]);
        $this->material->issue($order, [['material' => 1, 'qty' => '1000']]);
        $order = $order->fresh();
        $this->output->report($order, ['shift' => 'DAY', 'produced' => '3000'], $this->user->id);
        $this->qc->inspect($order->fresh(), ['accepted' => '3000']);

        $receipt = $this->goods->receive($order->fresh(), ['qty' => '3000']);
        $done = $this->goods->complete($order->fresh(), $this->user->id);
        ($this->relay)();
        // 3,000 x 133.333333 = 399,999.9990 taken into stock; the 0.0010 left over is the variance
        expect((string) $receipt->unit_cost)->toBe('133.333333')->and((string) $receipt->value)->toBe('399999.9990')->and((string) $done->variance_value)->toBe('0.0010')
            ->and(($this->bal)('1105'))->toBe('0.0000')->and(($this->bal)('2208'))->toBe('0.0010');
        ($this->checks)();
    });

    it('refuses to receive more than inspection passed, or before inspection', function () {
        $order = ($this->started)('10');
        ($this->make)($order, '10');
        mfgFails(fn () => $this->goods->receive($order->fresh(), ['qty' => '1']), 'manufacturing.receipt_too_much');
        $this->qc->inspect($order->fresh(), ['accepted' => '6']);
        mfgFails(fn () => $this->goods->receive($order->fresh(), ['qty' => '7']), 'manufacturing.receipt_too_much');
        mfgFails(fn () => $this->goods->receive($order->fresh(), ['qty' => '0']), 'manufacturing.qty_invalid');
        mfgFails(fn () => $this->goods->receive($order->fresh(), ['qty' => '2.5']), 'manufacturing.qty_invalid');
        $this->goods->receive($order->fresh(), ['qty' => '6']);
        mfgFails(fn () => $this->goods->receive($order->fresh(), ['qty' => '1']), 'manufacturing.receipt_too_much');
        expect(FinishedGoodsReceipt::count())->toBe(1);
    });

    it('cannot be changed once received, and a production receipt cannot be reversed from the stock side', function () {
        $order = ($this->started)('10');
        ($this->make)($order, '10');
        $this->qc->inspect($order->fresh(), ['accepted' => '10']);
        $receipt = $this->goods->receive($order->fresh(), ['qty' => '10']);

        expect(fn () => inSavepoint(fn () => DB::table('finished_goods_receipts')->where('id', $receipt->id)->update(['qty' => '1'])))->toThrow(Exception::class, 'not allowed');
        $document = StockDocument::findOrFail($receipt->stock_document_id);
        expect($document->source_type)->toBe('production_receipt')->and(userWithPermissions('inventory.reverse', 'inventory.receive', 'inventory.stock.view')->can('reverse', $document))->toBeFalse();
    });
});

describe('completing', function () {
    it('needs everything judged, reworked and received', function () {
        $order = ($this->started)('10');
        mfgFails(fn () => $this->goods->complete($order, 1), 'manufacturing.nothing_made');
        ($this->make)($order, '10', '0', '2');
        mfgFails(fn () => $this->goods->complete($order->fresh(), 1), 'manufacturing.inspection_pending');
        $this->qc->inspect($order->fresh(), ['accepted' => '8']);
        mfgFails(fn () => $this->goods->complete($order->fresh(), 1), 'manufacturing.rework_pending');
        $this->output->reworkDone($order->fresh(), ['shift' => 'DAY', 'qty' => '2']);
        mfgFails(fn () => $this->goods->complete($order->fresh(), 1), 'manufacturing.inspection_pending');
        $this->qc->inspect($order->fresh(), ['accepted' => '2']);
        mfgFails(fn () => $this->goods->complete($order->fresh(), 1), 'manufacturing.receipt_pending');
        $this->goods->receive($order->fresh(), ['qty' => '10']);
        expect($this->goods->complete($order->fresh(), $this->user->id)->status)->toBe('completed');
    });

    it('closes the order to everything else', function () {
        $order = ($this->started)('10');
        ($this->make)($order, '10');
        $this->qc->inspect($order->fresh(), ['accepted' => '10']);
        $this->goods->receive($order->fresh(), ['qty' => '10']);
        $this->goods->complete($order->fresh(), $this->user->id);
        $done = $order->fresh();

        mfgFails(fn () => ($this->make)($done, '1'), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->qc->inspect($done, ['accepted' => '1']), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->goods->receive($done, ['qty' => '1']), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->goods->complete($done, 1), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->material->issue($done, [['material' => 1, 'qty' => '1']]), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->material->return($done, [['material' => 1, 'qty' => '1']], null, 'x'), 'manufacturing.invalid_state');
        mfgFails(fn () => app(ProductionOrderService::class)->cancel($done, 'x', 1), 'manufacturing.invalid_state');
    });

    it('does not let an order with output be cancelled', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        ($this->make)($order, '3');
        mfgFails(fn () => app(ProductionOrderService::class)->cancel($order->fresh(), 'changed my mind', 1), 'manufacturing.has_output');
    });
});

describe('machine downtime', function () {
    beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00', 'Asia/Colombo')));

    it('records the time a machine stood still for a shift, with its reason', function () {
        $order = mfgReleased($this->shirt, '10', $this->wh);
        $event = $this->downtime->report(['machine' => 'm1', 'shift' => 'day', 'reason' => 'brk', 'from' => '13:30', 'minutes' => '45', 'order' => $order->order_no, 'notes' => 'belt snapped'], $this->user->id);

        expect($event->shift_date->toDateString())->toBe('2026-11-04')->and((string) $event->minutes)->toBe('45.00')->and($event->order_id)->toBe($order->id)
            ->and($event->started_at->setTimezone('Asia/Colombo')->format('H:i'))->toBe('13:30')->and($event->ended_at->setTimezone('Asia/Colombo')->format('H:i'))->toBe('14:15')
            ->and($event->source)->toBe('manual');
    });

    it('puts the hours after midnight of a night shift on the date the shift started', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 03:00', 'Asia/Colombo'));
        $event = $this->downtime->report(['machine' => 'M1', 'shift' => 'NIGHT', 'reason' => 'BRK', 'date' => '2026-11-04', 'from' => '01:00', 'minutes' => '30']);
        expect($event->shift_date->toDateString())->toBe('2026-11-04')->and($event->started_at->setTimezone('Asia/Colombo')->format('Y-m-d H:i'))->toBe('2026-11-05 01:00');
        $evening = $this->downtime->report(['machine' => 'M1', 'shift' => 'NIGHT', 'reason' => 'BRK', 'date' => '2026-11-04', 'from' => '22:00', 'minutes' => '15']);
        expect($evening->started_at->setTimezone('Asia/Colombo')->format('Y-m-d H:i'))->toBe('2026-11-04 22:00');
    });

    it('refuses two stoppages of one machine at the same time, but not touching ones or another machine', function () {
        $this->downtime->report(['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'BRK', 'from' => '10:00', 'minutes' => '60']);
        mfgFails(fn () => $this->downtime->report(['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'MAT', 'from' => '10:30', 'minutes' => '60']), 'manufacturing.downtime_overlap');
        mfgFails(fn () => $this->downtime->report(['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'MAT', 'from' => '09:30', 'minutes' => '45']), 'manufacturing.downtime_overlap');
        $this->downtime->report(['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'MAT', 'from' => '11:00', 'minutes' => '30']);   // starts as the other ends
        $this->downtime->report(['machine' => 'M2', 'shift' => 'DAY', 'reason' => 'BRK', 'from' => '10:00', 'minutes' => '60']);
        expect(DowntimeEvent::count())->toBe(3);
    });

    it('refuses what cannot be right', function (array $data, string $code) {
        mfgFails(fn () => $this->downtime->report($data + ['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'BRK', 'from' => '10:00', 'minutes' => '30']), $code);
        expect(DowntimeEvent::count())->toBe(0);
    })->with([
        'an unknown machine' => [['machine' => 'NOPE'], 'manufacturing.machine_required'],
        'no shift' => [['shift' => ''], 'manufacturing.shift_required'],
        'a reason that is switched off' => [['reason' => 'OLD'], 'manufacturing.reason_invalid'],
        'an unknown reason' => [['reason' => 'NOPE'], 'manufacturing.reason_invalid'],
        'a bad time' => [['from' => '25:99'], 'manufacturing.time_invalid'],
        'no minutes' => [['minutes' => '0'], 'manufacturing.minutes_invalid'],
        'part minutes' => [['minutes' => '1.5'], 'manufacturing.minutes_invalid'],
        'time not yet come' => [['from' => '16:00'], 'manufacturing.date_future'],
        'an order that does not exist' => [['order' => 'MO/NOPE'], 'manufacturing.order_invalid'],
    ]);

    it('can be cancelled and entered again, and is kept as entered', function () {
        $event = $this->downtime->report(['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'BRK', 'from' => '10:00', 'minutes' => '60']);
        mfgFails(fn () => $this->downtime->void($event, ' ', $this->user->id), 'manufacturing.reason_required');
        $this->downtime->void($event, 'wrong machine', $this->user->id);
        mfgFails(fn () => $this->downtime->void($event->fresh(), 'again', $this->user->id), 'manufacturing.already_voided');
        $again = $this->downtime->report(['machine' => 'M2', 'shift' => 'DAY', 'reason' => 'BRK', 'from' => '10:00', 'minutes' => '60']);   // the slot is free again
        $same = $this->downtime->report(['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'BRK', 'from' => '10:00', 'minutes' => '60']);
        expect($same->id)->not->toBe($event->id)->and($again->machine_id)->not->toBe($same->machine_id);

        $try = fn (Closure $sql, string $m) => expect(fn () => inSavepoint($sql))->toThrow(Exception::class, $m);
        $try(fn () => DB::table('downtime_events')->where('id', $same->id)->update(['minutes' => 1]), 'cannot be changed');
        $try(fn () => DB::table('downtime_events')->where('id', $same->id)->delete(), 'not allowed');
        $try(fn () => DB::table('downtime_events')->where('id', $event->id)->update(['void_reason' => 'x']), 'voided entry');
    });

    it('records a device\'s event once', function () {
        $a = $this->downtime->report(['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'BRK', 'from' => '10:00', 'minutes' => '20'], null, 'device', 'DT-9');
        $b = $this->downtime->report(['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'BRK', 'from' => '10:00', 'minutes' => '20'], null, 'device', 'DT-9');
        expect($b->id)->toBe($a->id)->and(DowntimeEvent::count())->toBe(1);
    });
});

describe('the shift report', function () {
    it('adds output and downtime by date and shift, leaving cancelled entries out', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00', 'Asia/Colombo'));
        $order = mfgReleased($this->shirt, '40', $this->wh);
        $this->output->report($order, ['shift' => 'DAY', 'date' => '2026-11-04', 'produced' => '10', 'rejected' => '1', 'rework' => '2'], $this->user->id);
        $this->output->report($order->fresh(), ['shift' => 'DAY', 'date' => '2026-11-04', 'produced' => '5'], $this->user->id);
        $wrong = $this->output->report($order->fresh(), ['shift' => 'DAY', 'date' => '2026-11-04', 'produced' => '9'], $this->user->id);
        $this->output->void($wrong, 'typed twice', $this->user->id);
        $this->output->report($order->fresh(), ['shift' => 'NIGHT', 'date' => '2026-11-03', 'produced' => '7', 'rejected' => '3'], $this->user->id);
        $this->output->reworkDone($order->fresh(), ['shift' => 'DAY', 'date' => '2026-11-04', 'qty' => '1']);
        $this->downtime->report(['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'BRK', 'from' => '10:00', 'minutes' => '40']);
        $this->downtime->report(['machine' => 'M2', 'shift' => 'DAY', 'reason' => 'MAT', 'from' => '10:00', 'minutes' => '20']);
        $gone = $this->downtime->report(['machine' => 'M1', 'shift' => 'DAY', 'reason' => 'BRK', 'from' => '12:00', 'minutes' => '99']);
        $this->downtime->void($gone, 'wrong', $this->user->id);

        $rows = collect(app(ShiftReport::class)->summary('2026-11-01', '2026-11-30'))->keyBy(fn ($r) => "{$r['date']} {$r['shift']}");
        expect($rows)->toHaveCount(2)
            ->and($rows['2026-11-04 DAY'])->toMatchArray(['produced' => '15', 'rejected' => '1', 'rework' => '2', 'rework_done' => '1', 'good' => '12', 'downtime_minutes' => '60', 'downtime_events' => 2, 'reports' => 3])
            ->and($rows['2026-11-03 NIGHT'])->toMatchArray(['produced' => '7', 'rejected' => '3', 'good' => '4', 'downtime_minutes' => '0']);

        $only = app(ShiftReport::class)->summary('2026-11-01', '2026-11-30', Shift::where('code', 'NIGHT')->value('id'));
        expect($only)->toHaveCount(1)->and($only[0]['shift'])->toBe('NIGHT');
        expect(app(ShiftReport::class)->summary('2026-12-01', '2026-12-31'))->toBe([]);

        $entries = app(ShiftReport::class)->entries('2026-11-04', Shift::where('code', 'DAY')->value('id'));
        expect($entries['reports'])->toHaveCount(4)->and($entries['downtime'])->toHaveCount(3);
    });
});

describe('the books stay right', function () {
    it('keeps the order\'s six quantities, the stock and work in progress in step through random reports, inspections, reworks, cancellations and receipts', function () {
        mt_srand(909);
        $order = ($this->started)('40');
        $made = $rej = $rew = $acc = $rewDone = $recv = 0;
        $live = [];

        for ($i = 0; $i < 80; $i++) {
            $order = $order->fresh();
            try {
                match (mt_rand(0, $this->user->id)) {
                    0, 1 => (function () use ($order, &$live) {
                        $p = mt_rand(1, 6);
                        $r = mt_rand(0, min(2, $p));
                        $w = mt_rand(0, min(1, $p - $r));
                        $live[] = [($this->make)($order, (string) $p, (string) $r, (string) $w), $p, $r, $w];
                    })(),
                    2 => $this->qc->inspect($order, ['accepted' => (string) mt_rand(0, 3), 'rejected' => (string) mt_rand(0, 1), 'rework' => (string) mt_rand(0, 1), 'reject_reason' => 'random']),
                    3 => $this->output->reworkDone($order, ['shift' => 'DAY', 'qty' => (string) mt_rand(1, 2)]),
                    4 => $this->goods->receive($order, ['qty' => (string) mt_rand(1, 3)]),
                    5 => $live !== [] ? $this->output->void(array_shift($live)[0], 'random', $this->user->id) : null,
                    default => null,
                };
            } catch (BusinessException $e) {
                expect($e->errorCode)->toBeIn(['manufacturing.over_production', 'manufacturing.inspection_empty', 'manufacturing.inspection_too_many', 'manufacturing.rework_too_much',
                    'manufacturing.receipt_too_much', 'manufacturing.report_in_use', 'manufacturing.qty_invalid', 'manufacturing.reason_required']);
            }
        }
        ($this->relay)();
        $o = $order->fresh();

        $reports = ProductionReport::where('order_id', $o->id)->whereNull('voided_at')->get();
        $inspections = DB::table('quality_inspections')->where('order_id', $o->id)->get();
        $sum = fn ($rows, $col) => $rows->reduce(fn ($c, $x) => Decimal::add($c, (string) $x->{$col}), '0');
        $out = $reports->where('kind', 'output');
        $back = $reports->where('kind', 'rework_done');

        expect((string) $o->reported_qty)->toBe(Decimal::round($sum($out, 'produced_qty'), 6))
            ->and((string) $o->accepted_qty)->toBe(Decimal::round($sum($inspections, 'accepted_qty'), 6))
            ->and((string) $o->rejected_qty)->toBe(Decimal::round(Decimal::add($sum($out, 'reject_qty'), $sum($inspections, 'rejected_qty')), 6))
            ->and((string) $o->rework_qty)->toBe(Decimal::round(Decimal::sub(Decimal::add($sum($out, 'rework_qty'), $sum($inspections, 'rework_qty')), $sum($back, 'produced_qty')), 6))
            ->and(Decimal::compare($o->pendingQty(), '0'))->toBeGreaterThanOrEqual(0)
            ->and(Decimal::compare((string) $o->received_qty, (string) $o->accepted_qty))->toBeLessThanOrEqual(0)
            ->and(invOnHand($this->shirt, $this->fg))->toBe(Decimal::round((string) $o->received_qty, 6));
        // finished goods in stock plus what the order still holds equal the material that went in
        expect(Decimal::round(Decimal::add(($this->bal)('1105'), (string) $o->fg_value), 4))->toBe('32400.0000');                      // 40 shirts x 810
        ($this->checks)();
    });
});
