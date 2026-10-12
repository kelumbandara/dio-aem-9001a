<?php

use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Services\BomQueries;
use App\Modules\Manufacturing\Services\BomService;
use App\Modules\Manufacturing\Services\SetupService;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->boms = app(BomService::class);
    $this->queries = app(BomQueries::class);
    $this->setup = app(SetupService::class);
    $this->shirt = mdItem(['name' => 'Shirt', 'base_uom' => 'PCS']);
    $this->fabric = mdItem(['name' => 'Cotton fabric', 'base_uom' => 'KG']);
    $this->thread = mdItem(['name' => 'Thread', 'base_uom' => 'KG']);
    $this->button = mdItem(['name' => 'Button', 'base_uom' => 'PCS']);
});

describe('work centres, lines, machines and shifts', function () {
    it('saves each kind, upper-cases the code and refuses a code used twice', function () {
        $wc = $this->setup->save('work-centers', ['code' => 'cut-1', 'name' => 'Cutting', 'capacity_hours_per_day' => '7.5', 'hourly_rate' => '650.25']);
        expect($wc->code)->toBe('CUT-1')->and((string) $wc->hourly_rate)->toBe('650.2500')->and($wc->is_active)->toBeTrue();
        $line = $this->setup->save('lines', ['code' => 'L1', 'name' => 'Line one']);
        $machine = $this->setup->save('machines', ['code' => 'm-01', 'name' => 'Cutter', 'work_center' => 'cut-1', 'production_line' => 'l1', 'status' => 'available']);
        expect($machine->work_center_id)->toBe($wc->id)->and($machine->production_line_id)->toBe($line->id);

        expect(fn () => $this->setup->save('work-centers', ['code' => 'CUT-1', 'name' => 'Again', 'capacity_hours_per_day' => '8', 'hourly_rate' => '1']))->toThrow(ValidationException::class);
        $this->setup->save('work-centers', ['name' => 'Cutting dept', 'capacity_hours_per_day' => '8', 'hourly_rate' => '700', 'is_active' => false], $wc);   // edit keeps its own code
        expect($wc->fresh()->name)->toBe('Cutting dept')->and($wc->fresh()->is_active)->toBeFalse();
    });

    it('validates what each kind needs', function () {
        mfgCenter('A');
        $bad = [
            ['work-centers', ['code' => 'X', 'name' => 'X', 'capacity_hours_per_day' => '0', 'hourly_rate' => '1']],
            ['work-centers', ['code' => 'X', 'name' => 'X', 'capacity_hours_per_day' => '25', 'hourly_rate' => '1']],
            ['work-centers', ['code' => 'X', 'name' => 'X', 'capacity_hours_per_day' => '8', 'hourly_rate' => '-1']],
            ['work-centers', ['code' => 'bad code!', 'name' => 'X', 'capacity_hours_per_day' => '8', 'hourly_rate' => '1']],
            ['machines', ['code' => 'M', 'name' => 'M', 'work_center' => 'NOPE', 'status' => 'available']],
            ['machines', ['code' => 'M', 'name' => 'M', 'work_center' => 'A', 'status' => 'broken']],
            ['shifts', ['code' => 'S', 'name' => 'S', 'start_time' => '08:00', 'end_time' => '08:00', 'days' => '1111100']],
            ['shifts', ['code' => 'S', 'name' => 'S', 'start_time' => '8am', 'end_time' => '16:00', 'days' => '1111100']],
            ['shifts', ['code' => 'S', 'name' => 'S', 'start_time' => '08:00', 'end_time' => '16:00', 'days' => '111']],
        ];
        foreach ($bad as [$entity, $data]) {
            expect(fn () => $this->setup->save($entity, $data))->toThrow(ValidationException::class);
        }
        // a night shift ends "earlier" than it starts
        $night = $this->setup->save('shifts', ['code' => 'N', 'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'days' => '1111100']);
        expect(substr((string) $night->start_time, 0, 5))->toBe('22:00')->and($night->days)->toBe('1111100');
        expect(DB::table('work_centers')->count())->toBe(1);
    });
});

describe('writing a bill of materials', function () {
    it('saves a draft with numbered components and routing, in order', function () {
        mfgCenter('CUT');
        mfgCenter('SEW');
        $this->setup->save('machines', ['code' => 'M1', 'name' => 'Sewing machine', 'work_center' => 'SEW', 'status' => 'available']);
        $v = mfgDraft($this->shirt, [['item' => $this->fabric, 'qty' => '1.25', 'scrap_pct' => '4'], ['item' => $this->button, 'qty' => '6']], [
            'base_qty' => '1', 'description' => 'Plain shirt',
            'operations' => [['name' => 'Sew', 'work_center' => 'sew', 'machine' => 'm1', 'setup_minutes' => '15', 'run_minutes' => '12.5'], ['name' => 'Cut', 'work_center' => 'CUT', 'op_no' => '5', 'run_minutes' => '3']],
        ]);

        expect($v->status)->toBe('draft')->and($v->version_no)->toBe(1)->and((string) $v->base_qty)->toBe('1.000000');
        expect($v->components->map(fn ($c) => [$c->line_no, $c->component_item_id, (string) $c->qty, (string) $c->scrap_pct])->all())
            ->toBe([[1, $this->fabric->id, '1.250000', '4.000'], [2, $this->button->id, '6.000000', '0.000']]);
        // steps are kept in step-number order; a step without a number takes the next tens position
        expect($v->operations->map(fn ($o) => [$o->op_no, $o->name, (string) $o->setup_minutes, (string) $o->run_minutes])->all())->toBe([[5, 'Cut', '0.00', '3.0000'], [10, 'Sew', '15.00', '12.5000']]);
        expect($v->operations->last()->machine_id)->toBe(Machine::where('code', 'M1')->value('id'));
    });

    it('refuses what cannot be a good bill', function (Closure $payload, string $code) {
        mfgCenter('CUT');
        mfgCenter('SEW');
        $this->setup->save('machines', ['code' => 'M1', 'name' => 'Sewing machine', 'work_center' => 'SEW', 'status' => 'available']);
        $this->setup->save('work-centers', ['code' => 'OLD', 'name' => 'Old', 'capacity_hours_per_day' => '8', 'hourly_rate' => '0', 'is_active' => false]);
        $service = mdItem(['name' => 'Tailoring', 'item_type' => 'service', 'base_uom' => 'HR', 'is_purchasable' => false]);
        $data = $payload($this, $service);
        mfgFails(fn () => $this->boms->save($data), $code);
        expect(BomVersion::count())->toBe(0);
    })->with([
        'no components' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => []], 'manufacturing.components_invalid'],
        'the product itself' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->shirt->code, 'qty' => '1']]], 'manufacturing.component_self'],
        'the same item twice' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1'], ['item' => $t->fabric->code, 'qty' => '2']]], 'manufacturing.component_duplicate'],
        'a service as component' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $svc->code, 'qty' => '1']]], 'manufacturing.component_invalid'],
        'a service as product' => [fn ($t, $svc) => ['item' => $svc->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1']]], 'manufacturing.item_not_stocked'],
        'an unknown item' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => 'NOPE-1', 'qty' => '1']]], 'manufacturing.item_not_found'],
        'no product' => [fn ($t, $svc) => ['components' => [['item' => $t->fabric->code, 'qty' => '1']]], 'manufacturing.item_not_found'],
        'zero quantity' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '0']]], 'manufacturing.qty_invalid'],
        'too many decimals' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1.1234567']]], 'manufacturing.qty_invalid'],
        'zero makes' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'base_qty' => '0', 'components' => [['item' => $t->fabric->code, 'qty' => '1']]], 'manufacturing.qty_invalid'],
        'scrap 100' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1', 'scrap_pct' => '100']]], 'manufacturing.scrap_invalid'],
        'negative scrap' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1', 'scrap_pct' => '-1']]], 'manufacturing.scrap_invalid'],
        'a unit the item does not have' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1', 'uom' => 'BOX']]], 'manufacturing.uom_invalid'],
        'step without a name' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1']], 'operations' => [['name' => '', 'work_center' => 'CUT']]], 'manufacturing.op_name_required'],
        'step at an unknown work centre' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1']], 'operations' => [['name' => 'Cut', 'work_center' => 'NOPE']]], 'manufacturing.work_center_invalid'],
        'step at a switched-off work centre' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1']], 'operations' => [['name' => 'Cut', 'work_center' => 'OLD']]], 'manufacturing.work_center_invalid'],
        'machine of another work centre' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1']], 'operations' => [['name' => 'Cut', 'work_center' => 'CUT', 'machine' => 'M1']]], 'manufacturing.machine_invalid'],
        'two steps with one number' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1']], 'operations' => [['name' => 'A', 'work_center' => 'CUT', 'op_no' => '10'], ['name' => 'B', 'work_center' => 'CUT', 'op_no' => '10']]], 'manufacturing.op_no_invalid'],
        'negative minutes' => [fn ($t, $svc) => ['item' => $t->shirt->code, 'components' => [['item' => $t->fabric->code, 'qty' => '1']], 'operations' => [['name' => 'A', 'work_center' => 'CUT', 'run_minutes' => '-1']]], 'manufacturing.minutes_invalid'],
    ]);

    it('measures a component in any unit the item has', function () {
        $flour = mdItem(['name' => 'Flour', 'base_uom' => 'G', 'uoms' => [['uom' => 'KG', 'factor' => '1000']]]);
        $bread = mdItem(['name' => 'Bread', 'base_uom' => 'PCS']);
        $v = mfgBom($bread, [['item' => $flour, 'qty' => '2.5', 'uom' => 'kg']], ['base_qty' => '10']);

        // 2.5 kg of flour for 10 loaves = 2,500 g; for 4 loaves = 1,000 g
        expect($this->queries->requirements($v, '4'))->toBe([['line_no' => 1, 'item_id' => $flour->id, 'qty' => '1000.00', 'exact' => '1000.000000000000']]);
    });
});

describe('versions', function () {
    it('keeps one draft at a time, one active version, and retires the old one when the new one is activated', function () {
        $v1 = mfgDraft($this->shirt, [['item' => $this->fabric, 'qty' => '1']]);
        mfgFails(fn () => mfgDraft($this->shirt, [['item' => $this->fabric, 'qty' => '2']]), 'manufacturing.draft_exists');

        $this->boms->activate($v1);
        expect($v1->fresh()->status)->toBe('active')->and($v1->fresh()->effective_from)->not->toBeNull()->and($this->queries->activeFor($this->shirt->id)->id)->toBe($v1->id);

        $v2 = $this->boms->copyToDraft($v1->fresh());
        expect($v2->version_no)->toBe(2)->and($v2->status)->toBe('draft')->and($v2->components->pluck('component_item_id')->all())->toBe([$this->fabric->id]);
        $this->boms->save(['components' => [['item' => $this->fabric->code, 'qty' => '1.5'], ['item' => $this->thread->code, 'qty' => '0.01']]], $v2);
        $this->boms->activate($v2->fresh());

        expect($v1->fresh()->status)->toBe('retired')->and($v1->fresh()->effective_to)->not->toBeNull()
            ->and($this->queries->activeFor($this->shirt->id)->id)->toBe($v2->id)
            ->and(BomVersion::where('item_id', $this->shirt->id)->where('status', 'active')->count())->toBe(1);
        // the retired version still shows what it was
        expect((string) $v1->fresh()->components->first()->qty)->toBe('1.000000');
    });

    it('never changes an active or retired version, in the service or in the database', function () {
        $v = mfgBom($this->shirt, [['item' => $this->fabric, 'qty' => '1']]);
        mfgFails(fn () => $this->boms->save(['components' => [['item' => $this->fabric->code, 'qty' => '9']]], $v), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->boms->deleteDraft($v), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->boms->activate($v->fresh()), 'manufacturing.invalid_state');

        $try = fn (Closure $sql) => expect(fn () => DB::transaction($sql))->toThrow(Exception::class);
        $try(fn () => DB::table('bom_components')->where('bom_version_id', $v->id)->update(['qty' => '9']));
        $try(fn () => DB::table('bom_components')->where('bom_version_id', $v->id)->delete());
        $try(fn () => DB::table('bom_components')->insert(['bom_version_id' => $v->id, 'line_no' => 9, 'component_item_id' => $this->thread->id, 'qty' => '1', 'uom_id' => $this->thread->base_uom_id, 'scrap_pct' => 0]));
        $try(fn () => DB::table('bom_versions')->where('id', $v->id)->update(['base_qty' => '5']));
        $try(fn () => DB::table('bom_versions')->where('id', $v->id)->delete());
        $try(fn () => DB::table('bom_versions')->where('id', $v->id)->update(['status' => 'draft']));

        $this->boms->retire($v->fresh());
        $try(fn () => DB::table('bom_versions')->where('id', $v->id)->update(['status' => 'active']));
        mfgFails(fn () => $this->boms->activate($v->fresh()), 'manufacturing.invalid_state');
        mfgFails(fn () => $this->boms->retire($v->fresh()), 'manufacturing.invalid_state');
        expect($this->queries->activeFor($this->shirt->id))->toBeNull();
    });

    it('lets the database itself refuse a second active version of a product', function () {
        mfgBom($this->shirt, [['item' => $this->fabric, 'qty' => '1']]);
        expect(fn () => DB::transaction(fn () => DB::table('bom_versions')->insert([
            'public_id' => (string) Str::uuid(), 'item_id' => $this->shirt->id, 'version_no' => 2, 'status' => 'active', 'base_qty' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(Exception::class);
    });

    it('deletes a draft and nothing else', function () {
        $v = mfgDraft($this->shirt, [['item' => $this->fabric, 'qty' => '1']], ['operations' => []]);
        $this->boms->deleteDraft($v);
        expect(BomVersion::count())->toBe(0)->and(DB::table('bom_components')->count())->toBe(0);
    });

    it('refuses to save a draft that someone else has changed meanwhile', function () {
        $v = mfgDraft($this->shirt, [['item' => $this->fabric, 'qty' => '1']]);
        $this->boms->save(['components' => [['item' => $this->fabric->code, 'qty' => '2']], 'version' => $v->version], $v);
        mfgFails(fn () => $this->boms->save(['components' => [['item' => $this->fabric->code, 'qty' => '3']], 'version' => $v->version], $v), 'concurrency.stale_version');
    });
});

describe('a product can never contain itself', function () {
    it('refuses a direct loop and a loop through two levels', function () {
        $a = mdItem(['name' => 'A', 'base_uom' => 'PCS']);
        $b = mdItem(['name' => 'B', 'base_uom' => 'PCS']);
        $c = mdItem(['name' => 'C', 'base_uom' => 'PCS']);
        mfgBom($a, [['item' => $b, 'qty' => '1']]);                 // A needs B
        mfgBom($b, [['item' => $c, 'qty' => '1']]);                 // B needs C
        mfgFails(fn () => mfgDraft($c, [['item' => $a, 'qty' => '1']]), 'manufacturing.bom_loop');   // C needing A would close the circle
        mfgFails(fn () => mfgDraft($b, [['item' => $a, 'qty' => '1']]), 'manufacturing.bom_loop');   // and so would B needing A
        mfgDraft($c, [['item' => $this->fabric, 'qty' => '1']]);   // C from raw material is fine
        expect(BomVersion::count())->toBe(3);
    });

    it('catches a loop that only appears when two drafts are activated', function () {
        $a = mdItem(['name' => 'A', 'base_uom' => 'PCS']);
        $b = mdItem(['name' => 'B', 'base_uom' => 'PCS']);
        $da = mfgDraft($a, [['item' => $b, 'qty' => '1']]);          // neither has an active bill yet, so both drafts can be written
        $db = mfgDraft($b, [['item' => $a, 'qty' => '1']]);
        $this->boms->activate($da);
        mfgFails(fn () => $this->boms->activate($db), 'manufacturing.bom_loop');
        expect($db->fresh()->status)->toBe('draft')->and($this->queries->activeFor($b->id))->toBeNull();
    });

    it('stops an indented bill that has been corrupted into a loop', function () {
        $a = mdItem(['name' => 'A', 'base_uom' => 'PCS']);
        $b = mdItem(['name' => 'B', 'base_uom' => 'PCS']);
        mfgBom($a, [['item' => $b, 'qty' => '1']]);
        // bypass every rule: put a second active bill in by hand, B needing A
        $id = DB::table('bom_versions')->insertGetId(['public_id' => (string) Str::uuid(), 'item_id' => $b->id, 'version_no' => 1, 'status' => 'draft', 'base_qty' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bom_components')->insert(['bom_version_id' => $id, 'line_no' => 1, 'component_item_id' => $a->id, 'qty' => 1, 'uom_id' => $a->base_uom_id, 'scrap_pct' => 0]);
        DB::table('bom_versions')->where('id', $id)->update(['status' => 'active']);

        mfgFails(fn () => $this->queries->explode($a->id, '1'), 'manufacturing.bom_loop');
    });
});

describe('what a quantity of the product needs', function () {
    it('works out each component exactly, rounding once to the component\'s unit', function () {
        $v = mfgBom($this->shirt, [['item' => $this->fabric, 'qty' => '1.25', 'scrap_pct' => '4'], ['item' => $this->thread, 'qty' => '0.0075'], ['item' => $this->button, 'qty' => '6', 'scrap_pct' => '2.5']], ['base_qty' => '1']);
        $dec = fn ($item) => app(ItemCatalog::class)->find($item->id)->baseUomDecimals;

        // 25 shirts: fabric 25 x 1.25 x 1.04 = 32.5 kg; thread 25 x 0.0075 = 0.1875 -> 0.188 (kg has 3 decimals); buttons 25 x 6 x 1.025 = 153.75 -> 154 (pieces are whole)
        $r = collect($this->queries->requirements($v, '25'))->keyBy('item_id');
        expect($r[$this->fabric->id]['qty'])->toBe(Decimal::round('32.5', $dec($this->fabric)))
            ->and($r[$this->thread->id]['qty'])->toBe(Decimal::round('0.1875', $dec($this->thread)))
            ->and($r[$this->button->id]['qty'])->toBe(Decimal::round('153.75', $dec($this->button)))
            ->and($r[$this->button->id]['exact'])->toBe('153.750000000000');
    });

    it('agrees with an independent calculation for random bills and quantities', function () {
        mt_srand(2026);
        $mat = [$this->fabric, $this->thread, mdItem(['name' => 'Zip', 'base_uom' => 'KG']), mdItem(['name' => 'Foam', 'base_uom' => 'KG'])];
        for ($round = 0; $round < 25; $round++) {
            $product = mdItem(['name' => "P{$round}", 'base_uom' => 'PCS']);
            $base = (string) mt_rand(1, 50);
            $lines = [];
            foreach (array_slice($mat, 0, mt_rand(1, 4)) as $m) {
                $lines[] = ['item' => $m, 'qty' => mt_rand(1, 9999).'.'.str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT), 'scrap_pct' => mt_rand(0, 99).'.'.mt_rand(0, 999)];
            }
            $v = mfgBom($product, array_map(fn ($l) => ['qty' => Decimal::trim(Decimal::round($l['qty'], 6))] + $l, $lines), ['base_qty' => $base]);
            $q = (string) mt_rand(1, 500);

            foreach ($this->queries->requirements($v, $q) as $row) {
                $line = collect($lines)->first(fn ($l) => $l['item']->id === $row['item_id']);
                $exact = bcmul(bcdiv(bcmul(Decimal::trim(Decimal::round($line['qty'], 6)), $q, 12), $base, 12), bcadd('1', bcdiv(Decimal::round($line['scrap_pct'], 3), '100', 12), 12), 12);
                expect($row['exact'])->toBe($exact)
                    ->and($row['qty'])->toBe(Decimal::round($exact, app(ItemCatalog::class)->find($row['item_id'])->baseUomDecimals));
            }
        }
    });

    it('goes down through sub-assemblies and totals what has to be bought', function () {
        $sleeve = mdItem(['name' => 'Sleeve', 'base_uom' => 'PCS']);
        mfgBom($sleeve, [['item' => $this->fabric, 'qty' => '0.25'], ['item' => $this->thread, 'qty' => '0.002']]);              // 1 sleeve
        $shirt = mfgBom($this->shirt, [['item' => $sleeve, 'qty' => '2'], ['item' => $this->fabric, 'qty' => '1'], ['item' => $this->button, 'qty' => '6']]);

        $rows = $this->queries->explode($this->shirt->id, '10');
        expect(collect($rows)->map(fn ($r) => [$r['level'], $r['item_id'], $r['qty'], $r['leaf']])->all())->toBe([
            [1, $sleeve->id, '20.000000', false], [2, $this->fabric->id, '5.000000', true], [2, $this->thread->id, '0.040000', true],
            [1, $this->fabric->id, '10.000000', true], [1, $this->button->id, '60.000000', true],
        ]);
        // fabric is needed twice (in the sleeves and in the body): 5 + 10
        expect($this->queries->leafTotals($rows))->toBe([$this->fabric->id => '15.000000', $this->thread->id => '0.040000', $this->button->id => '60.000000']);

        $used = $this->queries->whereUsed($this->fabric->id);
        expect(collect($used)->pluck('item_id')->sort()->values()->all())->toBe(collect([$sleeve->id, $this->shirt->id])->sort()->values()->all());
        expect($shirt->status)->toBe('active');
    });

    it('treats a component without an active bill as something to buy, and a retired bill as none', function () {
        $sleeve = mdItem(['name' => 'Sleeve', 'base_uom' => 'PCS']);
        $sv = mfgBom($sleeve, [['item' => $this->fabric, 'qty' => '0.25']]);
        mfgBom($this->shirt, [['item' => $sleeve, 'qty' => '2']]);
        expect(collect($this->queries->explode($this->shirt->id, '1'))->pluck('leaf')->all())->toBe([false, true]);

        $this->boms->retire($sv);
        expect($this->queries->explode($this->shirt->id, '1'))->toHaveCount(1)->and($this->queries->explode($this->shirt->id, '1')[0]['leaf'])->toBeTrue();
        mfgFails(fn () => $this->queries->explode($this->fabric->id, '1'), 'manufacturing.no_active_bom');
    });
});
