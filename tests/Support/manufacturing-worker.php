<?php

/**
 * Test helper process for ManufacturingConcurrencyTest: boots the app and, once the start time is reached, tries
 * <attempts> times to issue one unit ("issue") or return one unit ("return") of the order's first material, or to
 * activate a bill ("activate", the id is the BOM version), then prints "<done>:<refused>".
 *
 * Usage: php manufacturing-worker.php <issue|return|activate> <attempts> <startAt> <orderOrVersionId>
 */

use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Services\BomService;
use App\Modules\Manufacturing\Services\MaterialService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$mode, $attempts, $startAt, $id] = [$argv[1], (int) $argv[2], (float) $argv[3], (int) $argv[4]];

while (microtime(true) < $startAt) {
    usleep(1000);
}

$done = $refused = 0;
for ($i = 0; $i < $attempts; $i++) {
    try {
        if ($mode === 'activate') {
            $app->make(BomService::class)->activate(BomVersion::query()->findOrFail($id));
        } else {
            $order = ProductionOrder::query()->findOrFail($id);
            $mode === 'issue'
                ? $app->make(MaterialService::class)->issue($order, [['material' => 1, 'qty' => '1']])
                : $app->make(MaterialService::class)->return($order, [['material' => 1, 'qty' => '1']], null, 'race');
        }
        $done++;
    } catch (BusinessException $e) {
        $refused++;
    }
}

echo "{$done}:{$refused}";
