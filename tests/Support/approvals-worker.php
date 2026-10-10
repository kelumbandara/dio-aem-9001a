<?php

/**
 * Test helper process for ApprovalConcurrencyTest. Boots the app, waits for a shared start time so
 * several workers hit the database at the same instant, then performs one action and prints one line.
 *
 *   php approvals-worker.php execute <requestId> <startAt>
 *   php approvals-worker.php approve <requestPublicId> <userId> <startAt>
 */

use App\Modules\Approvals\Actions\ExecuteApprovedRequest;
use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Approvals\FakePayment;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->make(ApprovalService::class)->registerAction(FakePayment::class);

[, $mode] = $argv;
$startAt = (float) end($argv);

while (microtime(true) < $startAt) {
    usleep(200);
}

try {
    if ($mode === 'execute') {
        $app->make(ExecuteApprovedRequest::class)((int) $argv[2]);
        echo "ok\n";
    } else {
        $app->make(ApprovalEngine::class)->approve($argv[2], (int) $argv[3]);
        echo "ok\n";
    }
} catch (BusinessException $e) {
    echo 'err:'.$e->errorCode."\n";
} catch (Throwable $e) {
    echo 'fatal:'.get_class($e).': '.$e->getMessage()."\n";
}
