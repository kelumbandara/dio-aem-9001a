<?php

use App\Http\Middleware\OwnerOnly;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['owner' => OwnerOnly::class]);
        // PayHere posts from its own servers, so it cannot carry our CSRF token (the message is verified by its signature instead).
        $middleware->validateCsrfTokens(except: ['payhere/notify']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
