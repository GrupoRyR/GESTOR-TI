<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared props for every page (contract in PROMPT_GESTOR_IA_APP.md §7.1).
     *
     * Ziggy routes are not shared here: the root view's `@routes` directive
     * already exposes them to the frontend.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $user ? $this->authUser($user) : null,
            ],
            'companies' => fn () => $this->companies($user),
            'current_company_id' => fn () => $user ? $this->currentCompany->forUser($user) : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'notifications_unread' => fn () => $user ? $user->unreadNotifications()->count() : 0,
            'modules' => fn () => $this->modules->visibleTo($user),
        ];
    }

    /**
     * @return array{id: int, name: string, email: string, avatar_url: ?string, company_id: ?int, roles: list<string>, permissions: list<string>}
     */
    protected function authUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'company_id' => $user->company_id,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }

    /**
     * Active companies: every one for guests (`GuestSharedProps`, logos and
     * copy of the sign-in pages) and for users with `companies.view_all`,
     * otherwise only the user's own company.
     *
     * @return list<array{id: int, slug: string, name: string, short_name: string, logo_url: ?string, logo_dark_url: ?string, colors: array{primary: string, accent: string, dark: string}}>
     */
    protected function companies(?User $user): array
    {
        $query = Company::query()->active()->orderBy('name');

        if ($user !== null && ! $this->currentCompany->canViewAll($user)) {
            if ($user->company_id === null) {
                return [];
            }

            $query->whereKey($user->company_id);
        }

        return $query->get()
            ->map(fn (Company $company) => $company->toSharedArray())
            ->values()
            ->all();
    }
}
