<?php

namespace App\Http\Requests\Admin;

use App\Support\Integrations\AiProviderSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `admin.integrations.ai.update`: `{ provider, model, monthly_cap_usd|null }`.
 * Provider from config/gestor.php `ai.providers`; the model is any model id
 * (the selector offers a curated list); the cap is in USD, null/0 = no cap.
 * Route-gated by `settings.manage`.
 */
class UpdateAiIntegrationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(array_keys(AiProviderSettings::providers()))],
            'model' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            'monthly_cap_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.in' => 'Escoge un proveedor de la lista.',
            'model.regex' => 'Escribe el identificador del modelo, sin espacios.',
            'monthly_cap_usd.min' => 'Escribe un valor en dólares mayor que cero, o déjalo vacío.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'provider' => 'proveedor',
            'model' => 'modelo',
            'monthly_cap_usd' => 'tope mensual',
        ];
    }
}
