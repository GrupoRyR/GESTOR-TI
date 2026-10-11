<?php

namespace App\Http\Requests;

use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwitchCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(CurrentCompany::VIEW_ALL_PERMISSION);
    }

    /**
     * `company_id` must be sent; null selects "Todas".
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('companies', 'id')->where('is_active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'empresa',
        ];
    }

    public function companyId(): ?int
    {
        $companyId = $this->validated('company_id');

        return $companyId === null ? null : (int) $companyId;
    }
}
