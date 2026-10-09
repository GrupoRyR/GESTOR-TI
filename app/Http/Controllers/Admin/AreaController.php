<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Areas\AssignDepartments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAreaRequest;
use App\Http\Requests\Admin\UpdateAreaApprovalRequest;
use App\Http\Requests\Admin\UpdateAreaRequest;
use App\Http\Requests\Admin\UpdateDepartmentMappingsRequest;
use App\Models\Area;
use App\Models\Company;
use App\Models\DepartmentAreaMapping;
use App\Models\Employee;
use App\Models\User;
use App\Support\AreaApproval;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Áreas (`Admin/Areas/Index`): areas of ONE company with
 * their Microsoft departments (department → area mappings), the area
 * approver, the departments still without area and the per-company
 * "visto bueno de área" switch.
 *
 * The company comes from the `company_id` query (only `companies.view_all`
 * users may pick any company; restricted users always get their own, 403
 * for another one). Data is read without `CompanyScope` after that check,
 * because the page switches company by query, not by the session context.
 */
class AreaController extends Controller
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly AreaApproval $areaApproval,
    ) {}

    public function index(Request $request): Response
    {
        $company = $this->resolveCompany($request);

        return Inertia::render('Admin/Areas/Index', [
            'company_id' => $company->id,
            'areas' => $this->areas($company),
            'unmapped_departments' => $this->unmappedDepartments($company),
            'area_approval_enabled' => $this->areaApproval->enabled($company->id),
            'approver_options' => User::query()
                ->where('company_id', $company->id)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->all(),
        ]);
    }

    public function store(StoreAreaRequest $request, AssignDepartments $assign): RedirectResponse
    {
        $area = DB::transaction(fn () => Area::create([
            'company_id' => $request->companyId(),
            'name' => $request->validated('name'),
            'slug' => $request->slug(),
            'approver_user_id' => $request->approverId(),
            'is_active' => $request->boolean('active'),
        ]));

        $assign->syncArea($area, $request->departments());

        return back()->with('success', 'Área creada.');
    }

    public function update(UpdateAreaRequest $request, AssignDepartments $assign): RedirectResponse
    {
        $area = $request->area();

        $area->update([
            'name' => $request->validated('name'),
            'slug' => $request->slug(),
            'approver_user_id' => $request->approverId(),
            'is_active' => $request->boolean('active'),
        ]);

        $assign->syncArea($area, $request->departments());

        return back()->with('success', 'Área actualizada.');
    }

    public function updateMappings(UpdateDepartmentMappingsRequest $request, AssignDepartments $assign): RedirectResponse
    {
        $assign->many((int) $request->companyId(), $request->mappings());

        return back()->with('success', 'Departamentos asignados.');
    }

    public function updateApproval(UpdateAreaApprovalRequest $request): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        $this->areaApproval->set((int) $request->companyId(), $enabled);

        return back()->with('success', $enabled ? 'Visto bueno de área activado.' : 'Visto bueno de área desactivado.');
    }

    /**
     * Global users: the requested company, else the selected context, else
     * the first active company by name. Restricted users: their own company.
     */
    private function resolveCompany(Request $request): Company
    {
        $user = $request->user();
        $requested = $request->query('company_id');
        $requestedId = is_numeric($requested) ? (int) $requested : null;

        if (! $this->currentCompany->canViewAll($user)) {
            abort_if($user->company_id === null, 403);
            abort_if($requestedId !== null && $requestedId !== (int) $user->company_id, 403);

            return Company::query()->findOrFail($user->company_id);
        }

        if ($requestedId !== null) {
            return Company::query()->findOrFail($requestedId);
        }

        $contextId = $this->currentCompany->id();

        return ($contextId === null ? null : Company::query()->find($contextId))
            ?? Company::query()->active()->orderBy('name')->first()
            ?? Company::query()->orderBy('name')->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function areas(Company $company): array
    {
        return Area::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->with([
                'approver:id,name',
                'departmentMappings' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->withCount(['employees' => fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('status', Employee::STATUS_ACTIVE)])
            ->orderBy('name')
            ->get()
            ->map(fn (Area $area) => [
                'id' => $area->id,
                'name' => $area->name,
                'employees_count' => (int) $area->getAttribute('employees_count'),
                'departments' => $area->departmentMappings
                    ->pluck('department')
                    ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all(),
                'approver' => $area->approver ? ['id' => $area->approver->id, 'name' => $area->approver->name] : null,
                'active' => $area->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * Departments of the company's active employees that no mapping covers,
     * grouped by department key (the most common spelling is shown). The
     * suggested area is the active area whose slug matches the department.
     *
     * @return list<array{name: string, employees_count: int, area_id: ?int}>
     */
    private function unmappedDepartments(Company $company): array
    {
        $mapped = DepartmentAreaMapping::mapFor($company->id);

        $suggestions = Area::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->pluck('id', 'slug');

        return Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', Employee::STATUS_ACTIVE)
            ->whereNotNull('department')
            ->pluck('department')
            ->map(fn (string $department) => Str::squish($department))
            ->groupBy(fn (string $department) => DepartmentAreaMapping::keyFor($department))
            ->reject(fn (Collection $names, string $key) => $key === '' || array_key_exists($key, $mapped))
            ->map(function (Collection $names) use ($suggestions): array {
                $name = (string) $names->countBy()->sortDesc()->keys()->first();
                $suggested = $suggestions->get(Str::slug($name));

                return [
                    'name' => $name,
                    'employees_count' => $names->count(),
                    'area_id' => $suggested === null ? null : (int) $suggested,
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
