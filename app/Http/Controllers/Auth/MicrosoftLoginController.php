<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginWithMicrosoft;
use App\Actions\Auth\MicrosoftIdentity;
use App\Actions\Auth\MicrosoftLoginRejected;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentMicrosoftAuth;
use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Microsoft (Entra ID) multitenant sign-in through Socialite (`microsoft`
 * driver, tenant from `services.microsoft.tenant`, `organizations` by
 * default). It only maps Socialite to a MicrosoftIdentity; the rules live in
 * App\Actions\Auth\LoginWithMicrosoft.
 *
 * The same callback serves the re-authentication used by the vault
 * (`reauth:{minutes}` middleware): when the session carries the reauth
 * marker, the returning account must be the signed-in user's.
 */
class MicrosoftLoginController extends Controller
{
    public const REAUTH_MARKER = 'auth.reauth_azure_id';

    private const SCOPES = ['openid', 'profile', 'email', 'User.Read'];

    private const PROVIDER_ERROR = 'No se pudo iniciar sesión con Microsoft. Intenta de nuevo.';

    private const REAUTH_MISMATCH = 'La verificación no corresponde a tu cuenta de Microsoft.';

    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('microsoft')->scopes(self::SCOPES)->redirect();
    }

    /**
     * Forces a fresh Microsoft sign-in (`prompt=login`) for the current user.
     */
    public function reauth(Request $request): SymfonyRedirect
    {
        /** @var User $user */
        $user = $request->user();

        abort_if($user->azure_id === null, 403, 'La verificación requiere una cuenta de Microsoft.');

        $request->session()->put(self::REAUTH_MARKER, $user->azure_id);

        return Socialite::driver('microsoft')
            ->scopes(self::SCOPES)
            ->with(['prompt' => 'login', 'login_hint' => $user->email])
            ->redirect();
    }

    public function callback(Request $request, LoginWithMicrosoft $login): RedirectResponse
    {
        $reauthFor = $request->session()->pull(self::REAUTH_MARKER);
        $reauthenticating = $reauthFor !== null && Auth::check();

        try {
            $identity = $this->identity();
        } catch (InvalidStateException|GuzzleException $e) {
            Log::warning('Microsoft callback failed', ['error' => $e->getMessage()]);

            return $reauthenticating
                ? redirect()->route('home')->with('error', self::PROVIDER_ERROR)
                : redirect()->route('login')->with('error', self::PROVIDER_ERROR);
        }

        if ($reauthenticating) {
            return $this->completeReauth($request, $identity, (string) $reauthFor);
        }

        try {
            $user = $login->handle($identity);
        } catch (MicrosoftLoginRejected $e) {
            Log::warning('Microsoft login rejected', [
                'reason' => $e->reason,
                'tenant_id' => $identity->tenantId,
                'email' => $identity->email,
            ]);

            return redirect()->route('login')->with('error', $e->getMessage());
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home', absolute: false));
    }

    private function completeReauth(Request $request, MicrosoftIdentity $identity, string $expected): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($identity->azureId !== $expected || $identity->azureId !== $user->azure_id) {
            Log::warning('Microsoft re-authentication mismatch', [
                'user_id' => $user->id,
                'tenant_id' => $identity->tenantId,
            ]);

            return redirect()->route('home')->with('error', self::REAUTH_MISMATCH);
        }

        $request->session()->put(RequireRecentMicrosoftAuth::SESSION_KEY, now()->getTimestamp());

        return redirect()->intended(route('home', absolute: false));
    }

    /**
     * The tenant (`tid`) comes from the id_token claims. The provider
     * (socialiteproviders/microsoft 4.x `getClaims()`) validates that token's
     * signature against Microsoft's JWKS plus iss/aud/exp, so no claim is
     * decoded here without verification. No claims → no tenant → rejected.
     */
    private function identity(): MicrosoftIdentity
    {
        $driver = Socialite::driver('microsoft');
        /** @var SocialiteUser $socialUser */
        $socialUser = $driver->user();

        $raw = method_exists($socialUser, 'getRaw') ? (array) $socialUser->getRaw() : [];
        $upn = (string) ($raw['userPrincipalName'] ?? $socialUser->getEmail() ?? '');
        $email = (string) (($raw['mail'] ?? null) ?: $upn);
        $claims = method_exists($driver, 'getClaims') ? $driver->getClaims() : null;

        return new MicrosoftIdentity(
            azureId: (string) $socialUser->getId(),
            email: $email,
            name: (string) ($socialUser->getName() ?? ''),
            tenantId: isset($claims->tid) ? (string) $claims->tid : null,
            avatarUrl: null,
            external: str_contains(strtoupper($upn), '#EXT#'),
        );
    }
}
