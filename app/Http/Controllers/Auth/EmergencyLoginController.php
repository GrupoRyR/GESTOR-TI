<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AttemptEmergencyLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmergencyLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Emergency local login (single configured account). Both routes answer 404
 * while `services.emergency.admin_email` is not configured.
 */
class EmergencyLoginController extends Controller
{
    public function create(): Response
    {
        abort_unless(AttemptEmergencyLogin::enabled(), 404);

        return Inertia::render('Auth/EmergencyLogin', []);
    }

    public function store(EmergencyLoginRequest $request, AttemptEmergencyLogin $attempt): RedirectResponse
    {
        $user = $attempt->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            (string) $request->ip(),
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
