<?php

/**
 * Test helper process for NumberingConcurrencyTest: boots the app and allocates N numbers
 * from one sequence, printing one per line. Usage: php allocate-numbers.php <sequence> <count>
 */

use App\Modules\Core\Contracts\NumberingService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$numbering = $app->make(NumberingService::class);

for ($i = 0; $i < (int) $argv[2]; $i++) {
    echo $numbering->next($argv[1]), "\n";
}
