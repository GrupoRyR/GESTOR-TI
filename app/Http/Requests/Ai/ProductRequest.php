<?php

namespace App\Http\Requests\Ai;

use App\Domain\Ai\ProductForm;
use App\Enums\Ai\ProductStatus;
use App\Models\AiProduct;
use App\Models\Area;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `ProductFormData` of `ai.products.store` / `ai.products.update`
 * (C-ProductForm). Error keys are the field names; messages are the
 * Spanish ones of the board. Authorization stays in the route
 * (`can:ai.manage`) and the controller (AiProductPolicy).
 */
class ProductRequest extends FormRequest
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const VERSION_PATTERN = '/^v\d+\.\d+\.\d+$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->product();

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:64', 'regex:'.self::SLUG_PATTERN, $this->uniqueSlug($product)],
            'company_ids' => ['required', 'array', 'min:1'],
            'company_ids.*' => ['integer', 'distinct', Rule::exists('companies', 'id')->where('is_active', true)],
            'area_id' => ['required', 'integer', Rule::exists('areas', 'id')->where('is_active', true), $this->areaOfTheCompanies()],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'description' => ['nullable', 'string', 'max:2000'],
            'request_keys' => ['nullable', 'array', $this->linkable($product)],
            'request_keys.*' => ['string', 'distinct'],
            'version' => ['required', 'string', 'max:40', 'regex:'.self::VERSION_PATTERN],
            'state' => ['required', 'string', Rule::enum(ProductStatus::class)],
            'kind' => ['required', 'string', Rule::in(AiProduct::KINDS)],
            'stack' => ['nullable', 'string', 'max:80'],
            'host' => ['nullable', 'string', 'max:80'],
            'url' => ['nullable', 'string', 'max:255', $this->urlOrPlaceholder()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del producto.',
            'name.max' => 'Usa máximo 150 caracteres.',
            'slug.required' => 'Escribe el slug (el mismo de la tarjeta del Portal).',
            'slug.max' => 'Usa máximo 64 caracteres.',
            'slug.regex' => 'Usa solo minúsculas, números y guiones.',
            'company_ids.required' => 'Elige la empresa.',
            'company_ids.min' => 'Elige la empresa.',
            'company_ids.array' => 'Elige la empresa.',
            'company_ids.*.integer' => 'Elige la empresa.',
            'company_ids.*.distinct' => 'Elige la empresa.',
            'company_ids.*.exists' => 'Elige una empresa activa.',
            'area_id.required' => 'Elige el área que usa la app.',
            'area_id.integer' => 'Elige el área que usa la app.',
            'area_id.exists' => 'Elige el área que usa la app.',
            'owner_id.integer' => 'Elige el dueño de la lista.',
            'owner_id.exists' => 'Elige el dueño de la lista.',
            'description.max' => 'Usa máximo 2.000 caracteres.',
            'request_keys.array' => 'Elige solicitudes de la lista.',
            'request_keys.*.string' => 'Elige solicitudes de la lista.',
            'request_keys.*.distinct' => 'Esa solicitud ya está vinculada.',
            'version.required' => 'Escribe la versión actual.',
            'version.max' => 'Usa el formato v1.2.3.',
            'version.regex' => 'Usa el formato v1.2.3.',
            'state.required' => 'Elige un estado de la lista.',
            'state.enum' => 'Elige un estado de la lista.',
            'kind.required' => 'Elige el tipo de app.',
            'kind.in' => 'Elige el tipo de app.',
            'stack.max' => 'Usa máximo 80 caracteres.',
            'host.max' => 'Usa máximo 80 caracteres.',
            'url.max' => 'Usa máximo 255 caracteres.',
        ];
    }

    /**
     * Errors of `company_ids.*` and `request_keys.*` go under the keys the
     * page reads (`company_ids`, `request_keys`).
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();

        foreach (['company_ids', 'request_keys'] as $field) {
            foreach ($errors->keys() as $key) {
                if (str_starts_with($key, "{$field}.")) {
                    if (! $errors->has($field)) {
                        $errors->add($field, $errors->first($key));
                    }
                    $errors->forget($key);
                }
            }
        }

        parent::failedValidation($validator);
    }

    public function product(): ?AiProduct
    {
        $product = $this->route('product');

        return $product instanceof AiProduct ? $product : null;
    }

    /**
     * Unique among every product, soft-deleted included (a slug is never reused).
     */
    private function uniqueSlug(?AiProduct $product): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($product): void {
            $other = AiProduct::withTrashed()
                ->where('slug', $value)
                ->when($product !== null, fn ($query) => $query->whereKeyNot($product->id))
                ->first(['id', 'name']);

            if ($other !== null) {
                $fail("Ese slug ya lo usa {$other->name}. Prueba con otro.");
            }
        };
    }

    private function areaOfTheCompanies(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $companies = array_map('intval', array_filter((array) $this->input('company_ids', []), 'is_numeric'));
            $area = is_numeric($value) ? Area::query()->withoutGlobalScopes()->find((int) $value) : null;

            if ($area !== null && $companies !== [] && ! in_array((int) $area->company_id, $companies, true)) {
                $fail('Elige un área de la empresa del producto.');
            }
        };
    }

    private function linkable(?AiProduct $product): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($product): void {
            $keys = array_values(array_filter((array) $value, 'is_string'));

            if ($keys === []) {
                return;
            }

            $found = ProductForm::linkable($product)->whereIn('key', $keys)->pluck('key')->all();
            $missing = array_values(array_diff($keys, $found));

            if ($missing !== []) {
                $fail("La solicitud {$missing[0]} no se puede vincular.");
            }
        };
    }

    /**
     * A full http(s) address, or a "[URL DE …]" placeholder kept from the seed.
     */
    private function urlOrPlaceholder(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $value = trim((string) $value);

            if ($value === '' || (str_starts_with($value, '[') && str_ends_with($value, ']'))) {
                return;
            }

            if (! preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                $fail('Escribe la dirección completa, por ejemplo https://cartera.segurex.com.');
            }
        };
    }

    /**
     * Companies in the order the person chose, as ints.
     *
     * @return list<int>
     */
    public function companyIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->validated('company_ids'))));
    }

    /**
     * @return list<string>
     */
    public function requestKeys(): array
    {
        return array_values(array_unique(array_filter((array) ($this->validated('request_keys') ?? []), 'is_string')));
    }
}
