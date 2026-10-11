<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Mcp\McpContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Registrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request access to the Gestor IA MCP server, after AuthenticateMcp:
 * an OAuth token carries the `mcp:use` scope (personal tokens are the
 * connector's by construction), the user is active and still holds
 * `ai.mcp.use` (consent or a token alone is not enough: a removed role
 * closes access on the next call). Refusals are 403 with a JSON-RPC error
 * body; the reason is left for the call audit (McpContext::REJECTION).
 *
 * Everything the server does is audited with origin `api`.
 */
class EnsureMcpAccess
{
    public const PERMISSION = 'ai.mcp.use';

    public const FORBIDDEN = 'No tienes permiso para usar el conector del Gestor IA.';

    public const INACTIVE = 'Tu cuenta del Gestor TI está desactivada.';

    public const WRONG_SCOPE = 'El token no autoriza el conector del Gestor IA.';

    public function handle(Request $request, Closure $next): Response
    {
        $context = McpContext::of($request);
        $user = $context?->user;

        if (! $user instanceof User || ($context->token === null && $user->tokenCant(Registrar::OAUTH_SCOPE))) {
            return $this->refuse($request, self::WRONG_SCOPE, 'token sin el alcance mcp:use');
        }

        if (! $user->isActive()) {
            return $this->refuse($request, self::INACTIVE, 'cuenta desactivada');
        }

        if (! $user->can(self::PERMISSION)) {
            return $this->refuse($request, self::FORBIDDEN, 'sin permiso ai.mcp.use');
        }

        return AuditOrigin::runAs(AuditOrigin::API, fn () => $next($request));
    }

    private function refuse(Request $request, string $message, string $reason): JsonResponse
    {
        $request->attributes->set(McpContext::REJECTION, $reason);
        $id = $request->json('id');

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => is_int($id) || is_string($id) ? $id : null,
            'error' => ['code' => -32001, 'message' => $message],
        ], 403);
    }
}
