<?php

/**
 * Test helper process for ManufacturingConcurrencyTest: boots the app and, once the start time is reached, tries
 * <attempts> times to issue one unit ("issue") or return one unit ("return") of the order's first material, report one unit
 * of output ("report"), receive one finished unit ("receive"), report the downtime between two given times ("downtime", the id
 * is ignored), or to activate a bill ("activate", the id is the BOM version), then prints "<done>:<refused>".
 *
 * Usage: php manufacturing-worker.php <issue|return|report|receive|downtime|activate> <attempts> <startAt> <orderOrVersionId> [<from> <to>]
 */

use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Services\BomService;
use App\Modules\Manufacturing\Services\DowntimeService;
use App\Modules\Manufacturing\Services\FinishedGoodsService;
use App\Modules\Manufacturing\Services\MaterialService;
use App\Modules\Manufacturing\Services\OutputService;
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
        } elseif ($mode === 'downtime') {
            $app->make(DowntimeService::class)->report(['machine' => 'M1', 'shift' => 'ALL', 'reason' => 'BRK', 'started_at' => $argv[5], 'ended_at' => $argv[6]]);
        } elseif ($mode === 'report') {
            $app->make(OutputService::class)->report(ProductionOrder::query()->findOrFail($id), ['shift' => 'ALL', 'produced' => '1']);
        } elseif ($mode === 'receive') {
            $app->make(FinishedGoodsService::class)->receive(ProductionOrder::query()->findOrFail($id), ['qty' => '1']);
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
