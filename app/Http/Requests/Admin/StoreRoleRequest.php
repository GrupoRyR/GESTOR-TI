<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Agregar rol": `{ label, scope, copy_from: string|null }`. `scope` arrives
 * as its visible label ("Global", "Empresa", "Empresa y área"); the role
 * name is the label. Authorization: route middleware `can:roles.manage`.
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('label'))) {
            $this->merge(['label' => preg_replace('/\s+/u', ' ', trim($this->input('label')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->where('guard_name', 'web')],
            'scope' => ['required', 'string', Rule::in(array_values(config('permissions.scopes', [])))],
            'copy_from' => ['nullable', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'Escribe el nombre del rol.',
            'label.unique' => 'Ya existe un rol con ese nombre.',
            'scope.in' => 'Escoge un alcance de la lista.',
            'copy_from.exists' => 'El rol a copiar no existe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['label' => 'nombre', 'scope' => 'alcance', 'copy_from' => 'rol a copiar'];
    }

    /**
     * Scope key (global | company | company_area) of the chosen label.
     */
    public function scopeKey(): string
    {
        return (string) array_search($this->validated('scope'), config('permissions.scopes', []), true);
    }
}
