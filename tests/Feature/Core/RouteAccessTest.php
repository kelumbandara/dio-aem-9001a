<?php

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Security inventory of every route in the product (docs/architecture/security.md §9):
 *  1. the routes anyone can reach without signing in are exactly the ones listed here;
 *  2. a signed-in person with NO permission at all gets nothing from any other route, except the self-service ones listed
 *     here (their own notifications, password, phone, assistant, …), which only ever show their own things.
 */

/** Routes reachable without signing in, as "METHOD uri". Every addition must be a deliberate decision. */
function publicRoutes(): array
{
    return [
        'GET sanctum/csrf-cookie',        // framework: the CSRF cookie for browser clients
        'GET up',                         // framework: process is alive, no details
        'GET api/v1/health', 'GET api/v1/health/ready',   // no secrets in the answer (tested in PlatformApiTest)
        'POST api/v1/auth/login',         // throttled and locked out per account
        'GET login', 'POST login',        // throttled
        'GET forgot-password', 'POST forgot-password', 'GET reset-password/{token}', 'POST reset-password',   // same answer for every address; one-time token; throttled
        'POST locale',                    // chooses the display language only
        'GET branding/logo',              // the sign-in page shows the company logo
        'GET api/v1/webhooks/whatsapp', 'POST api/v1/webhooks/whatsapp',   // verify token / HMAC signature
        'GET unsubscribe/{message}',      // signed link in an e-mail
    ];
}

/** Routes any signed-in person may use, with no permission: they only show or change the person's own things. */
function selfServiceRoutes(): array
{
    return [
        'POST logout', 'POST api/v1/auth/logout', 'GET mfa/setup', 'POST mfa/confirm', 'POST api/v1/auth/mfa/setup', 'POST api/v1/auth/mfa/confirm',
        'GET /', 'GET api/v1/me', 'GET api/v1/mobile/config', 'POST api/v1/devices', 'DELETE api/v1/devices/{device}',
        'GET notifications', 'POST notifications/read-all', 'POST notifications/{notification}/read',
        'GET api/v1/notifications', 'POST api/v1/notifications/read-all', 'POST api/v1/notifications/{notification}/read',
        'GET account/password', 'PUT account/password',
        'GET communications/channels', 'POST communications/channels', 'POST communications/channels/confirm', 'DELETE communications/channels/{identity}',
        'GET api/v1/me/channels', 'POST api/v1/me/channels', 'POST api/v1/me/channels/confirm', 'DELETE api/v1/me/channels/{identity}',
        'GET api/v1/appearance',          // the company's colours and name: the phone app applies them
        'GET approvals', 'GET approvals/delegations', 'GET api/v1/approvals', 'GET api/v1/approvals/delegations',   // my own inbox / my own delegations (scoped to the person)
        'GET admin/licence',              // status and installation ID for everyone, so people know why the system is read-only (installing needs a permission)
        'GET reports',                    // the dashboards this person may see (none, without permissions)
    ];
}

function routeKey(LaravelRoute $r): array
{
    return array_map(fn (string $m) => $m.' '.$r->uri(), array_values(array_diff($r->methods(), ['HEAD'])));
}

it('has exactly the public routes that were decided, and nothing else without sign-in', function () {
    $found = [];
    foreach (Route::getRoutes() as $r) {
        if (! preg_match('/auth/i', implode(',', $r->gatherMiddleware())) && ! str_starts_with($r->uri(), 'storage/')) {
            array_push($found, ...routeKey($r));
        }
    }
    sort($found);
    $expected = publicRoutes();
    sort($expected);

    expect($found)->toBe($expected);
});

it('serves nothing from the framework\'s own storage route (it could expose files)', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn (LaravelRoute $r) => $r->uri())->all();
    expect(array_filter($uris, fn ($u) => str_starts_with($u, 'storage/')))->toBe([]);
    $this->get('/storage/anything.txt')->assertNotFound();
});

it('gives a person with no permission nothing from any route except the self-service ones', function () {
    $nobody = userWithPermissions();
    $skip = [...publicRoutes(), ...selfServiceRoutes()];
    $offenders = [];
    $checked = 0;

    foreach (Route::getRoutes() as $r) {
        foreach (routeKey($r) as $key) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            [$method, $uri] = explode(' ', $key, 2);
            $url = '/'.preg_replace('/\{[^}]+\}/', 'x', $uri);
            $isApi = str_starts_with($uri, 'api/');

            app('auth')->forgetGuards();
            DB::beginTransaction();      // a savepoint: one failing request must not poison the ones after it
            $response = $isApi
                ? asApi($nobody)->json($method, $url, [], $method === 'GET' ? [] : idemKey())
                : test()->actingAs($nobody)->call($method, $url);
            DB::rollBack();
            $checked++;

            $status = $response->getStatusCode();
            if ($status >= 500 || ($status >= 200 && $status < 300)) {
                $offenders[] = "{$key} → {$status}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(400)->and($offenders)->toBe([]);
})->skip(! env('RUN_ROUTE_SWEEP', true));
