<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Audit\AuditOrigin;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Registrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request access to the Gestor IA MCP server, after `auth:api`: the
 * token carries the `mcp:use` scope, the user is active and still holds
 * `ai.mcp.use` (consent alone is not enough: a removed role closes access
 * on the next call). Refusals are 403 with a JSON-RPC error body.
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
        $user = $request->user('api');

        if (! $user instanceof User || $user->tokenCant(Registrar::OAUTH_SCOPE)) {
            return $this->refuse($request, self::WRONG_SCOPE);
        }

        if (! $user->isActive()) {
            return $this->refuse($request, self::INACTIVE);
        }

        if (! $user->can(self::PERMISSION)) {
            return $this->refuse($request, self::FORBIDDEN);
        }

        return AuditOrigin::runAs(AuditOrigin::API, fn () => $next($request));
    }

    private function refuse(Request $request, string $message): JsonResponse
    {
        $id = $request->json('id');

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => is_int($id) || is_string($id) ? $id : null,
            'error' => ['code' => -32001, 'message' => $message],
        ], 403);
    }
}
