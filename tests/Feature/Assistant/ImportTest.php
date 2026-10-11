<?php

namespace Tests\Feature\Assistant;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\KnowledgeImport;
use App\Models\KnowledgeItem;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Excel import (PROMPT 6.8.1, `Assistant/Import/{Create,Mapping,Preview}`):
 * upload, sheet, mapping, preview, apply, errors file, template, cancel.
 */
class ImportTest extends CurationTestCase
{
    public function test_create_page_props_without_and_with_an_unfinished_file(): void
    {
        $this->assertSame($this->withShared(['file', 'max_size_label', 'now']), $this->propKeys(route('assistant.import.create')));

        $this->actingAs($this->jhon)
            ->get(route('assistant.import.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Import/Create')
                ->where('file', null)
                ->where('max_size_label', '10 MB')
                ->where('now', now()->toIso8601String())
                ->where('drafts_count', 0)
                ->where('unanswered_count', 0)
            );

        $import = $this->uploaded([
            'Informes' => [self::REPORT_HEADERS, $this->reportRow('Informe de ventas diario'), $this->reportRow('Informe de recaudo diario'), []],
            'Sistemas' => [['Nombre', 'Qué es'], ['SAP Business One', 'ERP']],
        ]);

        $this->actingAs($this->jhon)
            ->get(route('assistant.import.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('file.id', $import->id)
                ->where('file.name', 'mapa-de-sistemas.xlsx')
                ->where('file.size_bytes', fn ($size) => is_int($size) && $size > 0)
                ->where('file.uploaded_at', now()->toIso8601String())
                ->where('file.sheets', [
                    ['name' => 'Informes', 'rows' => 2, 'columns' => self::REPORT_HEADERS],
                    ['name' => 'Sistemas', 'rows' => 1, 'columns' => ['Nombre', 'Qué es']],
                ])
                ->where('file.selected_sheet', 'Informes')
                ->where('file.header_row', true)
            );
    }

    public function test_upload_validates_type_size_and_content(): void
    {
        $this->actingAs($this->jhon)->post(route('assistant.import.upload'), [])->assertSessionHasErrors('file');

        $this->actingAs($this->jhon)
            ->post(route('assistant.import.upload'), ['file' => $this->csv('a,b', 'viejo.xls')])
            ->assertSessionHasErrors(['file' => 'Solo se pueden importar archivos .xlsx o .csv. Si tienes un .xls, ábrelo en Excel y guárdalo como .xlsx.']);

        $this->actingAs($this->jhon)
            ->post(route('assistant.import.upload'), ['file' => $this->csv('esto no es un excel', 'roto.xlsx')])
            ->assertSessionHasErrors(['file' => 'No se pudo leer el archivo. Ábrelo en Excel, guárdalo de nuevo como .xlsx y vuelve a subirlo.']);

        config(['gestor.assistant.import.max_kb' => 1]);
        $this->actingAs($this->jhon)
            ->post(route('assistant.import.upload'), ['file' => $this->csv(str_repeat('x', 3000), 'grande.csv')])
            ->assertSessionHasErrors(['file' => 'El archivo puede pesar hasta 1 KB.']);

        $this->assertSame(0, KnowledgeImport::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_upload_stores_the_file_privately_and_replaces_the_unfinished_one(): void
    {
        $first = $this->uploaded(['Informes' => [self::REPORT_HEADERS, $this->reportRow('Uno')]]);

        $this->assertSame('uploaded', $first->status);
        $this->assertSame($this->jhon->id, $first->user_id);
        $this->assertStringStartsWith('assistant/imports/', $first->file_path);
        Storage::disk('local')->assertExists($first->file_path);
        $this->assertFalse(Storage::disk('public')->exists($first->file_path));

        $second = $this->uploaded(['Informes' => [self::REPORT_HEADERS, $this->reportRow('Dos')]]);

        $this->assertNull(KnowledgeImport::query()->find($first->id));
        Storage::disk('local')->assertMissing($first->file_path);
        Storage::disk('local')->assertExists($second->file_path);
    }

    public function test_update_picks_the_sheet_and_the_header_row(): void
    {
        $import = $this->uploaded([
            'Informes' => [self::REPORT_HEADERS, $this->reportRow('Informe de ventas diario')],
            'Glosario' => [['Pedido bloqueado', 'Retenido por cupo']],
        ]);

        $this->actingAs($this->jhon)
            ->put(route('assistant.import.update', $import), ['sheet' => 'Hoja que no existe', 'header_row' => true])
            ->assertSessionHasErrors(['sheet' => 'Elige una hoja del archivo.']);

        $this->actingAs($this->jhon)
            ->put(route('assistant.import.update', $import), ['sheet' => 'Glosario', 'header_row' => false])
            ->assertRedirect(route('assistant.import.mapping', $import));

        $import->refresh();
        $this->assertSame('Glosario', $import->sheet);
        $this->assertFalse($import->header_row);
        $this->assertSame('glossary', $import->type);

        $this->actingAs($this->jhon)
            ->get(route('assistant.import.mapping', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->where('file.sheets.1', ['name' => 'Glosario', 'rows' => 1, 'columns' => ['Columna A', 'Columna B']])
                ->where('mapping.columns.0', ['name' => 'Columna A', 'sample' => 'Pedido bloqueado', 'field' => null, 'match' => 'skip'])
            );
    }

    public function test_mapping_page_matches_columns_by_name(): void
    {
        $import = $this->uploaded(['Informes' => [self::REPORT_HEADERS, $this->reportRow('Informe de ventas diario', recipients: 'Gerencia; coordinadores de ventas')]]);
        $this->actingAs($this->jhon)->put(route('assistant.import.update', $import), ['sheet' => 'Informes', 'header_row' => true]);

        $this->assertSame(
            $this->withShared(['file', 'mapping', 'fields', 'review_months']),
            $this->propKeys(route('assistant.import.mapping', $import)),
        );

        $this->actingAs($this->jhon)
            ->get(route('assistant.import.mapping', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Import/Mapping')
                ->where('review_months', 6)
                ->where('mapping.file_id', $import->id)
                ->where('mapping.sheet', 'Informes')
                ->where('mapping.type', 'report')
                ->where('mapping.columns', [
                    ['name' => 'Nombre', 'sample' => 'Informe de ventas diario', 'field' => 'title', 'match' => 'auto'],
                    ['name' => 'Tipo', 'sample' => 'Informe', 'field' => 'type', 'match' => 'fixed'],
                    ['name' => 'Empresa', 'sample' => 'Segurex', 'field' => 'company', 'match' => 'auto'],
                    ['name' => 'Área', 'sample' => 'Ventas', 'field' => 'area', 'match' => 'auto'],
                    ['name' => 'Descripción', 'sample' => '', 'field' => 'description', 'match' => 'auto'],
                    ['name' => 'Sistema origen', 'sample' => 'Toma Pedidos SAP', 'field' => 'source_system', 'match' => 'auto'],
                    ['name' => 'Destinatarios', 'sample' => 'Gerencia; coordinadores de ventas', 'field' => 'recipients', 'match' => 'auto'],
                    ['name' => 'Horario', 'sample' => '6:00 a. m.', 'field' => 'schedule', 'match' => 'auto'],
                    ['name' => 'Visibilidad', 'sample' => 'Toda la empresa', 'field' => 'visibility', 'match' => 'auto'],
                    ['name' => 'Dueño', 'sample' => '', 'field' => 'owner', 'match' => 'auto'],
                    ['name' => 'Notas', 'sample' => 'Nota interna', 'field' => null, 'match' => 'skip'],
                ])
                ->where('fields.0', ['key' => 'title', 'label' => 'Título', 'required' => true])
                ->where('fields', fn ($fields) => collect($fields)->pluck('key')->all() === [
                    'title', 'type', 'company', 'area', 'description', 'visibility', 'owner', 'key',
                    'shows', 'source_system', 'recipients', 'schedule', 'format',
                ])
            );
    }

    public function test_mapping_update_requires_title_company_and_visibility_and_saves_match_kinds(): void
    {
        $import = $this->uploaded(['Informes' => [self::REPORT_HEADERS, $this->reportRow('Informe de ventas diario')]]);
        $this->actingAs($this->jhon)->put(route('assistant.import.update', $import), ['sheet' => 'Informes', 'header_row' => true]);

        $columns = array_map(fn (string $name) => ['name' => $name, 'field' => null], self::REPORT_HEADERS);
        $columns[0]['field'] = 'title';

        $this->actingAs($this->jhon)
            ->put(route('assistant.import.mapping.update', $import), ['columns' => $columns])
            ->assertSessionHasErrors(['columns' => 'Asocia una columna a Empresa y Visibilidad.']);

        $columns[2]['field'] = 'company';
        $columns[8]['field'] = 'visibility';
        $columns[10]['field'] = 'title';
        $this->actingAs($this->jhon)
            ->put(route('assistant.import.mapping.update', $import), ['columns' => $columns])
            ->assertSessionHasErrors(['columns' => 'Dos columnas no pueden llenar el mismo campo (Título).']);

        $columns[10]['field'] = 'recipients';
        $this->actingAs($this->jhon)
            ->put(route('assistant.import.mapping.update', $import), ['columns' => $columns])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('assistant.import.preview', $import));

        $import->refresh();
        $this->assertSame('mapped', $import->status);
        $this->assertSame(['name' => 'Notas', 'sample' => 'Nota interna', 'field' => 'recipients', 'match' => 'manual'], $import->mapping[10]);
        $this->assertSame(['name' => 'Destinatarios', 'sample' => 'Gerencia', 'field' => null, 'match' => 'skip'], $import->mapping[6]);
        $this->assertSame('auto', $import->mapping[0]['match']);
    }

    public function test_preview_shows_outcomes_summary_and_filters_with_a_partial_reload(): void
    {
        $existing = $this->card(['title' => 'Informe de ventas diario', 'fields' => ['shows' => 'Pedidos', 'recipients' => 'Gerencia', 'source_system' => null]]);

        $import = $this->mappedReports([
            $this->reportRow('Informe de ventas diario', recipients: 'Gerencia y coordinadores de ventas'),
            $this->reportRow('Informe de recaudo diario', owner: 'Paola Ríos', visibility: 'Solo TI'),
            $this->reportRow('Informe de flujo de caja semanal', company: 'RYR', area: 'Tesorería'),
        ]);

        $this->assertSame(
            $this->withShared(['file', 'type', 'summary', 'filter', 'rows', 'error_rows']),
            $this->propKeys(route('assistant.import.preview', $import)),
        );

        $this->actingAs($this->jhon)
            ->get(route('assistant.import.preview', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Import/Preview')
                ->where('type', 'report')
                ->where('summary', ['total' => 3, 'new' => 1, 'update' => 1, 'error' => 1])
                ->where('filter', 'all')
                ->where('error_rows', [4])
                ->has('rows', 3)
                ->where('rows.0', [
                    'row' => 4,
                    'title' => 'Informe de flujo de caja semanal',
                    'context' => 'Empresa «RYR» · Tesorería',
                    'outcome' => 'error',
                    'card' => null,
                    'changes' => [],
                    'visibility' => null,
                    'owner_name' => null,
                    'error' => [
                        'message' => 'La empresa «RYR» no existe en el Gestor.',
                        'fix' => 'En la celda C4 escribe Segurex, Derivados o Tercera (varias, separadas por coma), guarda y vuelve a subir el archivo.',
                    ],
                ])
                ->where('rows.1', [
                    'row' => 2,
                    'title' => 'Informe de ventas diario',
                    'context' => 'Segurex · Ventas',
                    'outcome' => 'update',
                    'card' => ['key' => $existing->key, 'type' => 'report', 'title' => 'Informe de ventas diario', 'visibility' => 'company'],
                    'changes' => [
                        ['label' => 'Sistema de origen', 'from' => null, 'to' => 'Toma Pedidos SAP'],
                        ['label' => 'Quién lo recibe', 'from' => 'Gerencia', 'to' => 'Gerencia y coordinadores de ventas'],
                        ['label' => 'Horario de envío', 'from' => null, 'to' => '6:00 a. m.'],
                    ],
                    'visibility' => 'company',
                    'owner_name' => 'Paola Ríos',
                    'error' => null,
                ])
                ->where('rows.2.row', 3)
                ->where('rows.2.outcome', 'new')
                ->where('rows.2.visibility', 'it')
                ->where('rows.2.owner_name', 'Paola Ríos')
                ->where('rows.2.context', 'Segurex · Ventas')
            );

        $this->assertSame('previewed', $import->fresh()->status);
        $this->assertSame(['total' => 3, 'new' => 1, 'update' => 1, 'error' => 1], $import->fresh()->stats['summary']);

        $this->actingAs($this->jhon)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Assistant/Import/Preview',
                'X-Inertia-Partial-Data' => 'rows,filter',
            ])
            ->get(route('assistant.import.preview', ['import' => $import, 'resultado' => 'error']))
            ->assertOk()
            ->assertJsonPath('props.filter', 'error')
            ->assertJsonCount(1, 'props.rows')
            ->assertJsonPath('props.rows.0.row', 4)
            ->assertJsonMissingPath('props.summary')
            ->assertJsonMissingPath('props.file');
    }

    public function test_store_creates_and_updates_cards_and_skips_error_rows(): void
    {
        $existing = $this->card(['title' => 'Informe de ventas diario', 'fields' => ['shows' => 'Pedidos', 'recipients' => 'Gerencia']]);

        $import = $this->mappedReports([
            $this->reportRow('Informe de ventas diario', recipients: 'Gerencia y coordinadores de ventas'),
            $this->reportRow('Informe de recaudo diario', company: 'Segurex, Derivados', owner: 'Paola Ríos', visibility: 'Solo TI', description: 'Recaudo del día'),
            $this->reportRow('Informe de flujo de caja semanal', company: 'RYR'),
        ]);
        $path = $import->file_path;

        $this->actingAs($this->jhon)
            ->post(route('assistant.import.store', $import))
            ->assertRedirect(route('assistant.knowledge.index'))
            ->assertSessionHas('success', 'Se importaron 2 fichas: 1 nueva y 1 actualizada. La fila 4 se saltó.');

        $created = KnowledgeItem::query()->where('title', 'Informe de recaudo diario')->firstOrFail();
        $this->assertSame('import', $created->source);
        $this->assertSame('report', $created->type);
        $this->assertSame('it', $created->visibility);
        $this->assertSame('Recaudo del día', $created->description);
        $this->assertSame($this->paola->id, $created->owner_id);
        $this->assertSame($this->ventas->id, $created->area_id);
        $this->assertSame([$this->segurex->id, $this->derivados->id], $created->companies()->orderBy('companies.id')->pluck('companies.id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('2027-04-07', $created->review_at->toDateString());
        $this->assertSame(['shows' => null, 'source_system' => 'Toma Pedidos SAP', 'recipients' => 'Gerencia', 'schedule' => '6:00 a. m.', 'format' => null], $created->fields);

        $existing->refresh();
        $this->assertSame('Gerencia y coordinadores de ventas', $existing->fields['recipients']);
        $this->assertSame('Pedidos', $existing->fields['shows']);
        $this->assertSame('manual', $existing->source);

        $this->assertSame(2, KnowledgeItem::query()->count());
        $import->refresh();
        $this->assertSame('applied', $import->status);
        $this->assertNotNull($import->applied_at);
        Storage::disk('local')->assertMissing($path);

        // An applied import is not shown again on step 1.
        $this->actingAs($this->jhon)->get(route('assistant.import.create'))->assertInertia(fn (Assert $page) => $page->where('file', null));
        $this->actingAs($this->jhon)->post(route('assistant.import.store', $import))->assertRedirect(route('assistant.import.create'));
        $this->assertSame(2, KnowledgeItem::query()->count());
    }

    public function test_rows_match_existing_cards_by_key_before_title(): void
    {
        $byKey = $this->card(['title' => 'Antiguo nombre del informe']);
        $this->card(['title' => 'Informe de sistemas', 'type' => 'system', 'fields' => ['what_is' => 'ERP']]);

        $headers = ['Clave', 'Nombre', 'Empresa', 'Visibilidad', 'Qué muestra'];
        $import = $this->uploaded(['Informes' => [
            $headers,
            [$byKey->key, 'Nuevo nombre del informe', 'Segurex', 'Toda la empresa', 'Ventas'],
            ['K-999', 'Otro', 'Segurex', 'Toda la empresa', ''],
            ['', 'Informe de sistemas', 'Segurex', 'Toda la empresa', ''],
            ['', 'Nuevo nombre del informe', 'Segurex', 'Toda la empresa', ''],
        ]]);
        $this->actingAs($this->jhon)->put(route('assistant.import.update', $import), ['sheet' => 'Informes', 'header_row' => true]);

        $this->actingAs($this->jhon)
            ->get(route('assistant.import.mapping', $import))
            ->assertInertia(fn (Assert $page) => $page->where('mapping.columns.0.field', 'key')->where('mapping.columns.4.field', 'shows'));

        $columns = [['name' => 'Clave', 'field' => 'key'], ['name' => 'Nombre', 'field' => 'title'], ['name' => 'Empresa', 'field' => 'company'], ['name' => 'Visibilidad', 'field' => 'visibility'], ['name' => 'Qué muestra', 'field' => 'shows']];
        $this->actingAs($this->jhon)->put(route('assistant.import.mapping.update', $import), ['columns' => $columns])->assertSessionHasNoErrors();

        $this->actingAs($this->jhon)
            ->get(route('assistant.import.preview', $import))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary', ['total' => 4, 'new' => 1, 'update' => 1, 'error' => 2])
                ->where('rows.0.row', 3)
                ->where('rows.0.error.message', 'La ficha «K-999» no existe en el Gestor.')
                ->where('rows.1.row', 5)
                ->where('rows.1.error.message', 'La fila 2 ya actualiza '.$byKey->key.'.')
                ->where('rows.2.row', 2)
                ->where('rows.2.card.key', $byKey->key)
                ->where('rows.2.changes.0', ['label' => 'Título', 'from' => 'Antiguo nombre del informe', 'to' => 'Nuevo nombre del informe'])
                // A system card with the same title is another type: the report is new.
                ->where('rows.3.row', 4)
                ->where('rows.3.outcome', 'new')
            );
    }

    public function test_errors_file_lists_the_error_rows_with_the_fix(): void
    {
        $import = $this->mappedReports([
            $this->reportRow('Informe de ventas diario'),
            $this->reportRow('Informe con clave', description: 'password=Secreta123'),
            $this->reportRow('', visibility: 'Pública'),
        ]);

        $response = $this->actingAs($this->jhon)->get(route('assistant.import.errors', $import))->assertOk();
        $this->assertStringContainsString('errores-mapa-de-sistemas.xlsx', (string) $response->headers->get('content-disposition'));

        $import->refresh();
        Storage::disk('local')->assertExists($import->error_path);
        $rows = $this->readXlsx(Storage::disk('local')->path($import->error_path));

        $this->assertSame(['Fila', ...self::REPORT_HEADERS, 'Error', 'Cómo corregirlo'], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame(3, (int) $rows[1][0]);
        $this->assertSame('Informe con clave', $rows[1][1]);
        $this->assertSame('La fila parece tener una contraseña o una llave.', $rows[1][12]);
        $this->assertSame('Bórrala de la celda E3: las credenciales van en Accesos o en la bóveda, nunca en una ficha.', $rows[1][13]);
        $this->assertSame(4, (int) $rows[2][0]);
        $this->assertSame('Falta el título.', $rows[2][12]);
    }

    public function test_a_semicolon_csv_imports_like_a_sheet(): void
    {
        $csv = "\xEF\xBB\xBF".implode("\n", [
            'Título;Empresa;Visibilidad;Qué muestra;Destinatarios',
            'Informe de compras del mes;Derivados;Solo TI;Compras del mes;Gerencia',
            'Informe de cartera;Segurex;Toda la empresa;"Cartera; por asesor";Asesores',
        ]);

        $this->actingAs($this->jhon)
            ->post(route('assistant.import.upload'), ['file' => $this->csv($csv, 'informes.csv')])
            ->assertSessionHasNoErrors();
        $import = KnowledgeImport::query()->latest('id')->firstOrFail();

        $this->assertSame([['name' => 'informes', 'first' => ['Título', 'Empresa', 'Visibilidad', 'Qué muestra', 'Destinatarios'], 'second' => ['Informe de compras del mes', 'Derivados', 'Solo TI', 'Compras del mes', 'Gerencia'], 'count' => 3]], $import->sheets);

        $this->actingAs($this->jhon)->put(route('assistant.import.update', $import), ['sheet' => 'informes', 'header_row' => true]);
        $this->assertSame('report', $import->fresh()->type);

        $columns = [['name' => 'Título', 'field' => 'title'], ['name' => 'Empresa', 'field' => 'company'], ['name' => 'Visibilidad', 'field' => 'visibility'], ['name' => 'Qué muestra', 'field' => 'shows'], ['name' => 'Destinatarios', 'field' => 'recipients']];
        $this->actingAs($this->jhon)->put(route('assistant.import.mapping.update', $import), ['columns' => $columns])->assertSessionHasNoErrors();
        $this->actingAs($this->jhon)->post(route('assistant.import.store', $import))->assertSessionHas('success', 'Se importaron 2 fichas: 2 nuevas.');

        $this->assertSame('Cartera; por asesor', KnowledgeItem::query()->where('title', 'Informe de cartera')->firstOrFail()->fields['shows']);
        $this->assertSame([$this->derivados->id], KnowledgeItem::query()->where('title', 'Informe de compras del mes')->firstOrFail()->companies->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_destroy_removes_the_files_and_the_import(): void
    {
        $import = $this->mappedReports([$this->reportRow('Informe de ventas diario', company: 'RYR')]);
        $this->actingAs($this->jhon)->get(route('assistant.import.errors', $import))->assertOk();
        $import->refresh();
        $paths = [$import->file_path, $import->error_path];

        $this->actingAs($this->jhon)
            ->delete(route('assistant.import.destroy', $import))
            ->assertRedirect(route('assistant.import.create'));

        $this->assertNull(KnowledgeImport::query()->find($import->id));
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_template_is_an_xlsx_with_one_sheet_per_type(): void
    {
        $response = $this->actingAs($this->jhon)->get(route('assistant.import.template'))->assertOk();
        $this->assertStringContainsString('plantilla-importacion-fichas.xlsx', (string) $response->headers->get('content-disposition'));

        $rows = $this->readXlsx($response->baseResponse->getFile()->getPathname());
        $this->assertSame(['Título', 'Tipo', 'Empresa', 'Área', 'Descripción', 'Visibilidad', 'Dueño', 'Qué es', 'Dónde corre', 'Acceso', 'Versión'], $rows[0]);
    }

    public function test_permissions_and_ownership(): void
    {
        $reader = $this->reader();

        $this->actingAs($reader)
            ->get(route('assistant.import.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Import/Create')
                ->where('file', null)
                ->where('drafts_count', 0)
                ->where('unanswered_count', 0)
            );

        $this->actingAs($reader)->post(route('assistant.import.upload'), ['file' => $this->xlsx(['A' => [['x']]])])->assertForbidden();
        $this->actingAs($reader)->get(route('assistant.import.template'))->assertForbidden();
        $this->actingAs($this->paola)->get(route('assistant.import.create'))->assertForbidden();

        $import = $this->uploaded(['Informes' => [self::REPORT_HEADERS, $this->reportRow('Uno')]]);
        $reader->givePermissionTo('assistant.knowledge.manage');

        $this->actingAs($reader)->get(route('assistant.import.mapping', $import))->assertNotFound();
        $this->actingAs($reader)->delete(route('assistant.import.destroy', $import))->assertNotFound();
        $this->actingAs($reader)->get(route('assistant.import.create'))->assertInertia(fn (Assert $page) => $page->where('file', null));
    }
}
