<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Contracts\LicenceGate;
use App\Modules\Core\Contracts\ModuleRegistry;
use App\Support\Exceptions\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleRegistry $modules, private readonly LicenceGate $licence) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $this->modules->assertEnabled($module);
        if (! $this->licence->allowsModule($module)) {
            throw new BusinessException('core.module_not_licensed', "The {$module} module is not part of this installation's licence.", 403);
        }

        return $next($request);
    }
}
