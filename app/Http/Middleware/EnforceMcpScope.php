<?php

namespace App\Http\Middleware;

use App\Support\Mcp\McpContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A `read` personal token calling a write tool gets HTTP 403 (contract of
 * types/pages/profile-mcp.ts), logged as rejected "token de solo lectura".
 * tools/list already hides the write tools from it (shouldRegister in
 * App\Mcp\Tools\Concerns\WritesGestor), but the call is refused here
 * whatever the client sends.
 */
class EnforceMcpScope
{
    public const READ_ONLY = 'token de solo lectura';

    public function handle(Request $request, Closure $next): Response
    {
        $context = McpContext::of($request);
        $tool = $request->json('params.name');

        if ($context !== null && ! $context->canWrite() && $request->json('method') === 'tools/call'
            && is_string($tool) && McpContext::isWriteTool($tool)) {
            $request->attributes->set(McpContext::REJECTION, self::READ_ONLY);
            $id = $request->json('id');

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => is_int($id) || is_string($id) ? $id : null,
                'error' => ['code' => -32001, 'message' => "Este token es de solo lectura: no puede usar {$tool}."],
            ], 403);
        }

        return $next($request);
    }
}
