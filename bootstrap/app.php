<?php

use App\Modules\Core\Http\Middleware\AssignRequestContext;
use App\Modules\Core\Http\Middleware\SecurityHeaders;
use App\Modules\Core\Http\Middleware\SetLocale;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\ApiErrorRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Web and API routes are registered by the module providers (see ModuleServiceProvider).
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Coolify/Traefik the app sees the proxy, not the visitor: TRUSTED_PROXIES ('*' = the proxy in front, which is the only way in)
        // makes https, the host and the client address come from its headers (rate limits and the audit log need the real address).
        $proxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->prepend(AssignRequestContext::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [SetLocale::class]);
        $middleware->api(append: [SetLocale::class]);
        $middleware->throttleApi(); // limiter "api" is defined by the Core provider
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => ApiErrorRenderer::wants($request));

        $exceptions->render(function (Throwable $e, Request $request) {
            // A value PostgreSQL cannot read as the column's type (SQLSTATE 22P02: "abc" where a number or UUID belongs, typically
            // in a web address) can match nothing: that is "not found", not a server error.
            $unreadable = $e instanceof QueryException && $e->getCode() === '22P02';
            if ($unreadable) {
                $e = new NotFoundHttpException('Not found.', $e);
            }

            if (ApiErrorRenderer::wants($request)) {
                return ApiErrorRenderer::render($e, $request);
            }

            if ($unreadable) {
                throw $e;
            }

            // Business rules raised outside an API call (e.g. a disabled module) become plain HTTP errors.
            if ($e instanceof BusinessException) {
                throw new HttpException($e->status, $e->getMessage(), $e);
            }

            return null;
        });
    })->create();
