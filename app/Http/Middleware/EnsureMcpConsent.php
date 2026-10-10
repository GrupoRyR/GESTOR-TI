<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Bridge\Client as BridgeClient;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User as BridgeUser;
use Laravel\Passport\Client;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth consent of the MCP connector (GET/POST /oauth/authorize, after the
 * `web` group so EnsureUserIsActive already logged out inactive users):
 *
 * - a signed-in user without `ai.mcp.use` gets the Spanish "no tienes
 *   permiso" page (403) instead of the consent screen or an approval, also
 *   when Passport would auto-approve an earlier grant;
 * - a successful authorization (redirect carrying `code`) is audited
 *   (`auth` log, event `mcp_client_authorized`, origin `api`).
 *
 * Guests pass through: Passport sends them to the existing login, which
 * returns to the intended authorize URL.
 */
class EnsureMcpConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->can(EnsureMcpAccess::PERMISSION)) {
            $request->session()->forget(['authToken', 'authRequest']);

            return response()->view('mcp.forbidden', ['user' => $user], 403);
        }

        $clientId = $request->isMethod('GET')
            ? $request->query('client_id')
            : $this->pendingClientId($request);

        $response = $next($request);

        if (is_string($clientId) && $this->issuedCode($response)) {
            $this->audit($user, $clientId);
        }

        return $response;
    }

    private function pendingClientId(Request $request): ?string
    {
        $serialized = $request->session()->get('authRequest');

        if (! is_string($serialized)) {
            return null;
        }

        $authRequest = unserialize($serialized, ['allowed_classes' => [
            AuthorizationRequest::class, BridgeClient::class, Scope::class, BridgeUser::class,
        ]]);

        return $authRequest instanceof AuthorizationRequest ? $authRequest->getClient()->getIdentifier() : null;
    }

    private function issuedCode(Response $response): bool
    {
        // Passport converts its PSR response: a plain redirect with Location.
        if (! $response->isRedirection()) {
            return false;
        }

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return isset($query['code']);
    }

    private function audit(User $user, string $clientId): void
    {
        $client = Client::query()->find($clientId);

        activity('auth')
            ->causedBy($user)
            ->event('mcp_client_authorized')
            ->withProperties([
                'origin' => 'api',
                'client_id' => $clientId,
                'client_name' => $client?->name,
            ])
            ->log('Conector MCP autorizado para '.($client?->name ?? 'un cliente OAuth'));
    }
}
