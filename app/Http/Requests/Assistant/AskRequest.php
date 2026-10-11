<?php

namespace App\Http\Requests\Assistant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `assistant.messages.store` (`{ question: string }`, max 500).
 * Route middleware already requires `assistant.use`.
 */
class AskRequest extends FormRequest
{
    public const MAX_LENGTH = 500;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => 'Escribe tu pregunta.',
            'question.string' => 'Escribe tu pregunta.',
            'question.max' => 'La pregunta es muy larga: máximo '.self::MAX_LENGTH.' caracteres.',
        ];
    }
}
