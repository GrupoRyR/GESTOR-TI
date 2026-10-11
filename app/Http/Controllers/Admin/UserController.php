<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\DeactivateUser;
use App\Actions\Users\UpdateUserRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRolesRequest;
use App\Models\Area;
use App\Models\Role;
use App\Models\User;
use App\Support\Paginated;
use App\Support\RoleMatrix;
use App\Support\UserDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Usuarios (`Admin/Users/Index`): everyone with a Gestor
 * account in the admin's company scope (UserDirectory), filters `q` (name
 * or email) and `role`, the detail panel (`admin.users.show`), roles with
 * the approved area, and deactivation.
 */
class UserController extends Controller
{
    public const PER_PAGE = 25;

    /** @var array<string, int>|null role name => matrix position */
    private ?array $roleOrder = null;

    public function __construct(private readonly UserDirectory $directory) {}

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function show(Request $request, User $user): Response
    {
        $this->directory->ensureVisible($user);

        return $this->render($request, $user);
    }

    public function updateRoles(UpdateUserRolesRequest $request, User $user, UpdateUserRoles $action): RedirectResponse
    {
        $action->handle($request->user(), $user, $request->roleNames(), $request->approverAreaId());

        return back()->with('success', "Roles de {$user->name} actualizados.");
    }

    public function deactivate(Request $request, User $user, DeactivateUser $action): RedirectResponse
    {
        $this->directory->ensureVisible($user);
        abort_if($request->user()?->is($user), 403, 'No puedes desactivar tu propia cuenta.');

        $action->handle($request->user(), $user);

        return back()->with('success', "{$user->name} quedó desactivado.");
    }

    private function render(Request $request, ?User $selected): Response
    {
        $q = $request->string('q')->squish()->value() ?: null;
        $role = $request->query('role');
        $role = is_string($role) && Role::query()->where('name', $role)->where('guard_name', 'web')->exists() ? $role : null;

        $paginator = $this->directory->query()
            ->with(['roles:id,name', 'telegramLink'])
            ->when($q, fn (Builder $query, string $q) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%'.$q.'%')
                ->orWhere('email', 'like', '%'.$q.'%')))
            ->when($role, fn (Builder $query, string $role) => $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', $role)))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => Paginated::from($paginator, fn (User $user) => $this->row($user)),
            'filters' => ['q' => $q, 'role' => $role],
            'selected' => $selected === null ? null : $this->detail($selected),
            'role_options' => RoleMatrix::roleOptions(),
            'area_options' => Area::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'company_id'])
                ->map(fn (Area $area) => ['id' => $area->id, 'name' => $area->name, 'company_id' => $area->company_id])
                ->values()
                ->all(),
            'vault_holder_name' => RoleMatrix::vaultHolderName(),
        ]);
    }

    /**
     * `AdminUserRow`.
     *
     * @return array<string, mixed>
     */
    private function row(User $user): array
    {
        $roleOrder = $this->roleOrder ??= Role::query()->inMatrixOrder()->pluck('name')->flip()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'company_id' => $user->company_id,
            'roles' => $user->roles->pluck('name')->sortBy(fn (string $name) => $roleOrder[$name] ?? PHP_INT_MAX)->values()->all(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'telegram_linked' => $user->telegramLink !== null && $user->telegramLink->isLinked(),
            'is_local' => $user->isLocal(),
            'active' => $user->isActive(),
        ];
    }

    /**
     * `AdminUserDetail`: the row plus job title and area of the linked
     * employee, and the area the user approves.
     *
     * @return array<string, mixed>
     */
    private function detail(User $user): array
    {
        $user->load(['roles:id,name', 'telegramLink', 'employee']);
        $employee = $user->employee;

        return [
            ...$this->row($user),
            'job_title' => $employee?->job_title,
            'area_name' => $employee?->area_id === null
                ? null
                : Area::withoutGlobalScopes()->whereKey($employee->area_id)->value('name'),
            'approver_area_id' => Area::withoutGlobalScopes()
                ->where('approver_user_id', $user->id)
                ->orderBy('id')
                ->value('id'),
        ];
    }
}
