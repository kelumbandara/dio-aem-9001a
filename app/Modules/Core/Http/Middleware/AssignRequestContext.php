<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts or creates X-Request-Id, exposes it to logs/audit via Context, and tags the channel.
 */
final class AssignRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Request-Id');
        if (! is_string($id) || preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id) !== 1) {
            $id = (string) Str::uuid();
        }

        $isApi = $request->is('api/*');
        Context::add('request_id', $id);
        Context::add('channel', $isApi ? 'api' : 'web');

        $response = $next($request);

        $response->headers->set('X-Request-Id', $id);
        if ($isApi) {
            $response->headers->set('X-API-Version', '1');
        }

        return $response;
    }
}
