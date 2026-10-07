<?php

namespace App\Http\Requests\Admin;

use App\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for creating and updating companies.
 *
 * The Microsoft tenant decides who can log in, so creating a company or
 * setting/changing its `tenant_id` requires `companies.manage_tenant`
 * (only Súper Admin). Without it the request is rejected with 403.
 */
abstract class CompanyRequest extends FormRequest
{
    public const MANAGE_TENANT_PERMISSION = 'companies.manage_tenant';

    abstract protected function company(): ?Company;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('tenant_id'))) {
            $this->merge(['tenant_id' => strtolower(trim($this->input('tenant_id')))]);
        }

        if (is_array($this->input('domains'))) {
            $this->merge([
                'domains' => array_map(
                    fn ($domain) => is_string($domain) ? strtolower(trim($domain)) : $domain,
                    $this->input('domains'),
                ),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $ignore = $this->company()?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:60'],
            'slug' => ['required', 'string', 'max:60', 'alpha_dash:ascii', 'lowercase', Rule::unique('companies', 'slug')->ignore($ignore)],
            'tenant_id' => ['sometimes', 'nullable', 'uuid', Rule::unique('companies', 'tenant_id')->ignore($ignore)],
            'domains' => ['present', 'array'],
            'domains.*' => ['required', 'string', 'max:255', 'distinct', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'logo_dark_path' => ['nullable', 'string', 'max:255'],
            'colors' => ['required', 'array:primary,accent,dark'],
            'colors.primary' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'colors.accent' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'colors.dark' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sender_email' => ['required', 'email', 'max:255'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'short_name' => 'nombre corto',
            'slug' => 'identificador',
            'tenant_id' => 'ID de inquilino',
            'domains' => 'dominios',
            'domains.*' => 'dominio',
            'logo_path' => 'logo',
            'logo_dark_path' => 'logo oscuro',
            'colors' => 'colores',
            'colors.primary' => 'color principal',
            'colors.accent' => 'color de acento',
            'colors.dark' => 'color oscuro',
            'sender_email' => 'correo emisor',
            'sender_name' => 'nombre del emisor',
            'is_active' => 'activa',
        ];
    }

    protected function canManageTenant(): bool
    {
        return (bool) $this->user()?->can(self::MANAGE_TENANT_PERMISSION);
    }
}
