<?php

namespace App\Http\Middleware;

use App\Support\Mcp\McpCallRecorder;
use App\Support\Mcp\McpContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audits every `tools/call` to the MCP server in `mcp_calls`, after
 * AuthenticateMcp and the rate limit and around the scope and access
 * checks, so refused calls are recorded as `rejected` too.
 */
class AuditMcpCalls
{
    public function __construct(private readonly McpCallRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $context = McpContext::of($request);

        if ($context !== null && $request->json('method') === 'tools/call') {
            $this->recorder->afterCall($request, $context, $response);
        }

        return $response;
    }
}
