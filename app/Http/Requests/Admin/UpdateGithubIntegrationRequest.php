<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `admin.integrations.github.update`: `{ organization }` — a GitHub
 * organization slug (letters, numbers and single inner hyphens, up to 39
 * characters). Empty clears it. Route-gated by `settings.manage`.
 */
class UpdateGithubIntegrationRequest extends FormRequest
{
    public const SLUG_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/';

    protected function prepareForValidation(): void
    {
        $this->merge(['organization' => is_string($this->input('organization')) ? trim($this->input('organization')) : $this->input('organization')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization' => ['nullable', 'string', 'max:39', 'regex:'.self::SLUG_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organization.regex' => 'Escribe el nombre de la organización tal como aparece en github.com (letras, números y guiones).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['organization' => 'organización'];
    }
}
