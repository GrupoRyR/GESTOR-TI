<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class StoreAreaRequest extends AreaRequest
{
    /**
     * Restricted users only create areas in their own company (403 for any
     * other); `companies.view_all` users choose any company.
     */
    public function authorize(): bool
    {
        $companyId = $this->companyId();

        return $companyId === null || $this->canAccessCompany($companyId);
    }

    public function companyId(): ?int
    {
        $chosen = $this->input('company_id');

        return is_numeric($chosen) ? (int) $chosen : null;
    }

    protected function companyRules(): array
    {
        return ['required', 'integer', Rule::exists('companies', 'id')];
    }

    protected function ignoredAreaId(): ?int
    {
        return null;
    }
}
