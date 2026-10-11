<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `admin.areas.approval.update`: `{ company_id, enabled }`.
 */
class UpdateAreaApprovalRequest extends CompanyAreasRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_id' => $this->companyRules(),
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'empresa',
            'enabled' => 'visto bueno de área',
        ];
    }
}
