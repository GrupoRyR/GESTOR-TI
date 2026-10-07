<?php

namespace App\Http\Requests\Admin;

use App\Models\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Role × permission matrix: the full list of permissions of one role.
 * Exclusive permissions (`ai.vault.access`) are rejected: they are never
 * granted through a role. Authorization: route middleware `can:roles.manage`.
 */
class UpdateRolePermissionsRequest extends FormRequest
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
            'permissions' => ['present', 'array'],
            'permissions.*' => [
                'bail',
                'string',
                'distinct',
                Rule::notIn(Permission::exclusiveNames()),
                Rule::exists('permissions', 'name')->where('guard_name', 'web'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.present' => 'Debes enviar la lista de permisos.',
            'permissions.array' => 'La lista de permisos no es válida.',
            'permissions.*.string' => 'El permiso no es válido.',
            'permissions.*.distinct' => 'El permiso :input está repetido.',
            'permissions.*.not_in' => 'El permiso :input es exclusivo y no se puede asignar a un rol.',
            'permissions.*.exists' => 'El permiso :input no existe.',
        ];
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return array_values($this->validated('permissions'));
    }
}
