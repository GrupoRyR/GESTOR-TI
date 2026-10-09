<?php

namespace App\Http\Requests\Admin;

use App\Models\Permission;
use App\Support\RoleMatrix;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Role × permission matrix saved in one PUT:
 * `{ roles: { name, permissions[] }[] }`. Only the permissions shown by the
 * matrix (config/permissions.php `matrix`) may be listed: `ai.vault.access`
 * is never a role permission, any other hidden permission is managed by
 * config only, and `ai.projects.approve` only goes to the project approval
 * role. Authorization: route middleware `can:roles.manage`.
 */
class UpdateRolesMatrixRequest extends FormRequest
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
            'roles.*' => ['required', 'array:name,permissions'],
            'roles.*.name' => ['bail', 'required', 'string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'roles.*.permissions' => ['present', 'array', $this->projectApprovalRule()],
            'roles.*.permissions.*' => [
                'bail',
                'string',
                // No `distinct`: it would compare across roles; repeats are dropped in matrix().
                Rule::notIn(Permission::exclusiveNames()),
                Rule::in(RoleMatrix::visiblePermissionNames()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.present' => 'Debes enviar la matriz de roles.',
            'roles.*.name.exists' => 'El rol :input no existe.',
            'roles.*.name.distinct' => 'El rol :input está repetido.',
            'roles.*.permissions.present' => 'Debes enviar la lista de permisos del rol.',
            'roles.*.permissions.*.not_in' => 'La bóveda no se da por roles.',
            'roles.*.permissions.*.in' => 'El permiso :input no se administra desde esta tabla.',
        ];
    }

    private function projectApprovalRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $index = (int) explode('.', $attribute)[1];
            $role = $this->input("roles.{$index}.name");

            if (is_array($value) && in_array('ai.projects.approve', $value, true) && $role !== RoleMatrix::projectApprovalRole()) {
                $fail('Aprobar proyectos solo se da con el rol '.RoleMatrix::projectApprovalRole().'.');
            }
        };
    }

    /**
     * @return array<string, list<string>> role name => visible permissions
     */
    public function matrix(): array
    {
        return collect($this->validated('roles'))
            ->mapWithKeys(fn (array $role) => [$role['name'] => array_values(array_unique($role['permissions']))])
            ->all();
    }
}
