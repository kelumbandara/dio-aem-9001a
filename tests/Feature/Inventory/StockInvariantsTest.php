<?php

use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Services\StockReconciler;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * Randomised sequences of everything the poster can do (including things that get refused). After every step the
 * invariants of docs/architecture/database.md §3 must hold: balances = Σ ledger, stock never negative, item cost
 * value = Σ ledger value and never negative, every posted document's total = Σ its lines.
 */
it('keeps the books balanced through a long random sequence of movements', function (int $seed) {
    mt_srand($seed);
    $wh = [invWarehouse(['code' => 'W1']), invWarehouse(['code' => 'W2', 'uses_locations' => true])];
    app(SaveLocation::class)($wh[1], ['code' => 'R1', 'name' => 'Rack 1']);
    $loc = Location::where('warehouse_id', $wh[1]->id)->where('code', 'R1')->first();
    $items = [mdItem(['name' => 'Plain']), mdItem(['name' => 'Decimal', 'base_uom' => 'KG']), mdItem(['name' => 'Lotted', 'tracking' => 'lot', 'shelf_life_days' => 40])];
    $poster = app(StockPoster::class);

    $pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
    $qty = fn ($item) => $item->name === 'Decimal' ? (string) (mt_rand(1, 40) / 8) : (string) mt_rand(1, 12);   // multiples of 0.125: exact in 3 decimals
    $place = fn () => mt_rand(0, 2) === 0 ? ['warehouseId' => $wh[1]->id, 'locationId' => $loc->id] : ['warehouseId' => $pick($wh)->id];

    $posted = $refused = $reversed = 0;
    for ($step = 0; $step < 140; $step++) {
        $item = $pick($items);
        $lot = $item->name === 'Lotted' ? ['lotNo' => 'L'.mt_rand(1, 3), 'expiryDate' => now('Asia/Colombo')->addDays(10 + 100 * mt_rand(1, 3))->toDateString()] : [];
        $kind = $pick(['receipt', 'receipt', 'issue', 'issue', 'transfer', 'adjustment', 'reverse']);

        try {
            if ($kind === 'reverse') {
                $doc = StockDocument::where('status', 'posted')->whereNull('reverses_id')->inRandomOrder()->first();
                if ($doc) {
                    $poster->reverse($doc->id);
                    $reversed++;
                }
            } elseif ($kind === 'receipt') {
                invPost('receipt', [invLine($item, $qty($item), $place() + $lot + ['unitCost' => (string) (mt_rand(100, 99999) / 100)])]);
            } elseif ($kind === 'issue') {
                invPost('issue', [invLine($item, $qty($item), $place())]);
            } elseif ($kind === 'transfer') {
                $from = $place();
                invPost('transfer', [invLine($item, $qty($item), $from + ['toWarehouseId' => $wh[0]->id === $from['warehouseId'] ? $wh[1]->id : $wh[0]->id])]);
            } else {
                invPost('adjustment', [invLine($item, $qty($item), $place() + $lot + ['direction' => $pick(['in', 'out']), 'unitCost' => $pick([null, '12.5'])])], ['reason' => 'count']);
            }
            $posted++;
        } catch (BusinessException) {
            $refused++;   // refusals are part of the test: they must leave no trace
        }

        expect(app(StockReconciler::class)->discrepancies())->toBe([], "after step {$step} ({$kind})");
        expect(DB::table('stock_balances')->where('on_hand', '<', 0)->count())->toBe(0)
            ->and(DB::table('item_costs')->where('value', '<', 0)->orWhere('qty', '<', 0)->count())->toBe(0);
    }

    expect($posted)->toBeGreaterThan(40)->and($refused)->toBeGreaterThan(0);   // the run really exercised both paths
    // Each original and its reversal cancel exactly.
    foreach (StockDocument::where('status', 'reversed')->get() as $original) {
        $reversal = StockDocument::find($original->reversed_by_id);
        expect($reversal->total_value)->toBe($original->total_value);
    }
})->with([2026, 7, 1234567]);
