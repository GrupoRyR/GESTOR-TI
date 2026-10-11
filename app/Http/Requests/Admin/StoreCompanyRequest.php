<?php

namespace App\Http\Requests\Admin;

use App\Models\Company;

class StoreCompanyRequest extends CompanyRequest
{
    /**
     * Creating a company is reserved to `companies.manage_tenant`.
     */
    public function authorize(): bool
    {
        return $this->canManageTenant();
    }

    protected function company(): ?Company
    {
        return null;
    }

    protected function requiresLogo(): bool
    {
        return true;
    }

    protected function tenantRules(): array
    {
        return ['required'];
    }
}
