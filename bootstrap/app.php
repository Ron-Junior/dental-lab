<?php

use App\Http\Middleware\EnsureCentralAdmin;
use App\Http\Middleware\EnsurePlanLimit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(except: [
            'webhook/*',
        ]);

        $middleware->redirectGuestsTo(
            fn (Request $request): string => $request->routeIs('central.*')
                ? route('central.login')
                : route('login', ['tenant' => $request->route('tenant')]),
        );

        $middleware->alias([
            'central.admin' => EnsureCentralAdmin::class,
            'plan.limit' => EnsurePlanLimit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        Integration::handles($exceptions);
    })->create();
