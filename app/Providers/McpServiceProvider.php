<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Mcp\LoopbackAwareAuthCodeGrant;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Client;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;

/**
 * OAuth server (Passport) for the Gestor IA MCP connector (Stage E).
 *
 * - Passport's own routes are off: routes/ai.php registers only the token
 *   endpoint and the authorize/approve/deny routes (consent checks, rate
 *   limits) next to `Mcp::oauthRoutes()` and the server.
 * - Single scope `mcp:use` (also the default when a client sends none).
 * - Token lifetimes from `gestor.mcp` (access 1 h, refresh 30 days).
 * - Authorization code grant with loopback redirects on any port.
 * - Token issuance is audited (`auth` log, `mcp_token_issued`, origin `api`).
 */
class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::ignoreRoutes();

        $this->app->extend(AuthorizationServer::class, function (AuthorizationServer $server, $app): AuthorizationServer {
            $grant = new LoopbackAwareAuthCodeGrant(
                $app->make(AuthCodeRepository::class),
                $app->make(RefreshTokenRepository::class),
                new DateInterval('PT10M'),
            );
            $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());

            // Same identifier: replaces Passport's authorization_code grant.
            $server->enableGrantType($grant, Passport::tokensExpireIn());

            return $server;
        });
    }

    public function boot(): void
    {
        Passport::tokensCan([Registrar::OAUTH_SCOPE => 'Consultar y actualizar el Gestor IA en tu nombre']);
        Passport::setDefaultScope([Registrar::OAUTH_SCOPE]);
        Passport::tokensExpireIn(now()->addMinutes((int) config('gestor.mcp.access_token_minutes', 60)));
        Passport::refreshTokensExpireIn(now()->addDays((int) config('gestor.mcp.refresh_token_days', 30)));
        Passport::authorizationView('mcp.authorize');

        // MCP calls: per user (each may hit the database several times).
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute((int) config('gestor.mcp.rate_limit_per_minute', 120))
            ->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Discovery, client registration and token endpoint: per IP.
        RateLimiter::for('mcp-oauth', fn (Request $request) => Limit::perMinute((int) config('gestor.mcp.oauth_rate_limit_per_minute', 30))
            ->by('mcp-oauth:'.$request->ip()));

        Event::listen(AccessTokenCreated::class, function (AccessTokenCreated $event): void {
            $user = $event->userId === null ? null : User::query()->find($event->userId);
            $client = Client::query()->find($event->clientId);

            activity('auth')
                ->causedBy($user)
                ->event('mcp_token_issued')
                ->withProperties([
                    'origin' => 'api',
                    'client_id' => $event->clientId,
                    'client_name' => $client?->name,
                ])
                ->log('Token del conector MCP emitido para '.($client?->name ?? 'un cliente OAuth'));
        });
    }
}
