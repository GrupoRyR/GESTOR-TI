<?php

namespace App\Http\Requests\Admin;

use App\Models\Area;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Shared rules for areas: the slug is derived from the name and must be
 * unique inside the company.
 */
abstract class AreaRequest extends FormRequest
{
    /**
     * Company the area belongs (or will belong) to.
     */
    abstract public function companyId(): ?int;

    abstract protected function ignoredAreaId(): ?int;

    public function slug(): string
    {
        return Str::slug((string) $this->input('name'));
    }

    /**
     * @return list<mixed>
     */
    protected function nameRules(): array
    {
        return [
            'required',
            'string',
            'max:120',
            function (string $attribute, mixed $value, Closure $fail): void {
                $companyId = $this->companyId();

                if ($companyId === null || $this->slug() === '') {
                    return;
                }

                $taken = Area::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('slug', $this->slug())
                    ->when($this->ignoredAreaId(), fn ($query, int $id) => $query->whereKeyNot($id))
                    ->exists();

                if ($taken) {
                    $fail('Ya existe un área con ese nombre en la empresa.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'empresa',
            'name' => 'nombre',
            'is_active' => 'activa',
        ];
    }
}
