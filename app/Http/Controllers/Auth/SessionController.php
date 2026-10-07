<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AttemptEmergencyLogin;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login page (Microsoft first, emergency access link when configured) and
 * logout.
 */
class SessionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'microsoft_url' => route('auth.microsoft.redirect'),
            'emergency_enabled' => AttemptEmergencyLogin::enabled(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
