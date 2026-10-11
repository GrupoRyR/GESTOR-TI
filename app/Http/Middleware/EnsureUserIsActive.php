<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the session of a user deactivated in Administración › Usuarios on
 * their next request (web group): logout, new session, back to the login
 * page with a notice. JSON requests get 401.
 */
class EnsureUserIsActive
{
    public const MESSAGE = 'Tu cuenta del Gestor TI está desactivada. Si crees que es un error, escribe a TI.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || $user->isActive()) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 401);
        }

        return redirect()->route('login')->with('error', self::MESSAGE);
    }
}
