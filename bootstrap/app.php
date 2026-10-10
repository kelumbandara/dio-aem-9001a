<?php

use App\Modules\Core\Http\Middleware\AssignRequestContext;
use App\Modules\Core\Http\Middleware\SetLocale;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\ApiErrorRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Web and API routes are registered by the module providers (see ModuleServiceProvider).
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestContext::class);
        $middleware->web(append: [SetLocale::class]);
        $middleware->api(append: [SetLocale::class]);
        $middleware->throttleApi(); // limiter "api" is defined by the Core provider
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => ApiErrorRenderer::wants($request));

        $exceptions->render(function (Throwable $e, Request $request) {
            if (ApiErrorRenderer::wants($request)) {
                return ApiErrorRenderer::render($e, $request);
            }

            // Business rules raised outside an API call (e.g. a disabled module) become plain HTTP errors.
            if ($e instanceof BusinessException) {
                throw new HttpException($e->status, $e->getMessage(), $e);
            }

            return null;
        });
    })->create();
