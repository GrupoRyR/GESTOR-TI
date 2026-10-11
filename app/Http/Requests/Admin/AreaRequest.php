<?php

namespace App\Http\Requests\Admin;

use App\Models\Area;
use App\Models\DepartmentAreaMapping;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Body of `admin.areas.store` / `admin.areas.update`:
 * `{ company_id, name, departments[], approver_id|null, active }`.
 *
 * The slug is derived from the name and must be unique inside the company.
 * Departments are Microsoft department names (compared ignoring case,
 * accents and extra spaces). The approver must be a user of the company;
 * no role is assigned here (Administración › Usuarios handles roles).
 * `areas.manage` is enforced by the route.
 */
abstract class AreaRequest extends FormRequest
{
    /**
     * Company the area belongs (or will belong) to; null while unknown
     * (invalid input, validation reports it).
     */
    abstract public function companyId(): ?int;

    abstract protected function ignoredAreaId(): ?int;

    /**
     * @return list<mixed>
     */
    abstract protected function companyRules(): array;

    public function slug(): string
    {
        return Str::slug((string) $this->input('name'));
    }

    /**
     * Squished department names, without empty values.
     *
     * @return list<string>
     */
    public function departments(): array
    {
        return array_values(array_filter(
            array_map(fn ($name) => Str::squish((string) $name), (array) $this->validated('departments', [])),
            fn (string $name) => $name !== '',
        ));
    }

    public function approverId(): ?int
    {
        $id = $this->validated('approver_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_id' => $this->companyRules(),
            'name' => $this->nameRules(),
            'departments' => ['sometimes', 'array'],
            'departments.*' => ['required', 'string', 'max:190', $this->distinctDepartment()],
            'approver_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('company_id', $this->companyId() ?? 0)->where('active', true),
            ],
            'active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'empresa',
            'name' => 'nombre',
            'departments' => 'departamentos',
            'departments.*' => 'departamento',
            'approver_id' => 'aprobador',
            'active' => 'activa',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'approver_id.exists' => 'El aprobador debe ser un usuario activo de la empresa.',
        ];
    }

    protected function canAccessCompany(int $companyId): bool
    {
        $user = $this->user();

        return $user !== null && app(CurrentCompany::class)->canAccess($user, $companyId);
    }

    /**
     * @return list<mixed>
     */
    private function nameRules(): array
    {
        return [
            'required',
            'string',
            'max:120',
            function (string $attribute, mixed $value, Closure $fail): void {
                $companyId = $this->companyId();

                if ($companyId === null || $this->slug() === '') {
                    return;
                }

                $taken = Area::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('slug', $this->slug())
                    ->when($this->ignoredAreaId(), fn ($query, int $id) => $query->whereKeyNot($id))
                    ->exists();

                if ($taken) {
                    $fail('Ya existe un área con ese nombre en la empresa.');
                }
            },
        ];
    }

    /**
     * Rejects a department repeated earlier in the list (same key).
     */
    private function distinctDepartment(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $index = (int) Str::afterLast($attribute, '.');
            $key = DepartmentAreaMapping::keyFor(is_string($value) ? $value : '');
            $earlier = array_slice((array) $this->input('departments', []), 0, $index);

            foreach ($earlier as $other) {
                if ($key !== '' && is_string($other) && DepartmentAreaMapping::keyFor($other) === $key) {
                    $fail('Ese departamento ya está en la lista.');

                    return;
                }
            }
        };
    }
}
