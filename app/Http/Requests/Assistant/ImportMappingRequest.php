<?php

namespace App\Http\Requests\Assistant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `assistant.import.mapping.update` (`ImportMappingPayload`): every
 * column of the sheet with its field (null = not imported). Which fields
 * exist, repeats and required fields are checked by
 * App\Actions\Assistant\KnowledgeImports::saveMapping (errors on `columns`).
 */
class ImportMappingRequest extends FormRequest
{
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
            'columns' => ['required', 'array', 'max:200'],
            'columns.*.name' => ['required', 'string', 'max:255'],
            'columns.*.field' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'columns.*' => 'Las columnas no coinciden con la hoja. Vuelve al paso 1.',
        ];
    }

    /**
     * @return list<array{name: string, field: ?string}>
     */
    public function columns(): array
    {
        return array_values(array_map(fn (array $column) => [
            'name' => (string) $column['name'],
            'field' => isset($column['field']) && $column['field'] !== '' ? (string) $column['field'] : null,
        ], (array) $this->validated('columns')));
    }
}
