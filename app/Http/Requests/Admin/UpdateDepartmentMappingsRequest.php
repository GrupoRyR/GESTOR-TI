<?php

namespace App\Http\Requests\Admin;

use App\Models\Area;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * `admin.areas.mappings.update`: `{ company_id, mappings: { department, area_id|null }[] }`.
 * `area_id = null` removes the department's mapping; any other value must
 * be an area of the same company.
 */
class UpdateDepartmentMappingsRequest extends CompanyAreasRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_id' => $this->companyRules(),
            'mappings' => ['present', 'array'],
            'mappings.*.department' => ['required', 'string', 'max:190'],
            'mappings.*.area_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $exists = Area::withoutGlobalScopes()
                        ->where('company_id', $this->companyId() ?? 0)
                        ->whereKey((int) $value)
                        ->exists();

                    if (! $exists) {
                        $fail('El área seleccionada no es válida.');
                    }
                },
            ],
        ];
    }

    /**
     * @return list<array{department: string, area_id: ?int}>
     */
    public function mappings(): array
    {
        return array_map(fn (array $item) => [
            'department' => Str::squish((string) $item['department']),
            'area_id' => isset($item['area_id']) ? (int) $item['area_id'] : null,
        ], array_values((array) $this->validated('mappings', [])));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'empresa',
            'mappings' => 'departamentos',
            'mappings.*.department' => 'departamento',
            'mappings.*.area_id' => 'área',
        ];
    }
}
