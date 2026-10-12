<?php

use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Inventory\Services\StockReconciler;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\MasterData\Database\Seeders\ReferenceDataSeeder;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Real parallelism against the committed test database: separate PHP processes receive goods and enter supplier bills
 * for the same order at the same moment. "Never receive or bill more than the order allows" cannot be shown inside one
 * transaction, so the data is committed and truncated afterwards.
 */
beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    cleanProcurementData();
    (new ReferenceDataSeeder)->run();
    (new FinanceSeeder)->run();
});

afterEach(fn () => cleanProcurementData());

function cleanProcurementData(): void
{
    DB::unprepared('TRUNCATE TABLE users, accounts, fiscal_years, customers, suppliers, units_of_measure, item_categories, currencies, tax_codes, payment_terms, price_lists, warehouses, number_sequences, outbox_events, audit_logs, settings RESTART IDENTITY CASCADE');
}

/** @return list<array{0: int, 1: int}> done/refused per worker */
function procurementRace(string $mode, int $workers, int $attempts, int $orderId): array
{
    $startAt = microtime(true) + 3.0;   // every worker must have booted before the gun goes off
    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $p = new Process([PHP_BINARY, base_path('tests/Support/procurement-worker.php'), $mode, (string) $attempts, (string) $startAt, (string) $orderId, (string) $i],
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

function approvedOrder(): array
{
    $wh = invWarehouse(['code' => 'MAIN']);
    $item = mdItem(['name' => 'Widget', 'default_tax_code' => 'VAT']);
    $supplier = app(SaveSupplier::class)(['name' => 'ACME Supplies']);
    $buyer = userWithPermissions('procurement.order.manage');
    $orders = app(PurchaseOrderService::class);
    $order = $orders->submit($orders->save(['supplier_id' => $supplier->id, 'warehouse_id' => $wh->id, 'lines' => [['item_id' => $item->id, 'qty' => '10', 'unit_price' => '100']]]), $buyer->id);

    return [$order, $item, $wh];
}

it('never receives more than was ordered, however many receipts arrive at once', function () {
    [$order, $item, $wh] = approvedOrder();

    $results = procurementRace('receive', 6, 4, $order->id);

    expect(array_sum(array_column($results, 0)))->toBe(10)->and(array_sum(array_column($results, 1)))->toBe(14)   // 24 attempts at 1 unit against 10 ordered
        ->and(invOnHand($item, $wh))->toBe('10.000000')->and(DB::table('goods_receipts')->count())->toBe(10)
        ->and(DB::table('purchase_order_lines')->value('qty_received'))->toEqual(10);
    expect(app(StockReconciler::class)->discrepancies())->toBe([])->and(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});

it('never bills more than was received, however many bills arrive at once', function () {
    [$order] = approvedOrder();
    app(GoodsReceiptService::class)->post($order, ['lines' => [['line' => 1, 'qty' => '10']]]);

    $results = procurementRace('bill', 6, 4, $order->id);

    expect(array_sum(array_column($results, 0)))->toBe(10)->and(array_sum(array_column($results, 1)))->toBe(14)
        ->and(DB::table('supplier_bills')->count())->toBe(10)->and(DB::table('purchase_order_lines')->value('qty_billed'))->toEqual(10)
        ->and(DB::table('open_items')->where('side', 'payable')->sum('amount'))->toEqual(1180);   // 10 bills of 1 unit at 100 + 18 % VAT
    expect(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});
