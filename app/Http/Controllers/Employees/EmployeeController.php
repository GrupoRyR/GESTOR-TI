<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Jobs\SyncCompanyEmployees;
use App\Models\AccessSystem;
use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAccess;
use App\Models\User;
use App\Services\Credentials\EmployeeCredentialService;
use App\Support\EmployeeSyncStatus;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Colaboradores (`employees.*`, module `employees`). Listing and route
 * binding honour `CompanyScope` (employees of other companies are 404).
 * Passwords never leave the server here: only
 * `EmployeeAccessController::reveal` returns one.
 *
 * Directory actions (create, "Sincronizar ahora") accept `ai.manage` (what
 * the design shows them for) or `employees.manage`.
 */
class EmployeeController extends Controller
{
    public const PER_PAGE = 25;

    /** Status filter values (`EmployeeStatusFilter`); `active` by default. */
    public const STATUS_FILTERS = ['active', 'inactive', 'all'];

    /** Permissions that unlock create and sync. */
    public const DIRECTORY_PERMISSIONS = ['ai.manage', 'employees.manage'];

    public const CREATE_NOTICE = 'La creación manual de colaboradores llega pronto.';

    public function index(Request $request, CurrentCompany $currentCompany, EmployeeSyncStatus $syncStatus): Response
    {
        /** @var User $user */
        $user = $request->user();

        $q = $request->string('q')->squish()->value() ?: null;
        $companyId = $currentCompany->canViewAll($user) ? ($request->integer('company_id') ?: null) : null;
        $withoutArea = $request->boolean('without_area');
        $areaId = $withoutArea ? null : ($request->integer('area_id') ?: null);
        $status = in_array($request->query('status'), self::STATUS_FILTERS, true) ? (string) $request->query('status') : 'active';

        // Directory of the visible companies (CompanyScope + company filter).
        $directory = fn (): Builder => Employee::query()
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId));

        $paginator = $directory()
            ->with(['area:id,name', 'manager:id,name', 'user.roles:id,name'])
            ->withCount('accesses')
            ->when($q, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%'.$q.'%')
                ->orWhere('email', 'like', '%'.$q.'%')
                ->orWhere('job_title', 'like', '%'.$q.'%')))
            ->when($withoutArea, fn (Builder $query) => $query->whereNull('area_id'))
            ->when($areaId, fn (Builder $query) => $query->where('area_id', $areaId))
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $showAccesses = $user->can(EmployeeCredentialService::PERMISSION);

        return Inertia::render('Employees/Index', [
            'employees' => [
                'data' => collect($paginator->items())
                    ->map(fn (Employee $employee): array => $this->row($employee, $showAccesses))
                    ->all(),
                'links' => $paginator->linkCollection()->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'filters' => [
                'q' => $q,
                'company_id' => $companyId,
                'area_id' => $areaId,
                'status' => $status,
                'without_area' => $withoutArea,
            ],
            'areas' => Area::query()
                ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'company_id'])
                ->map(fn (Area $area): array => ['id' => $area->id, 'name' => $area->name, 'company_id' => $area->company_id])
                ->values()
                ->all(),
            // Size of the active directory, whatever the other filters.
            'counts' => [
                'without_area' => $directory()->where('status', Employee::STATUS_ACTIVE)->whereNull('area_id')->count(),
                'total' => $directory()->where('status', Employee::STATUS_ACTIVE)->count(),
            ],
            ...$syncStatus->summary($this->syncCompanies($currentCompany, $companyId)),
        ]);
    }

    /**
     * No design page yet for manual creation: back to the list with a notice.
     */
    public function create(Request $request): RedirectResponse
    {
        $this->authorizeDirectory($request);

        return redirect()->route('employees.index')->with('error', self::CREATE_NOTICE);
    }

    public function show(Request $request, Employee $employee): Response
    {
        $employee->load(['area:id,name', 'manager:id,name']);

        $accesses = $employee->accesses()
            ->select(['id', 'employee_id', 'access_system_id', 'username', 'notes', 'last_rotated_at'])
            ->with('system:id,name')
            ->orderBy('access_system_id')
            ->orderBy('username')
            ->get();

        $user = $request->user();
        // Usernames and notes of accesses are credential data: only for
        // `credentials.view` (the same gate as revealing a password).
        $canReveal = (bool) $user?->can(EmployeeCredentialService::PERMISSION);

        return Inertia::render('Admin/Employees/Show', [
            'employee' => $employee->toListArray() + [
                'area_locked' => $employee->area_locked,
                'manager' => $employee->manager ? ['id' => $employee->manager->id, 'name' => $employee->manager->name] : null,
            ],
            'accesses' => $accesses->map(fn (EmployeeAccess $access) => [
                'id' => $access->id,
                'system' => ['id' => $access->system->id, 'name' => $access->system->name],
                'username' => $canReveal ? $access->username : null,
                'notes' => $canReveal ? $access->notes : null,
                'last_rotated_at' => $access->last_rotated_at?->toIso8601String(),
            ])->values()->all(),
            'can_reveal' => $canReveal,
            'can_manage' => (bool) $user?->can('employees.manage'),
            'areas' => Area::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Area $area) => ['id' => $area->id, 'name' => $area->name])
                ->all(),
            'access_systems' => AccessSystem::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (AccessSystem $system) => ['id' => $system->id, 'name' => $system->name])
                ->all(),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        if ($request->has('area_id')) {
            $areaId = $request->validated('area_id');
            $employee->assignArea($areaId === null ? null : (int) $areaId);
        }

        if ($request->has('status')) {
            $employee->update(['status' => $request->validated('status')]);
        }

        return back()->with('success', 'Colaborador actualizado.');
    }

    /**
     * Queues the Graph sync for the current company, or for every active
     * company with a tenant in the "Todas" context.
     */
    public function sync(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $this->authorizeDirectory($request);

        $companies = $currentCompany->isAll()
            ? Company::query()->syncable()->get()
            : Company::query()->whereKey($currentCompany->id())->get();

        $syncable = $companies->filter(fn (Company $company) => $company->isSyncable());

        if ($syncable->isEmpty()) {
            return back()->with('error', 'La empresa no tiene un inquilino de Microsoft configurado.');
        }

        $syncable->each(fn (Company $company) => SyncCompanyEmployees::dispatch($company->id));

        return back()->with('success', 'Sincronización de colaboradores en cola.');
    }

    /**
     * Row of `Employees/Index` (`EmployeeRow`). The access count is only
     * sent to `credentials.view` holders (the only ones who see the column).
     *
     * @return array{id: int, name: string, email: ?string, job_title: ?string, area: ?array{id: int, name: string}, company_id: int, manager_name: ?string, user: ?array{roles: list<string>}, accesses_count: int}
     */
    private function row(Employee $employee, bool $showAccesses): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'job_title' => $employee->job_title,
            'area' => $employee->area ? ['id' => $employee->area->id, 'name' => $employee->area->name] : null,
            'company_id' => $employee->company_id,
            'manager_name' => $employee->manager?->name,
            'user' => $employee->user ? ['roles' => $employee->user->roles->pluck('name')->values()->all()] : null,
            'accesses_count' => $showAccesses ? (int) $employee->accesses_count : 0,
        ];
    }

    /**
     * Companies whose last sync the list reports: the filtered or current
     * company, or every syncable company in "Todas".
     *
     * @return iterable<Company>
     */
    private function syncCompanies(CurrentCompany $currentCompany, ?int $companyId): iterable
    {
        if ($companyId !== null) {
            return Company::query()->whereKey($companyId)->get();
        }

        if ($currentCompany->isAll()) {
            return Company::query()->syncable()->orderBy('id')->get();
        }

        return Company::query()->whereKey($currentCompany->id())->get();
    }

    private function authorizeDirectory(Request $request): void
    {
        abort_unless((bool) $request->user()?->canAny(self::DIRECTORY_PERMISSIONS), 403);
    }
}
