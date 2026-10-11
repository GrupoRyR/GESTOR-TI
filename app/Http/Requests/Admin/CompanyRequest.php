<?php

namespace App\Http\Requests\Admin;

use App\Models\Company;
use App\Support\CompanyLogos;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Shared validation for creating and updating companies (multipart, as the
 * `Admin/Companies/*` pages send it): name, short_name, slug, domains[],
 * sender_email, colors{primary,accent,dark}, logo?, logo_dark?, active,
 * tenant_id.
 *
 * The Microsoft tenant decides who can log in, so creating a company or
 * setting/changing its `tenant_id` requires `companies.manage_tenant`
 * (only Súper Admin). Without it the request is rejected with 403.
 */
abstract class CompanyRequest extends FormRequest
{
    public const MANAGE_TENANT_PERMISSION = 'companies.manage_tenant';

    abstract protected function company(): ?Company;

    /**
     * Whether `logo` must be uploaded (creation).
     */
    abstract protected function requiresLogo(): bool;

    /**
     * @return list<mixed>
     */
    abstract protected function tenantRules(): array;

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

        if (is_string($this->input('sender_email'))) {
            $this->merge(['sender_email' => strtolower(trim($this->input('sender_email')))]);
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
            'tenant_id' => [...$this->tenantRules(), 'uuid', Rule::unique('companies', 'tenant_id')->ignore($ignore)],
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => ['required', 'string', 'max:255', 'distinct', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/'],
            'logo' => CompanyLogos::rules($this->requiresLogo()),
            'logo_dark' => CompanyLogos::rules(false),
            'colors' => ['required', 'array:primary,accent,dark'],
            'colors.primary' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'colors.accent' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'colors.dark' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sender_email' => ['required', 'email', 'max:255', $this->senderDomainRule()],
            'active' => ['required', 'boolean'],
        ];
    }

    /**
     * The sender mailbox must belong to one of the company's domains (the
     * wizard checks it client-side too; the server is the authority).
     */
    private function senderDomainRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $domains = $this->input('domains');

            if (! is_string($value) || ! is_array($domains)) {
                return;
            }

            $domain = Str::after($value, '@');

            if (! in_array($domain, array_filter($domains, 'is_string'), true)) {
                $fail('El correo emisor debe ser de uno de los dominios de la empresa.');
            }
        };
    }

    /**
     * Company columns from the validated input (`active` → `is_active`;
     * logos are stored separately with `CompanyLogos`).
     *
     * @return array<string, mixed>
     */
    public function attributesForCompany(): array
    {
        $data = $this->safe()->except(['logo', 'logo_dark', 'active']);
        $data['is_active'] = $this->boolean('active');

        return $data;
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
            'logo' => 'logo',
            'logo_dark' => 'logo oscuro',
            'colors' => 'colores',
            'colors.primary' => 'color principal',
            'colors.accent' => 'color de acento',
            'colors.dark' => 'color oscuro',
            'sender_email' => 'correo emisor',
            'active' => 'activa',
        ];
    }

    protected function canManageTenant(): bool
    {
        return (bool) $this->user()?->can(self::MANAGE_TENANT_PERMISSION);
    }
}
