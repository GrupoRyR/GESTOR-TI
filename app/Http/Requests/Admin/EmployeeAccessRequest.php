<?php

namespace App\Http\Requests\Admin;

use App\Models\Employee;
use App\Models\EmployeeAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update an employee access. The password is write-only: required
 * when creating, optional when updating (blank keeps the current one). It is
 * never flashed back to the session (Laravel's `dontFlash` covers `password`).
 * `employees.manage` is enforced by the route.
 */
class EmployeeAccessRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');
        /** @var ?EmployeeAccess $access */
        $access = $this->route('access');

        return [
            'access_system_id' => [
                'required',
                'integer',
                Rule::exists('access_systems', 'id')->where('is_active', true),
            ],
            'username' => [
                'required',
                'string',
                'max:190',
                Rule::unique('employee_accesses', 'username')
                    ->where('employee_id', $employee->id)
                    ->where('access_system_id', (int) $this->input('access_system_id'))
                    ->ignore($access?->id),
            ],
            'password' => [$access === null ? 'required' : 'nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.unique' => 'El colaborador ya tiene ese usuario en el sistema.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'access_system_id' => 'sistema',
            'username' => 'usuario',
            'password' => 'contraseña',
            'notes' => 'notas',
        ];
    }

    /**
     * Validated attributes for the model; a blank password is left out.
     *
     * @return array<string, mixed>
     */
    public function accessAttributes(): array
    {
        $attributes = [
            'access_system_id' => (int) $this->validated('access_system_id'),
            'username' => trim((string) $this->validated('username')),
            'notes' => $this->validated('notes'),
        ];

        $password = $this->validated('password');

        if (is_string($password) && $password !== '') {
            $attributes['password'] = $password;
        }

        return $attributes;
    }
}
