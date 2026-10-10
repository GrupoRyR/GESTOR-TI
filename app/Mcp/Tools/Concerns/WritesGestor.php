<?php

namespace App\Mcp\Tools\Concerns;

use App\Support\Mcp\McpContext;

/**
 * Write tools are listed only to callers that may write (`read_write`
 * personal tokens and OAuth). Hiding is a convenience: a direct call from
 * a `read` token is refused with 403 by App\Http\Middleware\EnforceMcpScope.
 */
trait WritesGestor
{
    public function shouldRegister(): bool
    {
        return McpContext::current()?->canWrite() ?? false;
    }
}
