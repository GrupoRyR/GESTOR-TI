<?php

namespace App\Http\Requests\Admin;

use App\Models\Area;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The area is resolved through `CompanyScope` (route binding), so it is
 * always inside the user's visible companies. Its company cannot change.
 */
class UpdateAreaRequest extends AreaRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function companyId(): ?int
    {
        return $this->area()?->company_id;
    }

    protected function ignoredAreaId(): ?int
    {
        return $this->area()?->id;
    }

    private function area(): ?Area
    {
        $area = $this->route('area');

        return $area instanceof Area ? $area : null;
    }
}
