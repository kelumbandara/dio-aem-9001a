<?php

/**
 * Shows which database statements one page repeats (the signature of an N+1 problem), with where they are called from.
 *
 *   $env:DB_DATABASE='dio_erp_perf'; php scripts/perf/queries.php /finance/receivables [--top=8]
 */

use App\Modules\Core\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_contains((string) config('database.connections.pgsql.database'), 'perf')) {
    fwrite(STDERR, "Refusing to run: not a performance database.\n");
    exit(1);
}
$uri = $argv[1] ?? '/';
$top = 8;
foreach ($argv as $a) {
    if (str_starts_with($a, '--top=')) {
        $top = (int) substr($a, 6);
    }
}

config(['licensing.enforce' => false]);
$user = User::query()->where('email', 'perf@dio.test')->firstOrFail();
Sanctum::actingAs($user);
Auth::guard('web')->setUser($user);

$seen = [];
DB::listen(function ($q) use (&$seen) {
    $sql = preg_replace(['/\s+/', '/\b\d+\b/', "/'[^']*'/", '/\((\?,? ?)+\)/'], [' ', 'N', '?', '(?)'], $q->sql);
    $trace = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40))->first(fn ($f) => isset($f['file']) && str_contains($f['file'], DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules'.DIRECTORY_SEPARATOR));
    $where = $trace ? str_replace(base_path().DIRECTORY_SEPARATOR, '', $trace['file']).':'.($trace['line'] ?? '?') : '?';
    $key = $sql;
    $seen[$key]['n'] = ($seen[$key]['n'] ?? 0) + 1;
    $seen[$key]['ms'] = ($seen[$key]['ms'] ?? 0) + $q->time;
    $seen[$key]['where'] = $seen[$key]['where'] ?? $where;
});

$request = Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => str_starts_with($uri, '/api/') ? 'application/json' : 'text/html']);
$start = hrtime(true);
$response = $app->make(HttpKernel::class)->handle($request);
$ms = (hrtime(true) - $start) / 1e6;

uasort($seen, fn ($a, $b) => $b['n'] <=> $a['n']);
printf("%s -> %d in %.0f ms, %d statements (%d distinct)\n", $uri, $response->getStatusCode(), $ms, array_sum(array_column($seen, 'n')), count($seen));
foreach (array_slice($seen, 0, $top, true) as $sql => $info) {
    printf("%5d x %6.1f ms  %s\n        at %s\n", $info['n'], $info['ms'], mb_substr($sql, 0, 150), $info['where']);
}
