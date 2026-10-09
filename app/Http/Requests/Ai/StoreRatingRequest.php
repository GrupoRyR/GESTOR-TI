<?php

namespace App\Http\Requests\Ai;

use App\Models\AiRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `ai.ratings.store`: `{ need_score: 1–5, ease_score: 1–5, comment: string|null }`.
 * Only the requester, in production, once (policy `rate`).
 */
class StoreRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('request');

        return $request instanceof AiRequest && $this->user()?->can('rate', $request) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'need_score' => ['required', 'integer', 'between:1,5'],
            'ease_score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'need_score.*' => 'Escoge de 1 a 5 cuánto resolvió tu necesidad.',
            'ease_score.*' => 'Escoge de 1 a 5 qué tan fácil es de usar.',
            'comment.max' => 'Es muy largo: resúmelo en menos de 2.000 caracteres.',
        ];
    }
}
