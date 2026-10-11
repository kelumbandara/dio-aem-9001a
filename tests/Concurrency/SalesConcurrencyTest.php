<?php

use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Inventory\Services\StockReconciler;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Database\Seeders\ReferenceDataSeeder;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Real parallelism against the committed test database: separate PHP processes confirm orders and deliver goods at the
 * same moment. "The credit limit cannot be squeezed past by two simultaneous orders" and "the last unit is delivered
 * once" cannot be shown inside one transaction, so the data is committed and truncated afterwards.
 */
beforeEach(function () {
    config(['sales.prices_include_vat' => false]);   // these tests work in net prices; VAT-inclusive pricing has its own tests
    Artisan::call('migrate', ['--force' => true]);
    cleanSalesData();
    (new ReferenceDataSeeder)->run();
    (new FinanceSeeder)->run();
});

afterEach(fn () => cleanSalesData());

function cleanSalesData(): void
{
    DB::unprepared('TRUNCATE TABLE users, accounts, fiscal_years, customers, suppliers, units_of_measure, item_categories, currencies, tax_codes, payment_terms, price_lists, warehouses, number_sequences, outbox_events, audit_logs, settings RESTART IDENTITY CASCADE');
}

/** @param  list<string>  $args  @return list<array{0: int, 1: int}> done/refused per worker */
function salesRace(int $workers, array $args): array
{
    $startAt = microtime(true) + 3.0;   // every worker must have booted before the gun goes off
    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $mine = array_map(fn ($a) => is_callable($a) ? $a($i) : $a, $args);
        $p = new Process([PHP_BINARY, base_path('tests/Support/sales-worker.php'), $mine[0], $mine[1], (string) $startAt, ...array_slice($mine, 2)],
            base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'SALES_PRICES_INCLUDE_VAT' => 'false']);
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

it('never lets simultaneous orders squeeze past a credit limit', function () {
    $wh = invWarehouse(['code' => 'MAIN']);
    $item = mdItem(['name' => 'Widget', 'default_tax_code' => 'VAT']);
    invReceive($item, $wh, '1000', '60');
    $customer = app(SaveCustomer::class)(['name' => 'Small Shop', 'credit_limit' => '1000']);   // each order is 118.00: room for exactly 8

    $results = salesRace(5, ['confirm', '6', (string) $customer->id, (string) $wh->id, (string) $item->id]);

    $done = array_sum(array_column($results, 0));
    $refused = array_sum(array_column($results, 1));
    expect($done)->toBe(8)->and($refused)->toBe(22)   // 30 attempts
        ->and(Order::where('status', 'confirmed')->count())->toBe(8)
        ->and(app(OrderService::class)->uninvoiced($customer->id))->toBe('944.0000');
});

it('delivers the last units exactly once however many deliveries race for them', function () {
    $wh = invWarehouse(['code' => 'MAIN']);
    $item = mdItem(['name' => 'Widget', 'default_tax_code' => 'VAT']);
    invReceive($item, $wh, '10', '60');
    $customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
    $orders = app(OrderService::class);

    $ids = [];
    foreach (range(1, 24) as $n) {   // 24 orders of one unit each against 10 on hand
        $ids[] = $orders->confirm($orders->save(['customer_id' => $customer->id, 'warehouse_id' => $wh->id, 'lines' => [['item_id' => $item->id, 'qty' => '1', 'unit_price' => '100']]]))->id;
    }
    $slices = array_chunk($ids, 4);

    $results = salesRace(6, ['deliver', '0', fn (int $i) => implode(',', $slices[$i])]);

    expect(array_sum(array_column($results, 0)))->toBe(10)->and(array_sum(array_column($results, 1)))->toBe(14)
        ->and(invOnHand($item, $wh))->toBe('0.000000')
        ->and(DB::table('sales_deliveries')->count())->toBe(10)
        ->and(DB::table('sales_order_lines')->sum('qty_delivered'))->toEqual(10);
    expect(app(StockReconciler::class)->discrepancies())->toBe([])->and(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});
