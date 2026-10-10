<?php

namespace App\Http\Requests\Ai;

use App\Models\AiProjectCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `CostPayload` of "Agregar costo" (`ai.costs.store|update`, multipart):
 * only the types entered by hand (team hours and AI usage are computed),
 * once or monthly, amount in COP and an optional receipt (same file rules
 * as the task attachments, `gestor.ai.attachments`).
 */
class ProjectCostRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $config = (array) config('gestor.ai.attachments');

        return [
            'type' => ['required', Rule::in(AiProjectCost::TYPES)],
            'concept' => ['required', 'string', 'max:160'],
            'recurrence' => ['required', Rule::in(AiProjectCost::RECURRENCES)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'attachment' => ['nullable', 'file', 'max:'.(int) $config['max_kb'], 'mimetypes:'.implode(',', (array) $config['mimetypes'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = intdiv((int) config('gestor.ai.attachments.max_kb'), 1024);

        return [
            'type.*' => 'Elige el tipo.',
            'concept.required' => 'Escribe el concepto.',
            'concept.max' => 'Escribe el concepto en máximo 160 caracteres.',
            'recurrence.*' => 'Elige si es un pago único o mensual.',
            'amount.*' => 'Pon el valor en pesos.',
            'date.*' => 'Elige la fecha.',
            'attachment.max' => "El soporte puede pesar hasta {$maxMb} MB.",
            'attachment.*' => 'El soporte debe ser una imagen, un PDF, un Excel o un texto.',
        ];
    }
}
