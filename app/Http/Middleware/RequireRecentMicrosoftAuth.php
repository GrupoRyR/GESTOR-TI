<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `reauth:{minutes}` (vault, PROMPT 6.4.10): requires a
 * Microsoft re-authentication (`prompt=login`) within the last N minutes
 * (default 10). Otherwise it remembers the URL (GET only) and redirects to
 * `auth.microsoft.reauth`. Use after `auth`.
 *
 * The timestamp is stored as a Unix timestamp in
 * `session('auth.reauthenticated_at')` by the Microsoft callback.
 */
class RequireRecentMicrosoftAuth
{
    public const SESSION_KEY = 'auth.reauthenticated_at';

    public function handle(Request $request, Closure $next, string $minutes = '10'): Response
    {
        $at = $request->session()->get(self::SESSION_KEY);

        if (is_numeric($at) && (int) $at >= now()->subMinutes((int) $minutes)->getTimestamp()) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('auth.microsoft.reauth');
    }
}
