<?php

/**
 * Test helper process for StockConcurrencyTest: boots the app and posts stock movements as fast as it can once the
 * start time is reached, then prints "<posted>:<refused>".
 *
 * Usage: php stock-worker.php <mode> <attempts> <startAt> <itemIds comma separated> <warehouseIds comma separated>
 *   issue    - issue 1 unit of the first item from the first warehouse, <attempts> times
 *   shuffle  - transfer 1 unit of every item between the two warehouses, items listed in a random order, in a random direction
 */

use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$mode, $attempts, $startAt] = [$argv[1], (int) $argv[2], (float) $argv[3]];
$items = array_map('intval', explode(',', $argv[4]));
$warehouses = array_map('intval', explode(',', $argv[5]));
$poster = $app->make(StockPoster::class);
$today = now('Asia/Colombo')->toDateString();

while (microtime(true) < $startAt) {
    usleep(1000);
}

$posted = $refused = 0;
for ($i = 0; $i < $attempts; $i++) {
    try {
        if ($mode === 'issue') {
            $poster->post(new StockMovement('issue', $today, 'sale', [new StockLine(itemId: $items[0], qty: '1', warehouseId: $warehouses[0])]));
        } else {
            $order = $items;
            shuffle($order);
            [$from, $to] = mt_rand(0, 1) === 0 ? [$warehouses[0], $warehouses[1]] : [$warehouses[1], $warehouses[0]];
            $poster->post(new StockMovement('transfer', $today, 'transfer', array_map(
                fn (int $id) => new StockLine(itemId: $id, qty: '1', warehouseId: $from, toWarehouseId: $to),
                $order,
            )));
        }
        $posted++;
    } catch (BusinessException $e) {
        $refused++;
    }
}

echo "{$posted}:{$refused}";
