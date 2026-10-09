<?php

namespace App\Http\Requests\Ai;

use App\Models\AiRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * `ai.requests.validation.update` (multipart `RequestValidationPayload`):
 * `{ criteria: [{ id, result: pass|fail, comment, photo }] }`. Only the
 * requester while "En pruebas" (policy `validate`); a failed criterion
 * needs a comment (also enforced by App\Actions\Ai\ValidateAcceptance).
 */
class UpdateAcceptanceValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('request');

        return $request instanceof AiRequest && $this->user()?->can('validate', $request) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'criteria' => ['required', 'array', 'min:1', 'max:100'],
            'criteria.*.id' => ['required', 'integer'],
            'criteria.*.result' => ['required', 'in:pass,fail'],
            'criteria.*.comment' => ['nullable', 'required_if:criteria.*.result,fail', 'string', 'max:2000'],
            'criteria.*.photo' => ['nullable', 'file'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'criteria.required' => 'Marca los criterios.',
            'criteria.*.result.*' => 'Marca si se cumple o no.',
            'criteria.*.comment.required_if' => 'Cuéntanos qué falló.',
            'criteria.*.comment.max' => 'Es muy largo: resúmelo en menos de 2.000 caracteres.',
        ];
    }

    /**
     * @return list<array{id: int, result: string, comment: ?string, photo: ?UploadedFile}>
     */
    public function results(): array
    {
        return array_values(array_map(fn (array $row, int|string $i) => [
            'id' => (int) $row['id'],
            'result' => (string) $row['result'],
            'comment' => $row['comment'] ?? null,
            'photo' => $this->file("criteria.{$i}.photo"),
        ], $this->validated('criteria'), array_keys($this->validated('criteria'))));
    }
}
