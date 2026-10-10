<?php

use App\Http\Middleware\EnsureMcpAccess;
use App\Http\Middleware\EnsureMcpConsent;
use App\Mcp\Servers\GestorIaServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

/*
|--------------------------------------------------------------------------
| MCP connector of the Gestor IA (Stage E, PROMPT §6.4.7 step 3)
|--------------------------------------------------------------------------
|
| Loaded by laravel/mcp (no middleware group by default). Passport's own
| routes are off (App\Providers\McpServiceProvider); only what Claude's
| OAuth client needs is registered here:
|   - RFC 9728 / 8414 metadata under /.well-known and DCR at /oauth/register
|     (Mcp::oauthRoutes, redirect URIs limited by config/mcp.php);
|   - POST /oauth/token (form-urlencoded, PKCE S256);
|   - /oauth/authorize: existing web login, consent screen, approve, deny;
|   - the server itself, behind the user's bearer token and `ai.mcp.use`.
|
*/

Route::middleware('throttle:mcp-oauth')->group(function () {
    Mcp::oauthRoutes();

    Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])->name('passport.token');
});

Route::middleware(['web', EnsureMcpConsent::class])->group(function () {
    Route::get('/oauth/authorize', [AuthorizationController::class, 'authorize'])
        ->name('passport.authorizations.authorize');
    Route::post('/oauth/authorize', [ApproveAuthorizationController::class, 'approve'])
        ->middleware('auth')
        ->name('passport.authorizations.approve');
});

Route::delete('/oauth/authorize', [DenyAuthorizationController::class, 'deny'])
    ->middleware(['web', 'auth'])
    ->name('passport.authorizations.deny');

Mcp::web('/mcp/gestor-ia', GestorIaServer::class)
    ->middleware(['throttle:mcp', 'auth:api', EnsureMcpAccess::class])
    ->name('mcp.gestor-ia');
