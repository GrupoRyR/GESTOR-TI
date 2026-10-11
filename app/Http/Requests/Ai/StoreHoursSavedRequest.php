<?php

namespace App\Http\Requests\Ai;

use App\Models\AiRequest;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `ai.ratings.hours.store`: `{ hours_per_week }`, 0–60 in steps of 0.5.
 * Only the requester, once in production, once (policy `reportHoursSaved`).
 */
class StoreHoursSavedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('request');

        return $request instanceof AiRequest && $this->user()?->can('reportHoursSaved', $request) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hours_per_week' => [
                'required', 'numeric', 'between:0,60',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_numeric($value) && fmod((float) $value * 2, 1.0) !== 0.0) {
                        $fail('Escribe las horas en medias horas (por ejemplo 2,5).');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hours_per_week.required' => 'Escribe cuántas horas te ahorra por semana.',
            'hours_per_week.numeric' => 'Escribe cuántas horas te ahorra por semana.',
            'hours_per_week.between' => 'Escribe entre 0 y 60 horas por semana.',
        ];
    }
}
