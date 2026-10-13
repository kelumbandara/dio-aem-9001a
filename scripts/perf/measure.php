<?php

/**
 * Times every readable page and API list of the product against a database filled by seed.php, as a super-admin, and counts
 * the database queries each one makes (a count that grows with the number of rows shown is the sign of an N+1 problem).
 * Reading only: nothing is changed except one throwaway user. Refuses any database whose name lacks "perf".
 *
 *   $env:DB_DATABASE='dio_erp_perf'; php scripts/perf/measure.php [--slow=400] [--queries=60] [--runs=3]
 */

use App\Modules\Core\CoreServiceProvider;
use App\Modules\Core\Models\MfaSecret;
use App\Modules\Core\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = (string) config('database.connections.pgsql.database');
if (! str_contains($db, 'perf')) {
    fwrite(STDERR, "Refusing to run: database '{$db}' does not look like a performance database.\n");
    exit(1);
}
$opts = getopt('', ['slow::', 'queries::', 'runs::']);
$slowMs = (int) ($opts['slow'] ?? 400);
$maxQueries = (int) ($opts['queries'] ?? 60);
$runs = (int) ($opts['runs'] ?? 3);

config(['licensing.enforce' => false, 'core.auth.api_requests_per_minute' => 1000000]);
$user = User::query()->where('email', 'perf@dio.test')->first() ?? User::factory()->create(['email' => 'perf@dio.test', 'name' => 'Perf']);
$user->assignRole(CoreServiceProvider::SUPER_ADMIN_ROLE);
MfaSecret::query()->firstOrCreate(['user_id' => $user->id], ['secret' => 'JBSWY3DPEHPK3PXP', 'confirmed_at' => now()]);
Sanctum::actingAs($user);
Auth::guard('web')->setUser($user);
$http = $app->make(HttpKernel::class);

/** @var list<array{label: string, uri: string, api: bool}> $targets */
$targets = [];
foreach (Route::getRoutes() as $route) {
    /** @var LaravelRoute $route */
    if (! in_array('GET', $route->methods(), true) || str_starts_with($route->uri(), 'storage/') || in_array($route->uri(), ['up', 'login', 'sanctum/csrf-cookie'], true) || str_starts_with($route->uri(), 'horizon')) {
        continue;
    }
    $uri = $route->uri();
    $params = $route->parameterNames();
    if ($params !== []) {
        $models = $route->signatureParameters(['subClass' => UrlRoutable::class]);
        $byName = [];
        foreach ($models as $p) {
            $byName[$p->getName()] = $p->getType()?->getName();
        }
        $ok = true;
        foreach ($params as $name) {
            $class = $byName[$name] ?? null;
            $key = $class ? $class::query()->orderBy('id')->first()?->getRouteKey() : null;
            if ($key === null) {
                $ok = false;
                break;
            }
            $uri = preg_replace('/\{'.preg_quote($name, '/').'\??\}/', (string) $key, $uri);
        }
        if (! $ok) {
            continue;
        }
    }
    $targets[] = ['label' => $route->uri(), 'uri' => '/'.ltrim($uri, '/'), 'api' => str_starts_with($route->uri(), 'api/')];
}

// Typical user actions on top of the plain pages: searching, deep pages, filters.
$extra = [
    ['/api/v1/items?filter[q]=bolt&per_page=50', true], ['/api/v1/items?page=20&per_page=50', true], ['/api/v1/customers?filter[q]=Customer 04&per_page=50', true],
    ['/api/v1/stock?per_page=100', true], ['/items?q=bolt', false], ['/customers?q=Customer 04', false], ['/sales/invoices?page=40', false],
];
foreach ($extra as [$u, $api]) {
    $targets[] = ['label' => 'EXTRA '.$u, 'uri' => $u, 'api' => $api];
}

$queryCount = 0;
DB::listen(function () use (&$queryCount) {
    $queryCount++;
});
$rows = [];
foreach ($targets as $t) {
    $times = [];
    $queries = 0;
    $status = 0;
    $bytes = 0;
    for ($i = 0; $i < $runs; $i++) {
        $queryCount = 0;
        DB::flushQueryLog();
        $request = Request::create($t['uri'], 'GET', [], [], [], ['HTTP_ACCEPT' => $t['api'] ? 'application/json' : 'text/html']);
        $start = hrtime(true);
        try {
            $response = $http->handle($request);
            $status = $response->getStatusCode();
            $bytes = strlen((string) $response->getContent());
        } catch (Throwable $e) {
            $status = 599;
        }
        $times[] = (hrtime(true) - $start) / 1e6;
        $queries = $queryCount;
        if ($status >= 500) {
            break;
        }
    }
    sort($times);
    $rows[] = ['label' => $t['label'], 'uri' => $t['uri'], 'ms' => $times[(int) floor(count($times) / 2)], 'queries' => $queries, 'status' => $status, 'kb' => (int) round($bytes / 1024)];
}

usort($rows, fn ($a, $b) => $b['ms'] <=> $a['ms']);
$bad = 0;
printf("%-58s %7s %6s %6s %5s\n", 'route', 'ms', 'sql', 'status', 'KB');
foreach ($rows as $r) {
    $flag = ($r['status'] >= 500 ? ' ERROR' : '').($r['ms'] > $slowMs ? ' SLOW' : '').($r['queries'] > $maxQueries ? ' MANY-QUERIES' : '');
    $bad += $flag !== '' ? 1 : 0;
    printf("%-58s %7.0f %6d %6d %5d%s\n", mb_substr($r['label'], 0, 58), $r['ms'], $r['queries'], $r['status'], $r['kb'], $flag);
}
$ms = array_column($rows, 'ms');
printf("\n%d pages measured; median %.0f ms, 95th percentile %.0f ms, slowest %.0f ms; %d flagged (slow > %d ms, or > %d queries, or an error)\n",
    count($rows), $ms[(int) floor(count($ms) / 2)], $ms[(int) floor(count($ms) * 0.05)], $ms[0], $bad, $slowMs, $maxQueries);
