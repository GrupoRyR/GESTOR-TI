<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Full list of roles of one user. Authorization: route middleware
 * `can:users.manage`.
 */
class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => [
                'bail',
                'string',
                'distinct',
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.present' => 'Debes enviar la lista de roles.',
            'roles.array' => 'La lista de roles no es válida.',
            'roles.*.string' => 'El rol no es válido.',
            'roles.*.distinct' => 'El rol :input está repetido.',
            'roles.*.exists' => 'El rol :input no existe.',
        ];
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        return array_values($this->validated('roles'));
    }
}
