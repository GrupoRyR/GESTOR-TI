<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Jobs\SyncCompanyEmployees;
use App\Models\AccessSystem;
use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAccess;
use App\Services\Credentials\EmployeeCredentialService;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Colaboradores. Listing and route binding honour
 * `CompanyScope` (employees of other companies are 404). Passwords never
 * leave the server here: only `EmployeeAccessController::reveal` returns one.
 */
class EmployeeController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $search = $request->string('search')->squish()->value() ?: null;
        $areaId = $request->integer('area_id') ?: null;
        $status = in_array($request->query('status'), Employee::STATUSES, true) ? $request->query('status') : null;

        $paginator = Employee::query()
            ->with('area:id,name')
            ->when($search, fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')
                ->orWhere('job_title', 'like', '%'.$search.'%')))
            ->when($areaId, fn ($query) => $query->where('area_id', $areaId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Admin/Employees/Index', [
            'employees' => [
                'data' => collect($paginator->items())->map(fn (Employee $employee) => $employee->toListArray())->all(),
                'links' => $paginator->linkCollection()->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'filters' => [
                'search' => $search,
                'area_id' => $areaId,
                'status' => $status,
            ],
            'areas' => $this->areaOptions(),
        ]);
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

        return Inertia::render('Admin/Employees/Show', [
            'employee' => $employee->toListArray() + [
                'area_locked' => $employee->area_locked,
                'manager' => $employee->manager ? ['id' => $employee->manager->id, 'name' => $employee->manager->name] : null,
            ],
            'accesses' => $accesses->map(fn (EmployeeAccess $access) => [
                'id' => $access->id,
                'system' => ['id' => $access->system->id, 'name' => $access->system->name],
                'username' => $access->username,
                'notes' => $access->notes,
                'last_rotated_at' => $access->last_rotated_at?->toIso8601String(),
            ])->values()->all(),
            'can_reveal' => (bool) $user?->can(EmployeeCredentialService::PERMISSION),
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
    public function sync(CurrentCompany $currentCompany): RedirectResponse
    {
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
     * @return list<array{id: int, name: string}>
     */
    private function areaOptions(): array
    {
        return Area::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Area $area) => ['id' => $area->id, 'name' => $area->name])
            ->values()
            ->all();
    }
}
