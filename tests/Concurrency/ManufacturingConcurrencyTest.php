<?php

use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockReconciler;
use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Models\ProductionMaterialMovement;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Services\MaterialService;
use App\Modules\MasterData\Database\Seeders\ReferenceDataSeeder;
use App\Modules\MasterData\Models\Item;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Real parallelism against the committed test database: separate PHP processes issue to, return from and activate
 * for the same order or bill at the same moment. "Never issue more than the order needs, never return more than it
 * holds, never two active bills" cannot be shown inside one transaction, so the data is committed and truncated afterwards.
 */
beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    cleanManufacturingData();
    (new ReferenceDataSeeder)->run();
    (new FinanceSeeder)->run();
});

afterEach(fn () => cleanManufacturingData());

function cleanManufacturingData(): void
{
    DB::unprepared('TRUNCATE TABLE users, accounts, fiscal_years, customers, suppliers, units_of_measure, item_categories, currencies, tax_codes, payment_terms, price_lists, warehouses, number_sequences, outbox_events, audit_logs, settings, work_centers, production_lines RESTART IDENTITY CASCADE');
}

/** @return list<array{0: int, 1: int}> done/refused per worker */
function manufacturingRace(string $mode, int $workers, int $attempts, int $id): array
{
    $startAt = microtime(true) + 3.0;   // every worker must have booted before the gun goes off
    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $p = new Process([PHP_BINARY, base_path('tests/Support/manufacturing-worker.php'), $mode, (string) $attempts, (string) $startAt, (string) $id],
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

/** @return array{0: ProductionOrder, 1: Item, 2: Warehouse} */
function releasedOrderOfTen(): array
{
    $wh = invWarehouse(['code' => 'RAW']);
    $product = mdItem(['name' => 'Gadget', 'base_uom' => 'PCS']);
    $part = mdItem(['name' => 'Part', 'base_uom' => 'PCS']);
    mfgBom($product, [['item' => $part, 'qty' => '1']]);
    invReceive($part, $wh, '100', '5');

    return [mfgReleased($product, '10', $wh), $part, $wh];     // needs exactly 10 parts
}

it('never issues more than the order needs, however many people issue at once', function () {
    [$order, $part, $wh] = releasedOrderOfTen();

    $results = manufacturingRace('issue', 4, 6, $order->id);    // 24 attempts at one part each, 10 allowed

    expect(array_sum(array_column($results, 0)))->toBe(10)->and(array_sum(array_column($results, 1)))->toBe(14);
    $material = $order->materials()->first();
    expect((string) $material->issued_qty)->toBe('10.000000')->and((string) $material->issued_value)->toBe('50.0000')
        ->and(ProductionMaterialMovement::count())->toBe(10)->and(invOnHand($part, $wh))->toBe('90.000000');
    expect(app(StockReconciler::class)->discrepancies())->toBe([])->and(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});

it('never returns more than the order holds, however many people return at once', function () {
    [$order, $part, $wh] = releasedOrderOfTen();
    app(MaterialService::class)->issue($order, [['material' => 1, 'qty' => '10']]);

    $results = manufacturingRace('return', 4, 6, $order->id);   // 24 attempts at one part each, 10 held

    expect(array_sum(array_column($results, 0)))->toBe(10)->and(array_sum(array_column($results, 1)))->toBe(14);
    $material = $order->materials()->first();
    expect((string) $material->returned_qty)->toBe('10.000000')->and($material->netQty())->toBe('0.000000000000')->and(invOnHand($part, $wh))->toBe('100.000000');
    expect(app(StockReconciler::class)->discrepancies())->toBe([])->and(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});

it('lets exactly one of several simultaneous activations win', function () {
    $product = mdItem(['name' => 'Gadget', 'base_uom' => 'PCS']);
    $part = mdItem(['name' => 'Part', 'base_uom' => 'PCS']);
    $draft = mfgDraft($product, [['item' => $part, 'qty' => '1']]);

    $results = manufacturingRace('activate', 4, 1, $draft->id);

    expect(array_sum(array_column($results, 0)))->toBe(1)->and(array_sum(array_column($results, 1)))->toBe(3);
    expect(BomVersion::where('item_id', $product->id)->where('status', 'active')->count())->toBe(1);
});
