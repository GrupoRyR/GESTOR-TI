<?php

use App\Http\Middleware\EnsureModuleIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireRecentMicrosoftAuth;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'module' => EnsureModuleIsActive::class,
            'reauth' => RequireRecentMicrosoftAuth::class,
        ]);

        // Inactive modules must 404 before `auth` redirects guests to login,
        // so disabled routes are never disclosed.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureModuleIsActive::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
