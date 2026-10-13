<?php

namespace App\Modules\Core\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Behind Coolify/Traefik the app sees the proxy, not the visitor. TRUSTED_PROXIES (config core.security.trusted_proxies) names the
 * proxies whose X-Forwarded-* headers are believed: "*" = the proxy in front of the app (the only way in), or a comma-separated list
 * of addresses/ranges. Empty = no proxy is trusted (local development). Read from configuration at request time, so it also works
 * when the configuration is cached.
 */
class TrustProxiesFromConfig extends TrustProxies
{
    /** @return array<int, string>|string|null */
    protected function proxies()
    {
        $value = trim((string) config('core.security.trusted_proxies', ''));

        return match (true) {
            $value === '' => null,
            $value === '*' => '*',
            default => array_map('trim', explode(',', $value)),
        };
    }
}
