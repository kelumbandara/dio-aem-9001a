<?php

/**
 * Test helper process for ProcurementConcurrencyTest: boots the app and, once the start time is reached, tries to
 * receive one unit ("receive") or bill one unit ("bill") of the order's first line, <attempts> times, then prints
 * "<done>:<refused>".
 *
 * Usage: php procurement-worker.php <receive|bill> <attempts> <startAt> <orderId> <workerIndex>
 */

use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\SupplierBillService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$mode, $attempts, $startAt, $orderId, $worker] = [$argv[1], (int) $argv[2], (float) $argv[3], (int) $argv[4], (int) $argv[5]];

while (microtime(true) < $startAt) {
    usleep(1000);
}

$done = $refused = 0;
for ($i = 0; $i < $attempts; $i++) {
    try {
        $order = PurchaseOrder::query()->findOrFail($orderId);
        if ($mode === 'receive') {
            $app->make(GoodsReceiptService::class)->post($order, ['lines' => [['line' => 1, 'qty' => '1']]]);
        } else {
            $app->make(SupplierBillService::class)->create($order, ['supplier_invoice_no' => "W{$worker}-{$i}", 'lines' => [['line' => 1, 'qty' => '1']]]);
        }
        $done++;
    } catch (BusinessException $e) {
        $refused++;
    }
}

echo "{$done}:{$refused}";
