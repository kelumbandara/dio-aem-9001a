<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * API: Accept-Language, then the user's saved locale. Web: session, then the user's locale.
 * Unsupported values are skipped. The application default is applied first on every request so a
 * long-lived worker never carries one request's locale into the next.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(config('core.default_locale'));

        $candidates = $request->is('api/*')
            ? [$this->fromAcceptLanguage($request), $request->user()?->locale]
            : [$request->hasSession() ? $request->session()->get('locale') : null, $request->user()?->locale];

        foreach ($candidates as $locale) {
            if (is_string($locale) && in_array($locale, config('core.locales'), true)) {
                App::setLocale($locale);
                break;
            }
        }

        return $next($request);
    }

    /**
     * First supported language the client asked for ("fr, ta;q=0.8" -> "ta"). Only an explicit
     * header counts: Symfony invents "en_US, en" when it is absent, which must not beat the user's setting.
     */
    private function fromAcceptLanguage(Request $request): ?string
    {
        if (! $request->headers->has('Accept-Language')) {
            return null;
        }

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok(str_replace('_', '-', $language), '-'));
            if (in_array($primary, config('core.locales'), true)) {
                return $primary;
            }
        }

        return null;
    }
}
