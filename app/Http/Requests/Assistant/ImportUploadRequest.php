<?php

namespace App\Http\Requests\Assistant;

use App\Actions\Assistant\KnowledgeImports;
use App\Domain\Assistant\Import\Spreadsheet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `assistant.import.upload` (multipart `ImportUploadPayload`):
 * one .xlsx or .csv up to `gestor.assistant.import.max_kb`. The old .xls
 * format is refused (the reader cannot open it). Route middleware already
 * requires `assistant.knowledge.manage`.
 */
class ImportUploadRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'extensions:'.implode(',', Spreadsheet::EXTENSIONS),
                'max:'.(int) config('gestor.assistant.import.max_kb', 10 * 1024),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Elige el archivo de Excel.',
            'file.file' => 'Elige el archivo de Excel.',
            'file.uploaded' => 'El archivo no se pudo subir. Intenta de nuevo.',
            'file.extensions' => 'Solo se pueden importar archivos .xlsx o .csv. Si tienes un .xls, ábrelo en Excel y guárdalo como .xlsx.',
            'file.max' => 'El archivo puede pesar hasta '.KnowledgeImports::maxSizeLabel().'.',
        ];
    }
}
