<?php

/**
 * Test helper process for SalesConcurrencyTest: boots the app and, once the start time is reached, either
 *  - "confirm": creates a one-line draft order for a customer and tries to confirm it (credit limit race), or
 *  - "deliver": tries to deliver each of the given confirmed orders (last-units race),
 * then prints "<done>:<refused>".
 *
 * Usage: php sales-worker.php confirm <attempts> <startAt> <customerId> <warehouseId> <itemId>
 *        php sales-worker.php deliver <attempts> <startAt> <orderIds,comma,separated>
 */

use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\OrderService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1];
$attempts = (int) $argv[2];
$startAt = (float) $argv[3];

while (microtime(true) < $startAt) {
    usleep(1000);
}

$done = $refused = 0;
if ($mode === 'confirm') {
    [$customerId, $warehouseId, $itemId] = [(int) $argv[4], (int) $argv[5], (int) $argv[6]];
    $orders = $app->make(OrderService::class);
    for ($i = 0; $i < $attempts; $i++) {
        $order = $orders->save(['customer_id' => $customerId, 'warehouse_id' => $warehouseId, 'lines' => [['item_id' => $itemId, 'qty' => '1', 'unit_price' => '100']]]);
        try {
            $orders->confirm($order);
            $done++;
        } catch (BusinessException $e) {
            $refused++;
        }
    }
} else {
    $deliveries = $app->make(DeliveryService::class);
    $ids = array_map('intval', explode(',', $argv[4]));
    foreach ($ids as $id) {
        try {
            $deliveries->post(Order::query()->findOrFail($id), []);
            $done++;
        } catch (BusinessException $e) {
            $refused++;
        }
    }
}

echo "{$done}:{$refused}";
