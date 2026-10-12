<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OwnerOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isOwner(), 403, 'Only the owner can do this.');

        return $next($request);
    }
}
