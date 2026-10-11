<?php

namespace App\Http\Middleware;

use App\Support\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `module:{key}`: answers 404 when the module is inactive,
 * so routes of disabled modules are unreachable (and not disclosed).
 */
class EnsureModuleIsActive
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->modules->isActive($module), 404);

        return $next($request);
    }
}
