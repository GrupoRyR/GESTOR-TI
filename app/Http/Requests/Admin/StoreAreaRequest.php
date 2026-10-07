<?php

namespace App\Http\Requests\Admin;

use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class StoreAreaRequest extends AreaRequest
{
    /**
     * `areas.manage` is enforced by the route. A restricted user needs a
     * home company to create areas in.
     */
    public function authorize(): bool
    {
        return $this->canViewAll() || $this->user()?->company_id !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => $this->nameRules(),
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($this->canViewAll()) {
            // In the "Todas" context the company must be chosen explicitly.
            $rules['company_id'] = [
                Rule::requiredIf(fn () => app(CurrentCompany::class)->id() === null),
                'nullable',
                'integer',
                Rule::exists('companies', 'id')->where('is_active', true),
            ];
        }

        return $rules;
    }

    /**
     * Restricted users always create in their own company (input ignored);
     * global users use the chosen company or the selected context.
     */
    public function companyId(): ?int
    {
        if (! $this->canViewAll()) {
            return $this->user()?->company_id;
        }

        $chosen = $this->input('company_id');

        if (is_numeric($chosen)) {
            return (int) $chosen;
        }

        return app(CurrentCompany::class)->id();
    }

    protected function ignoredAreaId(): ?int
    {
        return null;
    }

    private function canViewAll(): bool
    {
        return (bool) $this->user()?->can(CurrentCompany::VIEW_ALL_PERMISSION);
    }
}
