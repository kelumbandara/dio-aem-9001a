<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side protections on every answer: no framing (clickjacking), no MIME sniffing, a Content-Security-Policy that only
 * allows our own scripts/styles/images/fonts, a referrer that does not leak paths, and HSTS once the site is served over https.
 *
 * Scripts: Vite tags and our two small inline scripts carry a per-request nonce. Alpine's standard build compiles the short
 * expressions in our templates with `new Function`, which needs 'unsafe-eval' (accepted, see docs/architecture/security.md §9).
 * While the Vite dev server runs (hot file present) the policy is not sent, because HMR needs other origins.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        Vite::useCspNonce($nonce);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (! $response->headers->has('Content-Security-Policy') && ! Vite::isRunningHot()) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        if ($request->isSecure() && config('core.security.hsts_seconds') > 0) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.(int) config('core.security.hsts_seconds').'; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
