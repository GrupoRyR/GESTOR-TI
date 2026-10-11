<?php

namespace App\Actions\Areas;

use App\Actions\Employees\ApplyDepartmentMappings;
use App\Models\Area;
use App\Models\DepartmentAreaMapping;
use Illuminate\Support\Facades\DB;

/**
 * Microsoft department → area mappings edited from Administración › Áreas
 * (the area dialog and the "departamentos sin área" list). Every change is
 * applied right away to the company's employees whose area was not set by
 * hand (`ApplyDepartmentMappings`). Mappings are read and written without
 * `CompanyScope`: callers already checked access to the company.
 */
class AssignDepartments
{
    public function __construct(private readonly ApplyDepartmentMappings $apply) {}

    /**
     * The area groups exactly these departments: listed ones are mapped to
     * it (moved from another area if needed), the rest of its mappings are
     * removed.
     *
     * @param  list<string>  $departments
     */
    public function syncArea(Area $area, array $departments): void
    {
        DB::transaction(function () use ($area, $departments): void {
            $keys = [];

            foreach ($departments as $department) {
                $key = $this->assign($area->company_id, $department, $area->id);

                if ($key !== null) {
                    $keys[] = $key;
                }
            }

            DepartmentAreaMapping::withoutGlobalScopes()
                ->where('area_id', $area->id)
                ->whereNotIn('department_key', $keys)
                ->get()
                ->each->delete();
        });

        $this->apply->handle($area->company_id);
    }

    /**
     * @param  list<array{department: string, area_id: ?int}>  $mappings
     */
    public function many(int $companyId, array $mappings): void
    {
        DB::transaction(function () use ($companyId, $mappings): void {
            foreach ($mappings as $mapping) {
                $this->assign($companyId, $mapping['department'], $mapping['area_id']);
            }
        });

        $this->apply->handle($companyId);
    }

    /**
     * Maps one department (null area = remove its mapping). Returns the
     * department key, or null for an empty name.
     */
    private function assign(int $companyId, string $department, ?int $areaId): ?string
    {
        $key = DepartmentAreaMapping::keyFor($department);

        if ($key === '') {
            return null;
        }

        $mapping = DepartmentAreaMapping::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('department_key', $key)
            ->first();

        if ($areaId === null) {
            $mapping?->delete();

            return $key;
        }

        $mapping ??= new DepartmentAreaMapping(['company_id' => $companyId]);
        $mapping->fill(['department' => $department, 'area_id' => $areaId])->save();

        return $key;
    }
}
