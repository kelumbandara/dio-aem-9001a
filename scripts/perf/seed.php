<?php

/**
 * Fills a THROWAWAY database with about a year of a small company's data, using the product's own services (so every rule,
 * ledger and reconciliation holds), to measure speed. Never run against a real database: it refuses any name without "perf".
 *
 *   createdb dio_erp_perf            (PostgreSQL)
 *   $env:DB_DATABASE='dio_erp_perf'; php scripts/perf/seed.php [--items=2000] [--customers=500] [--orders=3000]
 */

use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Core\Services\OutboxRelay;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Inventory\Actions\SaveWarehouse;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\Inventory\Services\StockReconciler;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Actions\SaveItem;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\MasterData\Database\Seeders\ReferenceDataSeeder;
use App\Modules\MasterData\Support\InputMapper;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = (string) config('database.connections.pgsql.database');
if (! str_contains($db, 'perf')) {
    fwrite(STDERR, "Refusing to run: database '{$db}' does not look like a throwaway performance database (name must contain 'perf').\n");
    exit(1);
}

$opts = getopt('', ['items::', 'customers::', 'orders::']);
$nItems = (int) ($opts['items'] ?? 2000);
$nCustomers = (int) ($opts['customers'] ?? 500);
$nOrders = (int) ($opts['orders'] ?? 3000);
mt_srand(42);
$t0 = microtime(true);
$lap = function (string $what) use (&$t0): void {
    printf("[%6.1fs] %s\n", microtime(true) - $t0, $what);
};

Artisan::call('migrate:fresh', ['--force' => true]);
app()->make(ReferenceDataSeeder::class)->run();
foreach ([RolesAndPermissionsSeeder::class, FinanceSeeder::class] as $seeder) {
    Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
}
$lap('database ready');

$wh = app(SaveWarehouse::class)(['code' => 'MAIN', 'name' => 'Main store']);
$map = app(InputMapper::class);
$items = [];
for ($i = 1; $i <= $nItems; $i++) {
    $items[] = app(SaveItem::class)($map->item(['name' => 'Item '.str_pad((string) $i, 5, '0', STR_PAD_LEFT).' '.['bolt', 'sheet', 'cable', 'valve', 'gasket', 'filter'][$i % 6], 'category' => 'GENERAL', 'base_uom' => 'PCS', 'default_tax_code' => 'VAT']))->id;
}
$lap("{$nItems} items");

$customers = [];
for ($i = 1; $i <= $nCustomers; $i++) {
    $customers[] = app(SaveCustomer::class)(['name' => 'Customer '.str_pad((string) $i, 4, '0', STR_PAD_LEFT).' (Pvt) Ltd', 'email' => "c{$i}@example.lk"])->id;
}
for ($i = 1; $i <= (int) ($nCustomers / 3); $i++) {
    app(SaveSupplier::class)(['name' => 'Supplier '.str_pad((string) $i, 4, '0', STR_PAD_LEFT).' Traders']);
}
$lap("{$nCustomers} customers, ".(int) ($nCustomers / 3).' suppliers');

// Opening stock: every item received in 5 batches at slightly different costs (so costing has history to work with).
$poster = app(StockPoster::class);
foreach (array_chunk($items, 50) as $chunk) {
    for ($batch = 0; $batch < 5; $batch++) {
        $poster->post(new StockMovement(type: 'receipt', postingDate: now('Asia/Colombo')->toDateString(), reason: 'purchase', lines: array_map(
            fn (int $id) => new StockLine(itemId: $id, qty: '200', warehouseId: $wh->id, unitCost: (string) (40 + ($id % 50) + $batch)),
            $chunk,
        )));
    }
}
$lap('opening stock: '.($nItems * 5).' receipt lines');

$orders = app(OrderService::class);
$relay = app(OutboxRelay::class);
$done = 0;
for ($n = 1; $n <= $nOrders; $n++) {
    $lines = [];
    for ($l = mt_rand(1, 5); $l > 0; $l--) {
        $lines[] = ['item_id' => $items[mt_rand(0, $nItems - 1)], 'qty' => (string) mt_rand(1, 8), 'unit_price' => (string) mt_rand(80, 400)];
    }
    try {
        $order = $orders->confirm($orders->save(['customer_id' => $customers[mt_rand(0, $nCustomers - 1)], 'warehouse_id' => $wh->id, 'lines' => $lines]));
        app(DeliveryService::class)->post($order, []);
        app(InvoiceService::class)->create($order->refresh(), []);
        $done++;
    } catch (Throwable $e) {
        // A line that repeats an item or runs out of stock is skipped, as it would be refused on a real screen.
    }
    if ($n % 250 === 0) {
        $relay->run(20000);
        $lap("{$done} invoices so far ({$n} tried)");
    }
}
$relay->run(20000);
$lap("{$done} sales invoices posted");

$fin = app(FinanceReconciler::class)->discrepancies();
$stock = app(StockReconciler::class)->discrepancies();
printf("finance discrepancies: %d, stock discrepancies: %d\n", count($fin), count($stock));
foreach (['items', 'customers', 'sales_invoices', 'sales_invoice_lines', 'stock_ledger', 'journal_entries', 'journal_lines', 'open_items', 'audit_logs', 'outbox_events'] as $table) {
    printf("  %-22s %s\n", $table, number_format((int) DB::table($table)->count()));
}
exit(($fin === [] && $stock === []) ? 0 : 2);
