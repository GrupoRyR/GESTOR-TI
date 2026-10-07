<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\ApplyDepartmentMappings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDepartmentMappingRequest;
use App\Models\Area;
use App\Models\DepartmentAreaMapping;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Colaboradores › Departamentos: Microsoft department →
 * area. Honours `CompanyScope`. Changes are applied right away to the
 * employees whose area was not assigned by hand.
 */
class DepartmentMappingController extends Controller
{
    public function index(): Response
    {
        $mappings = DepartmentAreaMapping::query()
            ->with(['company:id,short_name', 'area:id,name'])
            ->orderBy('company_id')
            ->orderBy('department')
            ->get();

        $mappedKeys = $mappings->map(fn (DepartmentAreaMapping $m) => $m->company_id.'|'.$m->department_key)->flip();

        $unmapped = Employee::query()
            ->whereNotNull('department')
            ->distinct()
            ->get(['company_id', 'department'])
            ->reject(fn (Employee $e) => $mappedKeys->has($e->company_id.'|'.DepartmentAreaMapping::keyFor($e->department)))
            ->unique(fn (Employee $e) => DepartmentAreaMapping::keyFor($e->department))
            ->pluck('department')
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return Inertia::render('Admin/DepartmentMappings/Index', [
            'mappings' => $mappings->map(fn (DepartmentAreaMapping $mapping) => [
                'id' => $mapping->id,
                'company_id' => $mapping->company_id,
                'company_short_name' => $mapping->company?->short_name,
                'department' => $mapping->department,
                'area' => $mapping->area ? ['id' => $mapping->area->id, 'name' => $mapping->area->name] : null,
            ])->values()->all(),
            'areas' => Area::query()
                ->orderBy('company_id')
                ->orderBy('name')
                ->get(['id', 'name', 'company_id'])
                ->map(fn (Area $area) => ['id' => $area->id, 'name' => $area->name, 'company_id' => $area->company_id])
                ->all(),
            'unmapped_departments' => $unmapped,
        ]);
    }

    public function store(StoreDepartmentMappingRequest $request, ApplyDepartmentMappings $apply): RedirectResponse
    {
        $area = $request->area();

        DepartmentAreaMapping::create([
            'company_id' => $area->company_id,
            'department' => $request->department(),
            'area_id' => $area->id,
        ]);

        $apply->handle($area->company_id);

        return back()->with('success', 'Mapeo guardado.');
    }

    public function destroy(DepartmentAreaMapping $mapping, ApplyDepartmentMappings $apply): RedirectResponse
    {
        $companyId = $mapping->company_id;
        $mapping->delete();

        $apply->handle($companyId);

        return back()->with('success', 'Mapeo eliminado.');
    }
}
