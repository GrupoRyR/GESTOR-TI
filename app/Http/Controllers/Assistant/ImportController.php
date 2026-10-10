<?php

namespace App\Http\Controllers\Assistant;

use App\Actions\Assistant\KnowledgeImports;
use App\Domain\Assistant\CompanyNames;
use App\Domain\Assistant\Import\ImportFields;
use App\Domain\Assistant\Import\ImportRow;
use App\Domain\Assistant\KnowledgeTypes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\ImportMappingRequest;
use App\Http\Requests\Assistant\ImportUploadRequest;
use App\Models\KnowledgeImport;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Support\Assistant\AssistantTabCounts;
use App\Support\Assistant\Presenters\CurationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Importar desde Excel" (PROMPT 6.8.1, types/pages/assistant-curation.ts):
 * `Assistant/Import/Create` (step 1), `Mapping` (step 2), `Preview`
 * (step 3), App\Actions\Assistant\KnowledgeImports does the work.
 *
 * - `create`: `assistant.use` or `assistant.knowledge.manage`; without the
 *   latter the page renders NoPermission with the same keys empty.
 * - Everything else: route middleware `can:assistant.knowledge.manage`.
 *   An import belongs to the person who uploaded it (anybody else: 404).
 * - An applied import cannot be changed again (back to step 1).
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly KnowledgeImports $imports,
        private readonly AssistantTabCounts $tabs,
    ) {}

    public function create(Request $http): Response
    {
        $user = $this->user($http);
        abort_unless($user->can(KnowledgeItem::USE_PERMISSION) || KnowledgeItem::canManage($user), 403);

        $import = KnowledgeItem::canManage($user)
            ? KnowledgeImport::query()->where('user_id', $user->id)->unfinished()->latest('id')->first()
            : null;

        return Inertia::render('Assistant/Import/Create', [
            'file' => $import !== null ? CurationPresenter::importFile($import) : null,
            'max_size_label' => KnowledgeImports::maxSizeLabel(),
            'now' => now()->toIso8601String(),
            ...$this->tabs->for($user),
        ]);
    }

    public function upload(ImportUploadRequest $http): RedirectResponse
    {
        $this->imports->upload($this->user($http), $http->file('file'));

        return redirect()->route('assistant.import.create');
    }

    public function update(Request $http, KnowledgeImport $import): RedirectResponse
    {
        $this->own($http, $import);
        if ($import->isApplied()) {
            return $this->applied();
        }

        $data = $http->validate([
            'sheet' => ['required', 'string', 'max:100'],
            'header_row' => ['required', 'boolean'],
        ], [
            'sheet.*' => 'Elige una hoja del archivo.',
            'header_row.*' => 'Indica si la fila 1 tiene los nombres de las columnas.',
        ]);

        $this->imports->selectSheet($import, (string) $data['sheet'], (bool) $data['header_row']);

        return redirect()->route('assistant.import.mapping', $import);
    }

    public function destroy(Request $http, KnowledgeImport $import): RedirectResponse
    {
        $this->own($http, $import);
        $this->imports->delete($import);

        return redirect()->route('assistant.import.create');
    }

    public function mapping(Request $http, KnowledgeImport $import): Response|RedirectResponse
    {
        $this->own($http, $import);
        if ($import->isApplied()) {
            return $this->applied();
        }
        if ($import->type === null) {
            return redirect()->route('assistant.import.create');
        }

        $type = (string) $import->type;

        return Inertia::render('Assistant/Import/Mapping', [
            'file' => CurationPresenter::importFile($import),
            'mapping' => [
                'file_id' => $import->id,
                'sheet' => (string) $import->sheet,
                'type' => $type,
                'columns' => $this->imports->columns($import),
            ],
            'fields' => ImportFields::fieldsFor($type),
            'review_months' => KnowledgeTypes::REVIEW_MONTHS,
            ...$this->tabs->for($this->user($http)),
        ]);
    }

    public function updateMapping(ImportMappingRequest $http, KnowledgeImport $import): RedirectResponse
    {
        $this->own($http, $import);
        if ($import->isApplied()) {
            return $this->applied();
        }
        if ($import->type === null) {
            return redirect()->route('assistant.import.create');
        }

        $this->imports->saveMapping($import, $http->columns());

        return redirect()->route('assistant.import.preview', $import);
    }

    /**
     * Step 3. Rows worth reviewing: errors, updates, then the first
     * `gestor.assistant.import.preview_new_rows` new ones (`?resultado=`
     * filters; the tiles reload only `rows` and `filter`).
     */
    public function preview(Request $http, KnowledgeImport $import): Response|RedirectResponse
    {
        $user = $this->user($http);
        $this->own($http, $import);
        if ($import->isApplied()) {
            return $this->applied();
        }
        if ($import->mapping === null) {
            return redirect()->route($import->type === null ? 'assistant.import.create' : 'assistant.import.mapping', $import);
        }

        $rows = $this->imports->analyze($import, $user);
        $filter = in_array($http->query('resultado'), ['new', 'update', 'error'], true) ? (string) $http->query('resultado') : 'all';
        $stats = KnowledgeImports::stats($rows);

        return Inertia::render('Assistant/Import/Preview', [
            'file' => CurationPresenter::importFile($import),
            'type' => (string) $import->type,
            'summary' => $stats['summary'],
            'filter' => $filter,
            'rows' => $this->shown($rows, $filter),
            'error_rows' => $stats['error_rows'],
            ...$this->tabs->for($user),
        ]);
    }

    public function store(Request $http, KnowledgeImport $import): RedirectResponse
    {
        $this->own($http, $import);
        if ($import->isApplied()) {
            return $this->applied();
        }
        if ($import->mapping === null) {
            return redirect()->route('assistant.import.mapping', $import);
        }

        $result = $this->imports->apply($import, $this->user($http));
        if ($result === null) {
            return redirect()->route('assistant.import.preview', $import)->with('error', 'No hay filas sin errores para importar.');
        }

        return redirect()->route('assistant.knowledge.index')->with('success', self::summary($result));
    }

    /**
     * "Descargar errores": .xlsx with the error rows and a column with the fix.
     */
    public function errors(Request $http, KnowledgeImport $import): StreamedResponse|RedirectResponse
    {
        $this->own($http, $import);

        $path = $this->imports->errorsFile($import, $this->user($http));
        if ($path === null) {
            return back()->with('error', 'Este archivo no tiene filas con error.');
        }

        $name = 'errores-'.pathinfo($import->file_name, PATHINFO_FILENAME).'.xlsx';

        return Storage::disk($import->file_disk)->download($path, $name);
    }

    /**
     * "Plantilla de importación": one sheet per card type.
     */
    public function template(): BinaryFileResponse
    {
        $temporary = tempnam(sys_get_temp_dir(), 'ktpl');
        $path = $temporary.'.xlsx';
        @unlink($temporary);

        $this->imports->template($path);

        return response()->download($path, 'plantilla-importacion-fichas.xlsx')->deleteFileAfterSend();
    }

    /**
     * Rows of the filter, errors and updates first, then a sample of new rows.
     *
     * @param  list<ImportRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function shown(array $rows, string $filter): array
    {
        $order = ['error' => 0, 'update' => 1, 'new' => 2];
        $selected = array_values(array_filter($rows, fn (ImportRow $row) => $filter === 'all' || $row->outcome === $filter));
        usort($selected, fn (ImportRow $a, ImportRow $b) => [$order[$a->outcome], $a->row] <=> [$order[$b->outcome], $b->row]);

        $limit = (int) config('gestor.assistant.import.preview_new_rows', 20);
        $shown = [];
        $new = 0;
        foreach ($selected as $row) {
            if ($row->outcome === 'new' && $new++ >= $limit) {
                continue;
            }
            $shown[] = $row->toArray();
        }

        return $shown;
    }

    /**
     * "Se importaron 31 fichas: 28 nuevas y 3 actualizadas. La fila 17 se saltó."
     *
     * @param  array{new: int, update: int, error_rows: list<int>}  $result
     */
    private static function summary(array $result): string
    {
        $total = $result['new'] + $result['update'];
        $parts = [];
        if ($result['new'] > 0) {
            $parts[] = $result['new'].($result['new'] === 1 ? ' nueva' : ' nuevas');
        }
        if ($result['update'] > 0) {
            $parts[] = $result['update'].($result['update'] === 1 ? ' actualizada' : ' actualizadas');
        }

        $message = ($total === 1 ? 'Se importó 1 ficha: ' : "Se importaron {$total} fichas: ").CompanyNames::join($parts).'.';
        $skipped = $result['error_rows'];
        if (count($skipped) === 1) {
            $message .= " La fila {$skipped[0]} se saltó.";
        } elseif ($skipped !== []) {
            $message .= ' Las filas '.CompanyNames::join(array_map('strval', $skipped)).' se saltaron.';
        }

        return $message;
    }

    private function applied(): RedirectResponse
    {
        return redirect()->route('assistant.import.create')->with('error', 'Esta importación ya se aplicó. Sube el archivo de nuevo para otra.');
    }

    private function own(Request $http, KnowledgeImport $import): void
    {
        abort_unless((int) $import->user_id === (int) $this->user($http)->id, 404);
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
