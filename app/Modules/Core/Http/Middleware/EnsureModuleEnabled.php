<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $this->modules->assertEnabled($module);

        return $next($request);
    }
}
