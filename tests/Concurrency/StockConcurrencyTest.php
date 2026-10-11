<?php

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\StockReconciler;
use App\Modules\MasterData\Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Real parallelism against the committed test database: separate PHP processes post stock at the same moment.
 * "Never negative", "no oversold unit" and "no deadlock" cannot be shown inside one transaction, so the data is
 * committed and truncated afterwards.
 */
beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    cleanStockData();
    (new ReferenceDataSeeder)->run();
});

afterEach(fn () => cleanStockData());

function cleanStockData(): void
{
    DB::unprepared('TRUNCATE TABLE users, units_of_measure, item_categories, currencies, tax_codes, payment_terms, warehouses, number_sequences, outbox_events, audit_logs, settings RESTART IDENTITY CASCADE');
}

/** @param  list<string>  $args  @return list<array{0: int, 1: int}> posted/refused per worker */
function stockRace(int $workers, string $mode, int $attempts, array $itemIds, array $warehouseIds): array
{
    $startAt = microtime(true) + 3.0;   // every worker must have booted before the gun goes off
    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $p = new Process([PHP_BINARY, base_path('tests/Support/stock-worker.php'), $mode, (string) $attempts, (string) $startAt, implode(',', $itemIds), implode(',', $warehouseIds)],
            base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')]);
        $p->start();
        $processes[] = $p;
    }

    $out = [];
    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
        $out[] = array_map('intval', explode(':', trim($p->getOutput())));
    }

    return $out;
}

it('never sells a unit twice: parallel issues of limited stock succeed exactly as often as there is stock', function () {
    $wh = invWarehouse(['code' => 'MAIN']);
    $item = mdItem(['name' => 'Widget']);
    invReceive($item, $wh, '20', '10');

    $results = stockRace(6, 'issue', 6, [$item->id], [$wh->id]);   // 36 attempts for 20 units

    expect(array_sum(array_column($results, 0)))->toBe(20)->and(array_sum(array_column($results, 1)))->toBe(16);
    expect(invOnHand($item))->toBe('0.000000')->and(StockBalance::where('item_id', $item->id)->min('on_hand'))->toEqual(0);
    expect(DB::table('stock_ledger')->count())->toBe(21)->and(DB::table('item_costs')->where('item_id', $item->id)->value('value'))->toEqual(0);
    expect(app(StockReconciler::class)->discrepancies())->toBe([]);
});

it('does not deadlock when documents touch the same items in opposite orders and directions', function () {
    $a = invWarehouse(['code' => 'A']);
    $b = invWarehouse(['code' => 'B']);
    $items = [mdItem(['name' => 'One']), mdItem(['name' => 'Two']), mdItem(['name' => 'Three'])];
    foreach ($items as $item) {
        invReceive($item, $a, '500', '10');
        invReceive($item, $b, '500', '10');
    }

    $results = stockRace(6, 'shuffle', 8, array_map(fn ($i) => $i->id, $items), [$a->id, $b->id]);

    expect(array_sum(array_column($results, 0)))->toBe(48)->and(array_sum(array_column($results, 1)))->toBe(0);
    foreach ($items as $item) {
        expect(invOnHand($item))->toBe('1000.000000');
    }
    expect(app(StockReconciler::class)->discrepancies())->toBe([]);
});
