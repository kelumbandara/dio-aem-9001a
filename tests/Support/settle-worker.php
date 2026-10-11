<?php

/**
 * Test helper process for FinanceConcurrencyTest: boots the app and records receipts against one invoice as fast as it
 * can once the start time is reached, then prints "<posted>:<refused>".
 *
 * Usage: php settle-worker.php <partyId> <bankAccountId> <openItemId> <amount> <attempts> <startAt>
 */

use App\Modules\Finance\Services\SettlementService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$partyId, $bankId, $itemId, $amount, $attempts, $startAt] = [(int) $argv[1], (int) $argv[2], (int) $argv[3], $argv[4], (int) $argv[5], (float) $argv[6]];
$service = $app->make(SettlementService::class);
$today = now('Asia/Colombo')->toDateString();

while (microtime(true) < $startAt) {
    usleep(1000);
}

$posted = $refused = 0;
for ($i = 0; $i < $attempts; $i++) {
    try {
        $service->post([
            'side' => 'receivable', 'party_id' => $partyId, 'bank_account_id' => $bankId, 'date' => $today, 'amount' => $amount,
            'allocations' => [['open_item_id' => $itemId, 'amount' => $amount]],
        ]);
        $posted++;
    } catch (BusinessException $e) {
        $refused++;
    }
}

echo "{$posted}:{$refused}";
