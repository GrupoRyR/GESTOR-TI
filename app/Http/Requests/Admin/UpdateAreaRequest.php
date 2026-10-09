<?php

namespace App\Http\Requests\Admin;

use App\Models\Area;

/**
 * The area is looked up without `CompanyScope` (the Áreas page switches
 * company through its `company_id` query, not the session context) and then
 * checked against the user's companies: areas of a company the user cannot
 * access are 404. Its company cannot change; `company_id` in the body is
 * ignored.
 */
class UpdateAreaRequest extends AreaRequest
{
    private ?Area $resolvedArea = null;

    public function authorize(): bool
    {
        $area = $this->area();

        abort_if($area === null || ! $this->canAccessCompany($area->company_id), 404);

        return true;
    }

    public function area(): ?Area
    {
        $id = $this->route('area');

        if (! is_numeric($id)) {
            return null;
        }

        return $this->resolvedArea ??= Area::withoutGlobalScopes()->find((int) $id);
    }

    public function companyId(): ?int
    {
        return $this->area()?->company_id;
    }

    protected function companyRules(): array
    {
        return ['sometimes', 'nullable'];
    }

    protected function ignoredAreaId(): ?int
    {
        return $this->area()?->id;
    }
}
