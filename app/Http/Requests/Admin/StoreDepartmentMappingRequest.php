<?php

namespace App\Http\Requests\Admin;

use App\Models\Area;
use App\Models\DepartmentAreaMapping;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Department → area mapping. The company is the area's company, and the
 * area must be visible in the current company context (CompanyScope).
 * `employees.manage` is enforced by the route.
 */
class StoreDepartmentMappingRequest extends FormRequest
{
    private ?Area $resolvedArea = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'area_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->area() === null) {
                        $fail('El área seleccionada no es válida.');
                    }
                },
            ],
            'department' => [
                'required',
                'string',
                'max:190',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $area = $this->area();
                    $key = DepartmentAreaMapping::keyFor(is_string($value) ? $value : '');

                    if ($area === null || $key === '') {
                        return;
                    }

                    $taken = DepartmentAreaMapping::withoutGlobalScopes()
                        ->where('company_id', $area->company_id)
                        ->where('department_key', $key)
                        ->exists();

                    if ($taken) {
                        $fail('Ese departamento ya tiene un área asignada en la empresa.');
                    }
                },
            ],
        ];
    }

    /**
     * Area of the request, resolved inside the current company context.
     */
    public function area(): ?Area
    {
        $id = $this->input('area_id');

        if (! is_numeric($id)) {
            return null;
        }

        return $this->resolvedArea ??= Area::query()->find((int) $id);
    }

    public function department(): string
    {
        return Str::squish((string) $this->validated('department'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'area_id' => 'área',
            'department' => 'departamento',
        ];
    }
}
