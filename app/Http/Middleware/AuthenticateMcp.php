<?php

namespace App\Http\Middleware;

use App\Models\McpToken;
use App\Models\User;
use App\Support\Mcp\McpCallRecorder;
use App\Support\Mcp\McpContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentication of the unified MCP endpoint `POST /mcp`:
 *
 * - `Authorization: Bearer gti_mcp_…` → personal token (Profile/Mcp),
 *   looked up by its SHA-256. Unknown, expired or revoked → 401 (an
 *   expired or revoked token's attempt is audited as `unauthorized`).
 *   A valid one records `last_used_at` and, on `initialize`, the client
 *   name (`clientInfo.name`).
 * - any other bearer → Passport (`api` guard, OAuth of claude.ai).
 *
 * The 401 gets its `WWW-Authenticate` challenge from laravel/mcp's
 * AddWwwAuthenticateHeader. The caller is kept as App\Support\Mcp\McpContext.
 * `gestor.mcp.enabled = false` answers 404 (server not active).
 */
class AuthenticateMcp
{
    public function __construct(private readonly McpCallRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('gestor.mcp.enabled', true)) {
            return response()->json(['message' => 'El conector aún no está activo en este servidor.'], 404);
        }

        $bearer = (string) $request->bearerToken();
        $context = str_starts_with($bearer, McpToken::PREFIX)
            ? $this->personal($request, $bearer)
            : $this->oauth();

        if ($context === null) {
            return $this->unauthenticated();
        }

        Auth::shouldUse('api');
        Auth::guard('api')->setUser($context->user);
        $request->attributes->set(McpContext::ATTRIBUTE, $context);

        return $next($request);
    }

    private function personal(Request $request, string $value): ?McpContext
    {
        $token = McpToken::findByValue($value);

        if ($token === null) {
            return null;
        }

        if ($token->isRevoked() || $token->isExpired()) {
            $this->recorder->unauthorized($request, $token, $token->isRevoked() ? 'token revocado' : 'token vencido');

            return null;
        }

        $user = $token->user;

        if (! $user instanceof User) {
            return null;
        }

        $this->touch($request, $token);

        return new McpContext($user, $token);
    }

    private function oauth(): ?McpContext
    {
        $user = Auth::guard('api')->user();

        if (! $user instanceof User) {
            return null;
        }

        $token = $user->token();
        $client = $token instanceof AccessToken ? $token->oauth_client_id : null;

        return new McpContext($user, null, $client === null ? null : (string) $client);
    }

    private function touch(Request $request, McpToken $token): void
    {
        $token->last_used_at = now();

        if ($request->json('method') === 'initialize') {
            $client = $request->json('params.clientInfo.name');

            if (is_string($client) && trim($client) !== '' && stripos($client, McpToken::PREFIX) === false) {
                $token->last_client = Str::limit(Str::squish($client), 97);
            }
        }

        $token->saveQuietly();
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
