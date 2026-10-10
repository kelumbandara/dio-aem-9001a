<?php

use App\Modules\Core\Contracts\NumberingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Real parallelism: separate PHP processes hit the same sequence at once. This cannot be proven
 * inside one process or one transaction, so this test commits to the (test) database and cleans up.
 */
afterEach(function () {
    DB::table('number_sequence_counters')->delete();
    DB::table('number_sequences')->where('key', 'concurrency.test')->delete();
});

it('hands out unique, gap-free numbers to parallel processes', function () {
    Artisan::call('migrate', ['--force' => true]);
    app(NumberingService::class)->define('concurrency.test', '{SEQ}', 4);

    $workers = 6;
    $perWorker = 15;

    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/allocate-numbers.php'), 'concurrency.test', (string) $perWorker],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')],
        );
        $process->start();
        $processes[] = $process;
    }

    $numbers = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
        array_push($numbers, ...array_filter(explode("\n", trim($process->getOutput()))));
    }

    $expected = array_map(fn (int $n) => str_pad((string) $n, 4, '0', STR_PAD_LEFT), range(1, $workers * $perWorker));
    sort($numbers);

    expect($numbers)->toBe($expected);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'row-lock behaviour is verified on PostgreSQL');
