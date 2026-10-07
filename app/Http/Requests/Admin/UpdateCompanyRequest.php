<?php

namespace App\Http\Requests\Admin;

use App\Models\Company;

class UpdateCompanyRequest extends CompanyRequest
{
    /**
     * `companies.manage` is enforced by the route; changing the tenant
     * additionally requires `companies.manage_tenant` (403 otherwise).
     * Sending the unchanged tenant, or omitting it, is allowed.
     */
    public function authorize(): bool
    {
        return ! $this->changesTenant() || $this->canManageTenant();
    }

    protected function company(): ?Company
    {
        $company = $this->route('company');

        return $company instanceof Company ? $company : null;
    }

    private function changesTenant(): bool
    {
        if (! $this->exists('tenant_id')) {
            return false;
        }

        $requested = $this->input('tenant_id');
        $requested = is_string($requested) ? strtolower(trim($requested)) : $requested;
        $current = $this->company()?->tenant_id;

        return ($requested ?: null) !== ($current === null ? null : strtolower($current));
    }
}
