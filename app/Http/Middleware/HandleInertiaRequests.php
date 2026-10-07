<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
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

    public function __construct(private readonly ModuleRegistry $modules) {}

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
            'companies' => fn () => $this->companies($request),
            'current_company_id' => fn () => $this->currentCompanyId($request),
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
            // Columns added by later tasks (A3 companies, A5 Microsoft login).
            'avatar_url' => $user->getAttribute('avatar_url'),
            'company_id' => $user->getAttribute('company_id'),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }

    /**
     * Companies available to the current user (filled in by A3).
     *
     * @return list<array{id: int, slug: string, name: string, short_name: string, logo_url: ?string, logo_dark_url: ?string, colors: array{primary: string, accent: string, dark: string}}>
     */
    protected function companies(Request $request): array
    {
        return [];
    }

    /**
     * Selected company, or null for "Todas" (filled in by A3).
     */
    protected function currentCompanyId(Request $request): ?int
    {
        return null;
    }
}
