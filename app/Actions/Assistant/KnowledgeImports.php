<?php

namespace App\Actions\Assistant;

use App\Domain\Assistant\CompanyNames;
use App\Domain\Assistant\Import\ImportAnalyzer;
use App\Domain\Assistant\Import\ImportFields;
use App\Domain\Assistant\Import\ImportRow;
use App\Domain\Assistant\Import\Spreadsheet;
use App\Domain\Assistant\Import\UnreadableSpreadsheet;
use App\Domain\Assistant\KnowledgeTypes;
use App\Models\KnowledgeImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Excel import of knowledge cards (PROMPT 6.8.1, "importación desde
 * Excel"), step by step:
 *
 * 1. `upload`: reads every sheet (name, first rows, size) and keeps the
 *    file on the private disk (`gestor.assistant.import.disk`,
 *    `assistant/imports/{user}`). A person has one unfinished import: a new
 *    upload replaces it.
 * 2. `selectSheet`: sheet + "la fila 1 tiene los nombres" and the sheet
 *    type (a "Tipo" column, else the sheet name, else Informe).
 * 3. `saveMapping`: which field each column feeds (title, company and
 *    visibility required).
 * 4. `analyze` (preview) and `apply`: App\Domain\Assistant\Import\ImportAnalyzer;
 *    `apply` creates or updates the cards with KnowledgeItems (source
 *    `import`), skips the error rows and deletes the original file.
 * 5. `errorsFile`: .xlsx with the error rows and how to fix each one.
 */
final class KnowledgeImports
{
    /** Rows read to find the sheet type in a "Tipo" column. */
    private const TYPE_SAMPLE_ROWS = 200;

    public function __construct(
        private readonly Spreadsheet $spreadsheet,
        private readonly ImportAnalyzer $analyzer,
        private readonly KnowledgeItems $items,
    ) {}

    /**
     * @throws ValidationException on `file` when the file cannot be read
     */
    public function upload(User $user, UploadedFile $file): KnowledgeImport
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $name = $file->getClientOriginalName();

        try {
            $sheets = $this->spreadsheet->sheets((string) $file->getRealPath(), $extension, pathinfo($name, PATHINFO_FILENAME));
        } catch (UnreadableSpreadsheet) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo. Ábrelo en Excel, guárdalo de nuevo como .xlsx y vuelve a subirlo.']);
        }

        if ($sheets === [] || array_sum(array_column($sheets, 'count')) === 0) {
            throw ValidationException::withMessages(['file' => 'El archivo está vacío.']);
        }

        KnowledgeImport::query()->where('user_id', $user->id)->unfinished()->get()->each(fn (KnowledgeImport $old) => $this->delete($old));

        $disk = self::disk();
        $path = $file->storeAs('assistant/imports/'.$user->id, Str::uuid()->toString().'.'.$extension, $disk);

        return KnowledgeImport::query()->create([
            'user_id' => $user->id,
            'file_name' => mb_substr($name, 0, 255),
            'file_disk' => $disk,
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'status' => 'uploaded',
            'sheets' => $sheets,
            'sheet' => $sheets[0]['name'],
            'header_row' => true,
        ]);
    }

    /**
     * @throws ValidationException on `sheet`
     */
    public function selectSheet(KnowledgeImport $import, string $sheet, bool $headerRow): KnowledgeImport
    {
        $info = collect($import->sheets)->firstWhere('name', $sheet);
        if ($info === null) {
            throw ValidationException::withMessages(['sheet' => 'Elige una hoja del archivo.']);
        }

        $rows = $headerRow ? max(0, $info['count'] - 1) : $info['count'];
        $max = (int) config('gestor.assistant.import.max_rows', 2000);
        if ($rows === 0) {
            throw ValidationException::withMessages(['sheet' => 'Esa hoja no tiene filas para importar.']);
        }
        if ($rows > $max) {
            throw ValidationException::withMessages(['sheet' => "La hoja tiene {$rows} filas; se pueden importar hasta {$max} por archivo."]);
        }

        $import->forceFill([
            'sheet' => $sheet,
            'header_row' => $headerRow,
            'type' => $this->detectType($import, $sheet, $headerRow),
            'mapping' => null,
            'stats' => null,
            'status' => 'uploaded',
        ])->save();

        return $import;
    }

    /**
     * Columns of the selected sheet with their field: the saved mapping or
     * the automatic one.
     *
     * @return list<array{name: string, sample: string, field: ?string, match: string}>
     */
    public function columns(KnowledgeImport $import): array
    {
        if ($import->mapping !== null) {
            return $import->mapping;
        }

        [$names, $samples] = self::headers($import);
        $automatic = ImportFields::autoMap($names, (string) $import->type);
        $columns = [];

        foreach ($names as $position => $name) {
            $columns[] = [
                'name' => $name,
                'sample' => $samples[$position] ?? '',
                'field' => $automatic[$position],
                'match' => ImportFields::matchKind($automatic[$position], $automatic[$position]),
            ];
        }

        return $columns;
    }

    /**
     * @param  list<array{name: string, field: ?string}>  $wanted
     *
     * @throws ValidationException on `columns`
     */
    public function saveMapping(KnowledgeImport $import, array $wanted): KnowledgeImport
    {
        $type = (string) $import->type;
        [$names, $samples] = self::headers($import);
        $byName = [];
        foreach ($wanted as $column) {
            $byName[$column['name']] = $column['field'];
        }

        if (array_diff($names, array_keys($byName)) !== [] || count($byName) !== count($names)) {
            throw ValidationException::withMessages(['columns' => 'Las columnas no coinciden con la hoja. Vuelve al paso 1.']);
        }

        $allowed = ImportFields::keys($type);
        $fields = [];
        foreach ($names as $name) {
            $field = $byName[$name];
            if ($field !== null && ! in_array($field, $allowed, true)) {
                throw ValidationException::withMessages(['columns' => "La columna {$name} tiene un campo que no existe."]);
            }
            if ($field !== null && in_array($field, $fields, true)) {
                throw ValidationException::withMessages(['columns' => 'Dos columnas no pueden llenar el mismo campo ('.ImportFields::label($type, $field).').']);
            }
            $fields[] = $field;
        }

        $missing = ImportFields::missingRequired($type, $fields);
        if ($missing !== []) {
            $labels = array_map(fn (string $key) => ImportFields::label($type, $key), $missing);

            throw ValidationException::withMessages(['columns' => 'Asocia una columna a '.CompanyNames::join($labels).'.']);
        }

        $automatic = ImportFields::autoMap($names, $type);
        $mapping = [];
        foreach ($names as $position => $name) {
            $mapping[] = [
                'name' => $name,
                'sample' => $samples[$position] ?? '',
                'field' => $fields[$position],
                'match' => ImportFields::matchKind($fields[$position], $automatic[$position]),
            ];
        }

        $import->forceFill(['mapping' => $mapping, 'status' => 'mapped', 'stats' => null])->save();

        return $import;
    }

    /**
     * What every row would do; records the summary and marks the import
     * previewed.
     *
     * @return list<ImportRow>
     */
    public function analyze(KnowledgeImport $import, User $user): array
    {
        $rows = $this->rows($import, $user);

        $import->forceFill([
            'status' => 'previewed',
            'stats' => self::stats($rows),
        ])->save();

        return $rows;
    }

    /**
     * Creates and updates the cards of every row without errors.
     *
     * Null when no row can be imported (nothing changes).
     *
     * @return array{new: int, update: int, error_rows: list<int>}|null
     */
    public function apply(KnowledgeImport $import, User $user): ?array
    {
        $rows = $this->rows($import, $user);
        $stats = self::stats($rows);

        if ($stats['summary']['new'] + $stats['summary']['update'] === 0) {
            return null;
        }

        DB::transaction(function () use ($rows, $user): void {
            foreach ($rows as $row) {
                if ($row->outcome === 'new' && $row->data !== null) {
                    $this->items->create($user, $row->data, 'import');
                } elseif ($row->outcome === 'update' && $row->card !== null && $row->data !== null) {
                    $this->items->update($user, $row->card, $row->data);
                }
            }
        });

        if ($stats['error_rows'] !== []) {
            $this->writeErrors($import, $rows);
        }

        Storage::disk($import->file_disk)->delete((string) $import->file_path);
        $import->forceFill(['status' => 'applied', 'stats' => $stats, 'applied_at' => now()])->save();

        activity('knowledge')
            ->causedBy($user)
            ->performedOn($import)
            ->event('imported')
            ->withProperties(['file' => $import->file_name, 'sheet' => $import->sheet, ...$stats['summary']])
            ->log('importó fichas desde Excel');

        return [
            'new' => $stats['summary']['new'],
            'update' => $stats['summary']['update'],
            'error_rows' => $stats['error_rows'],
        ];
    }

    /**
     * Path (on the import disk) of the errors .xlsx; written from a fresh
     * analysis while the original file exists.
     */
    public function errorsFile(KnowledgeImport $import, User $user): ?string
    {
        if (! $import->isApplied()) {
            $this->writeErrors($import, $this->rows($import, $user));
        }

        return $import->error_path !== null && Storage::disk($import->file_disk)->exists($import->error_path) ? $import->error_path : null;
    }

    /**
     * "Quitar el archivo" / "Cancelar": the files and the record.
     */
    public function delete(KnowledgeImport $import): void
    {
        $disk = Storage::disk($import->file_disk);
        foreach ([$import->file_path, $import->error_path] as $path) {
            if ($path !== null) {
                $disk->delete($path);
            }
        }

        $import->delete();
    }

    /**
     * "Plantilla de importación": one sheet per type with its headers.
     */
    public function template(string $path): void
    {
        $sheets = [];

        foreach (KnowledgeTypes::TYPES as $type) {
            $headers = ['Título', 'Tipo', 'Empresa', 'Área', 'Descripción', 'Visibilidad', 'Dueño'];
            foreach (ImportFields::fieldsFor($type) as $field) {
                if (! in_array($field['key'], ImportFields::COMMON_KEYS, true)) {
                    $headers[] = $field['label'];
                }
            }
            $sheets[ImportFields::SHEET_NAMES[$type]] = [$headers];
        }

        Spreadsheet::write($path, $sheets);
    }

    /**
     * Upload limit as shown ("10 MB").
     */
    public static function maxSizeLabel(): string
    {
        $kb = (int) config('gestor.assistant.import.max_kb', 10 * 1024);

        return $kb % 1024 === 0 ? intdiv($kb, 1024).' MB' : $kb.' KB';
    }

    public static function disk(): string
    {
        return (string) config('gestor.assistant.import.disk', 'local');
    }

    /**
     * Column names (empty or repeated headers get a name) and the example
     * value of each one, for the selected sheet.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function headers(KnowledgeImport $import): array
    {
        return self::sheetColumns(collect($import->sheets)->firstWhere('name', $import->sheet) ?? ['first' => [], 'second' => []], $import->header_row);
    }

    /**
     * Column names and examples of one sheet as read on upload.
     *
     * @param  array{first: list<string>, second: list<string>}  $info
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function sheetColumns(array $info, bool $headerRow): array
    {
        $first = $info['first'];
        $second = $info['second'];

        if (! $headerRow) {
            $width = max(count($first), 1);
            $names = array_map(fn (int $position) => 'Columna '.Spreadsheet::column($position), range(0, $width - 1));

            return [$names, array_pad($first, $width, '')];
        }

        $names = [];
        $seen = [];
        foreach ($first as $position => $header) {
            $name = $header !== '' ? $header : 'Columna '.Spreadsheet::column($position);
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            $names[] = $seen[$name] > 1 ? "{$name} ({$seen[$name]})" : $name;
        }

        return [$names, array_pad(array_slice($second, 0, count($names)), count($names), '')];
    }

    /**
     * @return list<ImportRow>
     */
    private function rows(KnowledgeImport $import, User $user): array
    {
        $disk = Storage::disk($import->file_disk);
        if ($import->file_path === null || ! $disk->exists($import->file_path)) {
            $this->fail($import, 'El archivo ya no está disponible.');
        }

        try {
            return $this->analyzer->analyze($import, $user, $disk->path((string) $import->file_path));
        } catch (UnreadableSpreadsheet) {
            $this->fail($import, 'No se pudo leer el archivo.');
        }
    }

    private function fail(KnowledgeImport $import, string $message): never
    {
        $import->forceFill(['status' => 'failed', 'error_message' => $message])->save();

        throw ValidationException::withMessages(['file' => $message.' Vuelve a subirlo.']);
    }

    /**
     * @param  list<ImportRow>  $rows
     * @return array{summary: array{total: int, new: int, update: int, error: int}, error_rows: list<int>}
     */
    public static function stats(array $rows): array
    {
        $summary = ['total' => count($rows), 'new' => 0, 'update' => 0, 'error' => 0];
        $errors = [];

        foreach ($rows as $row) {
            $summary[$row->outcome]++;
            if ($row->outcome === 'error') {
                $errors[] = $row->row;
            }
        }

        return ['summary' => $summary, 'error_rows' => $errors];
    }

    /**
     * @param  list<ImportRow>  $rows
     */
    private function writeErrors(KnowledgeImport $import, array $rows): void
    {
        [$names] = self::headers($import);
        $sheet = [['Fila', ...$names, 'Error', 'Cómo corregirlo']];

        foreach ($rows as $row) {
            if ($row->error !== null) {
                $sheet[] = [$row->row, ...array_pad($row->cells, count($names), ''), $row->error['message'], $row->error['fix']];
            }
        }

        $temporary = tempnam(sys_get_temp_dir(), 'kimp');
        $file = $temporary.'.xlsx';
        @unlink($temporary);

        try {
            Spreadsheet::write($file, ['Errores' => $sheet]);
            $path = $import->error_path ?? 'assistant/imports/'.$import->user_id.'/errores-'.$import->id.'.xlsx';
            Storage::disk($import->file_disk)->put($path, (string) file_get_contents($file));
        } finally {
            @unlink($file);
        }

        $import->forceFill(['error_path' => $path])->save();
    }

    private function detectType(KnowledgeImport $import, string $sheet, bool $headerRow): string
    {
        $info = collect($import->sheets)->firstWhere('name', $sheet);
        $typeColumn = null;
        if ($headerRow) {
            foreach ($info['first'] as $position => $header) {
                if (in_array(ImportFields::normalize($header), ['tipo', 'tipo de ficha'], true)) {
                    $typeColumn = $position;
                    break;
                }
            }
        }

        $votes = $typeColumn !== null ? $this->typeVotes($import, $sheet, $typeColumn) : [];
        if ($votes !== []) {
            arsort($votes);

            return (string) array_key_first($votes);
        }

        return ImportFields::typeFromName($sheet) ?? ImportFields::DEFAULT_TYPE;
    }

    /**
     * Types named in the "Tipo" column of the first data rows.
     *
     * @return array<string, int>
     */
    private function typeVotes(KnowledgeImport $import, string $sheet, int $column): array
    {
        $votes = [];
        $seen = 0;

        try {
            $path = Storage::disk($import->file_disk)->path((string) $import->file_path);
            foreach ($this->spreadsheet->rows($path, $import->extension(), $sheet) as $cells) {
                // The first row is the header; 200 data rows are enough.
                if ($seen++ === 0) {
                    continue;
                }
                if ($seen > self::TYPE_SAMPLE_ROWS) {
                    break;
                }
                $type = ImportFields::typeFromName($cells[$column] ?? '');
                if ($type !== null) {
                    $votes[$type] = ($votes[$type] ?? 0) + 1;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $votes;
    }
}
