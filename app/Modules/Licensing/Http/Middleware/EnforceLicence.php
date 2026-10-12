<?php

namespace App\Modules\Licensing\Http\Middleware;

use App\Modules\Licensing\Exceptions\LicenceException;
use App\Modules\Licensing\Services\LicenceService;
use App\Support\Http\ApiErrorRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the licence to every web and API request. While the licence runs (or in its grace period) nothing changes.
 * Read-only phase: looking, printing and exporting work, anything that changes data is refused (HTTP 402). Locked: only
 * signing in and out, the licence screen and what they need remain. Background jobs and scheduled commands are not
 * affected, so data keeps being protected and reconciled.
 */
final class EnforceLicence
{
    private const ALWAYS_OPEN = [
        'licence.*', 'api.v1.licence.*', 'login', 'login.store', 'logout', 'mfa.*', 'locale.update', 'branding.logo', 'notifications.*',
        'api.v1.auth.*', 'api.v1.health.*', 'api.v1.me', 'api.v1.notifications.*', 'api.v1.appearance.show',
    ];

    public function __construct(private readonly LicenceService $licence) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->licence->enforcing() || $request->routeIs(self::ALWAYS_OPEN)) {
            return $next($request);
        }

        $status = $this->licence->status();
        if (! $status->restricts()) {
            return $next($request);
        }

        $reading = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($status->state === 'readonly' && $reading) {
            return $next($request);
        }

        $locked = $status->state === 'locked';
        $error = LicenceException::make($locked ? 'locked' : 'read_only', __($locked ? 'licensing::ui.errors.locked' : 'licensing::ui.errors.read_only', ['date' => $status->validUntil]), 402);
        if (ApiErrorRenderer::wants($request)) {
            throw $error;
        }

        return $locked || $reading ? redirect()->route('licence.show') : back()->withErrors(['licence' => $error->getMessage()]);
    }
}
