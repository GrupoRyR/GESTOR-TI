<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login page and logout.
 *
 * The Microsoft callback reports failures through one-shot session flashes:
 * - LOGIN_ERROR: a code of `LoginProps['error']`, shown on `Auth/Login`;
 * - COMPANY_NOT_ENABLED: the verified Microsoft email of an account whose
 *   tenant/domain is not enabled, which renders `Auth/CompanyNotEnabled`
 *   on this same route (the design has no route of its own for it).
 */
class SessionController extends Controller
{
    public const LOGIN_ERROR = 'login_error';

    public const COMPANY_NOT_ENABLED = 'login_company_not_enabled';

    /** @var list<string> */
    public const ERROR_CODES = ['tenant_not_allowed', 'microsoft_failed'];

    public function create(Request $request): Response
    {
        // Pulled (not only flashed) so a reload never shows them again.
        $notEnabledEmail = $request->session()->pull(self::COMPANY_NOT_ENABLED);
        $error = $request->session()->pull(self::LOGIN_ERROR);

        if (is_string($notEnabledEmail) && $notEnabledEmail !== '') {
            return Inertia::render('Auth/CompanyNotEnabled', [
                'email' => $notEnabledEmail,
                'support_email' => config('gestor.support_email'),
            ]);
        }

        // `companies` repeats the guest shared list (same objects): the page
        // prop overwrites the shared key, so both must be identical.
        return Inertia::render('Auth/Login', [
            'companies' => Company::query()->active()->orderBy('name')->get()
                ->map(fn (Company $company) => $company->toSharedArray())
                ->values()
                ->all(),
            'error' => in_array($error, self::ERROR_CODES, true) ? $error : null,
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
