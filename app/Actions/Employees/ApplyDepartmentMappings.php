<?php

namespace App\Actions\Employees;

use App\Models\DepartmentAreaMapping;
use App\Models\Employee;

/**
 * Re-applies the department → area mappings of a company to its employees
 * whose area was not assigned by hand (`area_locked = false`). Used after a
 * mapping is created or deleted, so the change shows without waiting for
 * the next Graph sync.
 */
class ApplyDepartmentMappings
{
    /**
     * @return int employees whose area changed
     */
    public function handle(int $companyId): int
    {
        $mappings = DepartmentAreaMapping::mapFor($companyId);
        $changed = 0;

        Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('area_locked', false)
            ->lazyById()
            ->each(function (Employee $employee) use ($mappings, &$changed): void {
                $areaId = $mappings[DepartmentAreaMapping::keyFor($employee->department)] ?? null;

                $current = $employee->area_id === null ? null : (int) $employee->area_id;

                if ($current !== $areaId) {
                    $employee->update(['area_id' => $areaId]);
                    $changed++;
                }
            });

        return $changed;
    }
}
