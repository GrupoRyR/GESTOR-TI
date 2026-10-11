<?php

use App\Http\Middleware\EnsureModuleIsActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureVaultAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireRecentMicrosoftAuth;
use App\Support\ErrorPage;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // EnsureUserIsActive runs inside Inertia's middleware so its redirect
        // gets the 303 conversion for PUT/PATCH/DELETE visits.
        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'module' => EnsureModuleIsActive::class,
            'reauth' => RequireRecentMicrosoftAuth::class,
            'vault' => EnsureVaultAccess::class,
        ]);

        // Exact whitespace for Markdown (docs autosave keeps indentation and
        // the trailing newline) and for secrets (vault values, passwords).
        $middleware->trimStrings(except: [
            'markdown',
            'value',
            'secret',
            'password',
            'password_confirmation',
            'current_password',
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
            fn (Request $request) => $request->is('api/*', 'mcp/*') || $request->expectsJson(),
        );

        // Design error page (`Errors/Error`, types/pages/errors.ts) for web
        // requests; JSON/API responses (including the credential reveal
        // endpoint) keep Laravel's JSON.
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => ErrorPage::render($response, $request));
    })->create();
