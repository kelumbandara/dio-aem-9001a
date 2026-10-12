<?php

use App\Modules\Core\Services\OutboxRelay;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Services\StockReconciler;
use App\Modules\Manufacturing\Models\ProductionMaterialMovement;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\WorkCenter;
use App\Modules\Manufacturing\Services\BomService;
use App\Modules\Manufacturing\Services\MaterialService;
use App\Modules\Manufacturing\Services\ProductionOrderService;
use App\Modules\Manufacturing\Services\SetupService;
use App\Modules\MasterData\Models\Item;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    $this->wh = invWarehouse(['code' => 'RAW']);
    $this->fg = invWarehouse(['code' => 'FG']);
    $this->shirt = mdItem(['name' => 'Shirt', 'base_uom' => 'PCS']);
    $this->fabric = mdItem(['name' => 'Cotton fabric', 'base_uom' => 'KG']);
    $this->button = mdItem(['name' => 'Button', 'base_uom' => 'PCS']);
    mfgCenter('CUT', ['hourly_rate' => '500']);
    mfgCenter('SEW');
    $this->orders = app(ProductionOrderService::class);
    $this->material = app(MaterialService::class);
    $this->planner = userWithPermissions('manufacturing.order.release');
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
    $this->relay = fn () => app(OutboxRelay::class)->run(2000);
    $this->checks = function () {
        expect(app(FinanceReconciler::class)->discrepancies())->toBe([])->and(app(StockReconciler::class)->discrepancies())->toBe([]);
    };
    // shirt: 1.2 kg fabric (+5% loss) and 6 buttons each
    $this->bom = mfgBom($this->shirt, [['item' => $this->fabric, 'qty' => '1.2', 'scrap_pct' => '5'], ['item' => $this->button, 'qty' => '6']], [
        'operations' => [['name' => 'Cut', 'work_center' => 'CUT', 'setup_minutes' => '10', 'run_minutes' => '2'], ['name' => 'Sew', 'work_center' => 'SEW', 'run_minutes' => '8.5']],
    ]);
    $this->stock = function (string $fabricQty = '100', string $fabricCost = '400', string $buttonQty = '1000', string $buttonCost = '2') {
        invReceive($this->fabric, $this->wh, $fabricQty, $fabricCost);
        invReceive($this->button, $this->wh, $buttonQty, $buttonCost);
    };
});

describe('making the order', function () {
    it('works out the materials and routing from the active bill, and numbers the order', function () {
        $order = mfgOrder($this->shirt, '50', $this->wh);

        expect($order->order_no)->toMatch('#^MO/\d{4}-\d{2}/\d{5}$#')->and($order->status)->toBe('draft')->and($order->bom_version_id)->toBe($this->bom->id)
            ->and((string) $order->planned_qty)->toBe('50.000000')
            ->and([(string) $order->reported_qty, (string) $order->accepted_qty, (string) $order->rejected_qty, (string) $order->rework_qty, (string) $order->received_qty])->each->toBe('0.000000');
        // 50 x 1.2 x 1.05 = 63 kg; 50 x 6 = 300 buttons
        expect($order->materials->map(fn ($m) => [$m->item_id, (string) $m->required_qty, (string) $m->issued_qty])->all())
            ->toBe([[$this->fabric->id, '63.000000', '0.000000'], [$this->button->id, '300.000000', '0.000000']]);
        // setup once, run per unit: Cut 10 + 2 x 50 = 110 min; Sew 0 + 8.5 x 50 = 425 min
        expect($order->operations->map(fn ($o) => [$o->op_no, (string) $o->planned_setup_minutes, (string) $o->planned_run_minutes, $o->status])->all())->toBe([[10, '10.00', '100.0000', 'pending'], [20, '0.00', '425.0000', 'pending']]);
        expect(mfgOrder($this->shirt, '1', $this->wh)->order_no)->toEndWith('00002');
    });

    it('refuses an order that cannot be made', function (Closure $data, string $code) {
        $data = $data($this);
        mfgFails(fn () => $this->orders->save($data), $code);
        expect(ProductionOrder::count())->toBe(0);
    })->with([
        'a product without an active bill' => [fn ($t) => ['item' => $t->button->code, 'qty' => '1', 'source_warehouse_id' => $t->wh->id, 'output_warehouse_id' => $t->wh->id], 'manufacturing.no_active_bom'],
        'an unknown item' => [fn ($t) => ['item' => 'NOPE', 'qty' => '1', 'source_warehouse_id' => $t->wh->id, 'output_warehouse_id' => $t->wh->id], 'manufacturing.item_not_found'],
        'zero quantity' => [fn ($t) => ['item' => $t->shirt->code, 'qty' => '0', 'source_warehouse_id' => $t->wh->id, 'output_warehouse_id' => $t->wh->id], 'manufacturing.qty_invalid'],
        'a part of a whole piece' => [fn ($t) => ['item' => $t->shirt->code, 'qty' => '1.5', 'source_warehouse_id' => $t->wh->id, 'output_warehouse_id' => $t->wh->id], 'manufacturing.qty_invalid'],
        'finishing before it starts' => [fn ($t) => ['item' => $t->shirt->code, 'qty' => '1', 'planned_start' => '2026-12-10', 'planned_end' => '2026-12-09', 'source_warehouse_id' => $t->wh->id, 'output_warehouse_id' => $t->wh->id], 'manufacturing.dates_invalid'],
        'a bad date' => [fn ($t) => ['item' => $t->shirt->code, 'qty' => '1', 'planned_start' => '2026-13-45', 'source_warehouse_id' => $t->wh->id, 'output_warehouse_id' => $t->wh->id], 'manufacturing.date_invalid'],
        'no warehouse' => [fn ($t) => ['item' => $t->shirt->code, 'qty' => '1', 'output_warehouse_id' => $t->wh->id], 'manufacturing.warehouse_invalid'],
        'a production line that does not exist' => [fn ($t) => ['item' => $t->shirt->code, 'qty' => '1', 'line' => 'NOPE', 'source_warehouse_id' => $t->wh->id, 'output_warehouse_id' => $t->wh->id], 'manufacturing.line_invalid'],
    ]);

    it('rewrites a draft\'s materials when it is saved again and uses the bill that is active then', function () {
        $order = mfgOrder($this->shirt, '10', $this->wh);
        $v2 = $this->boms = app(BomService::class);
        $draft = $v2->copyToDraft($this->bom->fresh());
        $v2->save(['components' => [['item' => $this->fabric->code, 'qty' => '2'], ['item' => $this->button->code, 'qty' => '4']]], $draft);
        $v2->activate($draft->fresh());

        // the draft still points at version 1 until it is saved again
        expect($order->fresh()->bom_version_id)->toBe($this->bom->id);
        $order = $this->orders->save(['qty' => '10', 'source_warehouse_id' => $this->wh->id, 'output_warehouse_id' => $this->wh->id, 'version' => $order->version], $order);
        expect($order->bom_version_id)->toBe($draft->id)->and($order->materials->map(fn ($m) => (string) $m->required_qty)->all())->toBe(['20.000000', '40.000000'])
            ->and($order->version)->toBe(2);
        mfgFails(fn () => $this->orders->save(['qty' => '10', 'source_warehouse_id' => $this->wh->id, 'output_warehouse_id' => $this->wh->id, 'version' => 1], $order), 'concurrency.stale_version');
    });
});

describe('releasing', function () {
    it('releases a draft, reports shortages without refusing, and cannot be released twice', function () {
        invReceive($this->fabric, $this->wh, '50', '400');         // enough fabric for 50 shirts needs 63 kg: short; buttons: none at all
        $order = mfgOrder($this->shirt, '50', $this->wh);
        $result = $this->orders->release($order, $this->planner->id);

        expect($result['order']->status)->toBe('released')->and($result['order']->released_at)->not->toBeNull()->and($result['order']->released_by)->toBe($this->planner->id)
            ->and($result['shortages'])->toBe([
                ['item_id' => $this->fabric->id, 'required' => '63.000000', 'available' => '50.000000'],
                ['item_id' => $this->button->id, 'required' => '300.000000', 'available' => '0.000000'],
            ]);
        mfgFails(fn () => $this->orders->release($order->fresh(), $this->planner->id), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->orders->save(['qty' => '5', 'source_warehouse_id' => $this->wh->id, 'output_warehouse_id' => $this->wh->id], $order->fresh()), 'manufacturing.invalid_state');
    });

    it('refuses to release an order whose bill has been replaced, and keeps a released order on its own version', function () {
        $draftOrder = mfgOrder($this->shirt, '10', $this->wh);
        $releasedOrder = mfgReleased($this->shirt, '10', $this->wh);

        $v2 = app(BomService::class);
        $copy = $v2->copyToDraft($this->bom->fresh());
        $v2->save(['components' => [['item' => $this->fabric->code, 'qty' => '3']]], $copy);
        $v2->activate($copy->fresh());

        mfgFails(fn () => $this->orders->release($draftOrder->fresh(), $this->planner->id), 'manufacturing.bom_replaced');
        expect($releasedOrder->fresh()->bom_version_id)->toBe($this->bom->id)->and((string) $releasedOrder->fresh()->materials->first()->required_qty)->toBe('12.600000');
    });

    it('refuses to release when a step\'s work centre or machine has been switched off', function () {
        $order = mfgOrder($this->shirt, '10', $this->wh);
        app(SetupService::class)->save('work-centers', ['name' => 'Cutting', 'capacity_hours_per_day' => '8', 'hourly_rate' => '500', 'is_active' => false], WorkCenter::where('code', 'CUT')->first());
        mfgFails(fn () => $this->orders->release($order, $this->planner->id), 'manufacturing.routing_unavailable');
    });
});

describe('issuing and returning materials', function () {
    it('takes stock out into work in progress at its average cost, and the books agree', function () {
        ($this->stock)('100', '400');
        $order = mfgReleased($this->shirt, '50', $this->wh);

        $result = $this->material->issue($order, [['material' => 1, 'qty' => '40'], ['item' => $this->button->code, 'qty' => '300']], $this->planner->id);
        ($this->relay)();

        $fabric = $order->materials()->where('line_no', 1)->first();
        $buttons = $order->materials()->where('line_no', 2)->first();
        expect($result['document_no'])->toStartWith('SIS/')->and($order->fresh()->status)->toBe('in_progress')
            ->and((string) $fabric->issued_qty)->toBe('40.000000')->and((string) $fabric->issued_value)->toBe('16000.0000')
            ->and((string) $buttons->issued_value)->toBe('600.0000')
            ->and(invOnHand($this->fabric, $this->wh))->toBe('60.000000')->and(invOnHand($this->button, $this->wh))->toBe('700.000000')
            ->and(($this->bal)('1105'))->toBe('16600.0000');              // work in progress
        expect(($this->bal)('1100'))->toBe(Decimal::round(Decimal::sub('42000', '16600'), 4))
            ->and($order->movements()->count())->toBe(2)->and($order->movements->pluck('stock_document_id')->unique()->count())->toBe(1);
        ($this->checks)();
    });

    it('puts returned stock back at the cost it left at, even when the average has moved', function () {
        ($this->stock)('100', '400');
        $order = mfgReleased($this->shirt, '50', $this->wh);
        $this->material->issue($order, [['material' => 1, 'qty' => '40']]);                 // 40 kg at 400
        invReceive($this->fabric, $this->wh, '60', '1000');                                // the average cost jumps: (60*400 + 60*1000) / 120 = 700

        $this->material->return($order->fresh(), [['material' => 1, 'qty' => '10']], $this->planner->id, 'cut too much');
        ($this->relay)();
        $m = $order->materials()->where('line_no', 1)->first();

        // 10 kg come back at 400 (what they were charged), not at today's 700
        expect((string) $m->returned_qty)->toBe('10.000000')->and((string) $m->returned_value)->toBe('4000.0000')->and(Decimal::round($m->netQty(), 6))->toBe('30.000000')
            ->and(Decimal::round(Decimal::sub((string) $m->issued_value, (string) $m->returned_value), 4))->toBe('12000.0000')
            ->and(($this->bal)('1105'))->toBe('12000.0000');
        expect(invOnHand($this->fabric, $this->wh))->toBe('130.000000');
        ($this->checks)();

        // the rest comes back at the same unit cost, leaving nothing in work in progress for this material
        $this->material->return($order->fresh(), [['material' => 1, 'qty' => '30']], $this->planner->id, 'order stopped');
        ($this->relay)();
        expect((string) $m->fresh()->returned_value)->toBe('16000.0000')->and(($this->bal)('1105'))->toBe('0.0000');
        ($this->checks)();
    });

    it('refuses more than the order needs unless someone with permission gives a reason, and marks it', function () {
        ($this->stock)();
        $order = mfgReleased($this->shirt, '50', $this->wh);                                // needs 63 kg

        mfgFails(fn () => $this->material->issue($order, [['material' => 1, 'qty' => '63.001']], null, false), 'manufacturing.over_issue');
        expect(invOnHand($this->fabric, $this->wh))->toBe('100.000000')->and(ProductionMaterialMovement::count())->toBe(0);

        $this->material->issue($order, [['material' => 1, 'qty' => '60']], null, false);   // within the requirement: fine
        mfgFails(fn () => $this->material->issue($order->fresh(), [['material' => 1, 'qty' => '5']], null, false), 'manufacturing.over_issue');   // 65 in all
        mfgFails(fn () => $this->material->issue($order->fresh(), [['material' => 1, 'qty' => '5']], null, true), 'manufacturing.over_issue_reason');
        $this->material->issue($order->fresh(), [['material' => 1, 'qty' => '5']], $this->planner->id, true, 'sleeves recut');

        $moves = ProductionMaterialMovement::orderBy('id')->get();
        expect($moves->pluck('over_issue')->all())->toBe([false, true])->and($moves->last()->reason)->toBe('sleeves recut')
            ->and((string) $order->materials()->first()->issued_qty)->toBe('65.000000');
    });

    it('allows the percentage the settings allow on top of the requirement', function () {
        config(['manufacturing.over_issue_pct' => '2']);
        ($this->stock)();
        $order = mfgReleased($this->shirt, '50', $this->wh);                                // 63 kg, so up to 64.26 without permission
        $this->material->issue($order, [['material' => 1, 'qty' => '64.26']]);
        mfgFails(fn () => $this->material->issue($order->fresh(), [['material' => 1, 'qty' => '0.01']]), 'manufacturing.over_issue');
    });

    it('limits a return to what the order holds and needs a reason', function () {
        ($this->stock)();
        $order = mfgReleased($this->shirt, '50', $this->wh);
        $this->material->issue($order, [['material' => 1, 'qty' => '20']]);

        mfgFails(fn () => $this->material->return($order->fresh(), [['material' => 1, 'qty' => '20.001']], null, 'x'), 'manufacturing.return_too_much');
        mfgFails(fn () => $this->material->return($order->fresh(), [['material' => 2, 'qty' => '1']], null, 'x'), 'manufacturing.return_too_much');   // buttons were never issued
        mfgFails(fn () => $this->material->return($order->fresh(), [['material' => 1, 'qty' => '5']], null, '  '), 'manufacturing.reason_required');
        expect(invOnHand($this->fabric, $this->wh))->toBe('80.000000');
    });

    it('refuses badly formed lines without posting anything', function (Closure $lines, string $code) {
        ($this->stock)();
        $order = mfgReleased($this->shirt, '50', $this->wh);
        $before = StockDocument::count();
        mfgFails(fn () => $this->material->issue($order, $lines($this)), $code);
        expect(StockDocument::count())->toBe($before)->and(ProductionMaterialMovement::count())->toBe(0);
    })->with([
        'no lines' => [fn ($t) => [], 'manufacturing.lines_invalid'],
        'a material that is not on the order' => [fn ($t) => [['material' => 9, 'qty' => '1']], 'manufacturing.material_not_on_order'],
        'an item that is not on the order' => [fn ($t) => [['item' => $t->shirt->code, 'qty' => '1']], 'manufacturing.material_not_on_order'],
        'the same material twice' => [fn ($t) => [['material' => 1, 'qty' => '1'], ['item' => $t->fabric->code, 'qty' => '1']], 'manufacturing.material_twice'],
        'zero' => [fn ($t) => [['material' => 1, 'qty' => '0']], 'manufacturing.qty_invalid'],
        'too many decimals for the unit' => [fn ($t) => [['material' => 2, 'qty' => '1.5']], 'manufacturing.qty_invalid'],
        'not a number' => [fn ($t) => [['material' => 1, 'qty' => 'lots']], 'manufacturing.qty_invalid'],
    ]);

    it('posts nothing at all when the warehouse does not have enough for one of the lines', function () {
        invReceive($this->fabric, $this->wh, '100', '400');                                 // no buttons
        $order = mfgReleased($this->shirt, '50', $this->wh);
        $docs = StockDocument::count();

        expect(fn () => $this->material->issue($order, [['material' => 1, 'qty' => '10'], ['material' => 2, 'qty' => '5']]))->toThrow(BusinessException::class);

        expect(StockDocument::count())->toBe($docs)->and(invOnHand($this->fabric, $this->wh))->toBe('100.000000')
            ->and((string) $order->materials()->first()->issued_qty)->toBe('0.000000')->and(ProductionMaterialMovement::count())->toBe(0)->and($order->fresh()->status)->toBe('released');
    });

    it('works only on a released order that is not finished or cancelled', function () {
        ($this->stock)();
        $draft = mfgOrder($this->shirt, '10', $this->wh);
        mfgFails(fn () => $this->material->issue($draft, [['material' => 1, 'qty' => '1']]), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->material->return($draft, [['material' => 1, 'qty' => '1']], null, 'x'), 'manufacturing.invalid_state');

        $order = mfgReleased($this->shirt, '10', $this->wh);
        $this->orders->cancel($order, 'not needed', 1);
        mfgFails(fn () => $this->material->issue($order->fresh(), [['material' => 1, 'qty' => '1']]), 'manufacturing.invalid_state');
    });

    it('keeps every issue and return as it was posted and refuses to take a production document back by reversal', function () {
        ($this->stock)();
        $order = mfgReleased($this->shirt, '10', $this->wh);
        $this->material->issue($order, [['material' => 1, 'qty' => '5']], $this->planner->id);
        $move = ProductionMaterialMovement::sole();

        foreach ([fn () => DB::table('production_material_movements')->where('id', $move->id)->update(['qty' => '1']), fn () => DB::table('production_material_movements')->where('id', $move->id)->delete()] as $sql) {
            expect(fn () => DB::transaction($sql))->toThrow(Exception::class, 'not allowed');
        }

        $document = StockDocument::findOrFail($move->stock_document_id);
        expect($document->source_type)->toBe('production_issue')
            ->and(userWithPermissions('inventory.reverse', 'inventory.issue', 'inventory.stock.view')->can('reverse', $document))->toBeFalse();
    });
});

describe('cancelling', function () {
    it('cancels a draft or released order, but not while material is still with it', function () {
        ($this->stock)();
        $draft = mfgOrder($this->shirt, '10', $this->wh);
        expect($this->orders->cancel($draft, 'wrong product', 1)->status)->toBe('cancelled');
        mfgFails(fn () => $this->orders->cancel($draft->fresh(), 'again', 1), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->orders->cancel(mfgOrder($this->shirt, '10', $this->wh), '  ', 1), 'manufacturing.reason_required');

        $order = mfgReleased($this->shirt, '10', $this->wh);
        $this->material->issue($order, [['material' => 1, 'qty' => '5']]);
        mfgFails(fn () => $this->orders->cancel($order->fresh(), 'stopped', 1), 'manufacturing.material_outstanding');

        $this->material->return($order->fresh(), [['material' => 1, 'qty' => '5']], null, 'order stopped');
        $cancelled = $this->orders->cancel($order->fresh(), 'stopped', 1);
        expect($cancelled->status)->toBe('cancelled')->and($cancelled->cancel_reason)->toBe('stopped');
    });
});

describe('the books stay right', function () {
    it('keeps stock, work in progress and the movement records in step through random issues and returns', function () {
        mt_srand(808);
        $items = [$this->fabric, $this->button, mdItem(['name' => 'Lining', 'base_uom' => 'KG']), mdItem(['name' => 'Label', 'base_uom' => 'PCS'])];
        foreach ($items as $i => $item) {
            invReceive($item, $this->wh, '100000', (string) (3 + $i * 7).'.'.(string) (11 * $i + 5));
        }
        $product = mdItem(['name' => 'Jacket', 'base_uom' => 'PCS']);
        mfgBom($product, array_map(fn ($it) => ['item' => $it, 'qty' => (string) mt_rand(1, 9).'.'.mt_rand(10, 99), 'scrap_pct' => (string) mt_rand(0, 10)], $items));
        $order = mfgReleased($product, '500', $this->wh);

        for ($i = 0; $i < 60; $i++) {
            $m = $order->materials()->where('line_no', mt_rand(1, 4))->first();
            $dec = $m->item_id === $items[0]->id || $m->item_id === $items[2]->id ? 3 : 0;
            $qty = Decimal::trim(Decimal::round((string) (mt_rand(1, 4000) / 100), $dec));
            if (Decimal::compare($qty, '0') <= 0) {
                continue;
            }
            try {
                if (mt_rand(0, 2) > 0) {
                    $this->material->issue($order, [['material' => $m->line_no, 'qty' => $qty]], null, true, 'test');
                } else {
                    $this->material->return($order->fresh(), [['material' => $m->line_no, 'qty' => $qty]], null, 'test');
                }
            } catch (BusinessException $e) {
                expect($e->errorCode)->toBe('manufacturing.return_too_much');   // the only refusal this sequence may meet
            }
        }
        ($this->relay)();

        $wip = '0';
        foreach ($order->materials()->get() as $m) {
            $moves = ProductionMaterialMovement::where('material_id', $m->id)->get();
            $issued = $moves->where('kind', 'issue')->reduce(fn ($c, $x) => Decimal::add($c, (string) $x->qty), '0');
            $returned = $moves->where('kind', 'return')->reduce(fn ($c, $x) => Decimal::add($c, (string) $x->qty), '0');
            $value = fn ($kind) => $moves->where('kind', $kind)->reduce(fn ($c, $x) => Decimal::add($c, (string) $x->value), '0');

            expect((string) $m->issued_qty)->toBe(Decimal::round($issued, 6))->and((string) $m->returned_qty)->toBe(Decimal::round($returned, 6))
                ->and((string) $m->issued_value)->toBe(Decimal::round($value('issue'), 4))->and((string) $m->returned_value)->toBe(Decimal::round($value('return'), 4))
                ->and(Decimal::compare($m->netQty(), '0'))->toBeGreaterThanOrEqual(0)
                // stock on hand = what was received less what the order holds
                ->and(invOnHand(Item::find($m->item_id), $this->wh))->toBe(Decimal::round(Decimal::sub('100000', $m->netQty()), 6));
            $wip = Decimal::add($wip, Decimal::sub((string) $m->issued_value, (string) $m->returned_value));
        }
        // the ledger's work in progress is exactly what the order says it holds
        expect(($this->bal)('1105'))->toBe(Decimal::round($wip, 4));
        ($this->checks)();
    });
});
