<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AttemptEmergencyLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmergencyLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Emergency local login (`Auth/Emergency`, single configured account). Both
 * routes answer 404 while `services.emergency.admin_email` is not configured.
 *
 * `lockout` / `attempts_left` are null on a fresh visit; after a failed POST
 * they are flashed once from AttemptEmergencyLogin::throttleState(), which
 * treats every username alike (no account disclosure).
 */
class EmergencyLoginController extends Controller
{
    public const STATE = 'emergency_login_state';

    public function create(Request $request): Response
    {
        abort_unless(AttemptEmergencyLogin::enabled(), 404);

        $state = $request->session()->get(self::STATE);

        return Inertia::render('Auth/Emergency', [
            'lockout' => $state['lockout'] ?? null,
            'attempts_left' => $state['attempts_left'] ?? null,
        ]);
    }

    public function store(EmergencyLoginRequest $request, AttemptEmergencyLogin $attempt): RedirectResponse
    {
        $username = $request->string('username')->toString();
        $ip = (string) $request->ip();

        try {
            $user = $attempt->handle($username, $request->string('password')->toString(), $ip);
        } catch (ValidationException $e) {
            $request->session()->flash(self::STATE, AttemptEmergencyLogin::throttleState($username, $ip));

            throw $e;
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home', absolute: false));
    }
}
