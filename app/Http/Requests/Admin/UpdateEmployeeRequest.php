<?php

namespace App\Http\Requests\Admin;

use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual changes to an employee: area (locks it against the sync; null
 * unlocks it) and status. `employees.manage` is enforced by the route.
 */
class UpdateEmployeeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return [
            'area_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('areas', 'id')->where('company_id', $employee->company_id),
            ],
            'status' => ['sometimes', 'required', Rule::in(Employee::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'area_id' => 'área',
            'status' => 'estado',
        ];
    }
}
