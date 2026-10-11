<?php

namespace App\Http\Requests\Admin;

use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Requests of the Áreas page that act on a whole company (`company_id` in
 * the body): restricted users only on their own company (403 otherwise).
 * `areas.manage` is enforced by the route.
 */
abstract class CompanyAreasRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = $this->companyId();
        $user = $this->user();

        return $companyId === null || ($user !== null && app(CurrentCompany::class)->canAccess($user, $companyId));
    }

    public function companyId(): ?int
    {
        $id = $this->input('company_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return list<mixed>
     */
    protected function companyRules(): array
    {
        return ['required', 'integer', Rule::exists('companies', 'id')];
    }
}
