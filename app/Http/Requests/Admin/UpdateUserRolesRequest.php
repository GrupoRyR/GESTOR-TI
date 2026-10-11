<?php

namespace App\Http\Requests\Admin;

use App\Models\Area;
use App\Models\Role;
use App\Models\User;
use App\Support\UserDirectory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Roles of one user (Administración › Usuarios):
 * `{ roles: string[], area_id: number|null }`. `area_id` is the area the
 * person approves and is required when a role with `requires_area`
 * ("Aprobador de área") is included; it must be an active area of the
 * user's company. The emergency (local) account and deactivated users are
 * not changed here. Users outside the admin's company scope answer 404.
 * Authorization: route middleware `can:users.manage`.
 */
class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        app(UserDirectory::class)->ensureVisible($this->targetUser());
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
            'area_id' => ['nullable', 'integer'],
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
            'area_id.integer' => 'Escoge el área que aprueba.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $user = $this->targetUser();

            if ($user->isLocal()) {
                $validator->errors()->add('roles', 'Los roles de la cuenta de emergencia no se cambian desde aquí.');

                return;
            }

            if (! $user->isActive()) {
                $validator->errors()->add('roles', 'El usuario está desactivado.');

                return;
            }

            if ($validator->errors()->isNotEmpty() || ! $this->requiresArea()) {
                return;
            }

            $areaId = $this->input('area_id');

            if ($areaId === null || $areaId === '') {
                $validator->errors()->add('area_id', 'Escoge el área que aprueba.');

                return;
            }

            $valid = Area::withoutGlobalScopes()
                ->whereKey((int) $areaId)
                ->where('company_id', $user->company_id ?? 0)
                ->where('is_active', true)
                ->exists();

            if (! $valid) {
                $validator->errors()->add('area_id', 'El área debe ser un área activa de la empresa del usuario.');
            }
        }];
    }

    public function targetUser(): User
    {
        /** @var User $user */
        $user = $this->route('user');

        return $user;
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        return array_values($this->validated('roles'));
    }

    /**
     * Area to approve, only when a `requires_area` role is included.
     */
    public function approverAreaId(): ?int
    {
        return $this->requiresArea() ? (int) $this->validated('area_id') : null;
    }

    private function requiresArea(): bool
    {
        $roles = array_filter((array) $this->input('roles', []), 'is_string');

        return $roles !== [] && Role::query()->whereIn('name', $roles)->where('requires_area', true)->exists();
    }
}
