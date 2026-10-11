<?php

namespace Tests\Feature\Assistant;

use App\Models\KnowledgeImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Curation pages of the Asistente TI (types/pages/assistant-curation.ts):
 * Excel import, /aprender drafts and unanswered questions. Company short
 * names are the ones people type in the Excel ("Segurex", "Derivados").
 */
abstract class CurationTestCase extends AssistantTestCase
{
    protected const SHARED = ['auth', 'companies', 'current_company_id', 'flash', 'notifications_unread', 'modules', 'errors'];

    /** Headers of the "Informes" sheet of the design fixture (mapa-de-sistemas.xlsx). */
    protected const REPORT_HEADERS = ['Nombre', 'Tipo', 'Empresa', 'Área', 'Descripción', 'Sistema origen', 'Destinatarios', 'Horario', 'Visibilidad', 'Dueño', 'Notas'];

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->segurex->forceFill(['short_name' => 'Segurex'])->save();
        $this->derivados->forceFill(['short_name' => 'Derivados'])->save();
        $this->third->forceFill(['short_name' => 'Tercera'])->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * A real .xlsx written with OpenSpout: sheet name => rows.
     *
     * @param  array<string, list<list<mixed>>>  $sheets
     */
    protected function xlsx(array $sheets, string $name = 'mapa-de-sistemas.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $this->temporary[] = $path;

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $first = true;
        foreach ($sheets as $sheetName => $rows) {
            if (! $first) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            $writer->getCurrentSheet()->setName($sheetName);
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $first = false;
        }
        $writer->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function csv(string $content, string $name = 'informes.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
        $this->temporary[] = $path;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * One report row in the order of REPORT_HEADERS.
     *
     * @return list<string>
     */
    protected function reportRow(string $title, string $company = 'Segurex', string $area = 'Ventas', string $owner = '', string $visibility = 'Toda la empresa', string $recipients = 'Gerencia', string $description = ''): array
    {
        return [$title, 'Informe', $company, $area, $description, 'Toma Pedidos SAP', $recipients, '6:00 a. m.', $visibility, $owner, 'Nota interna'];
    }

    /**
     * Uploads a workbook as Jhon and returns the import.
     *
     * @param  array<string, list<list<mixed>>>  $sheets
     */
    protected function uploaded(array $sheets, ?User $as = null): KnowledgeImport
    {
        $this->actingAs($as ?? $this->jhon)
            ->post(route('assistant.import.upload'), ['file' => $this->xlsx($sheets)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('assistant.import.create'));

        return KnowledgeImport::query()->latest('id')->firstOrFail();
    }

    /**
     * Upload, pick the sheet and keep the automatic mapping.
     *
     * @param  list<list<mixed>>  $rows  data rows (the header is REPORT_HEADERS)
     */
    protected function mappedReports(array $rows): KnowledgeImport
    {
        $import = $this->uploaded(['Informes' => [self::REPORT_HEADERS, ...$rows]]);

        $this->actingAs($this->jhon)
            ->put(route('assistant.import.update', $import), ['sheet' => 'Informes', 'header_row' => true])
            ->assertRedirect(route('assistant.import.mapping', $import));

        $columns = array_map(fn (string $name) => ['name' => $name, 'field' => match ($name) {
            'Nombre' => 'title',
            'Tipo' => 'type',
            'Empresa' => 'company',
            'Área' => 'area',
            'Descripción' => 'description',
            'Sistema origen' => 'source_system',
            'Destinatarios' => 'recipients',
            'Horario' => 'schedule',
            'Visibilidad' => 'visibility',
            'Dueño' => 'owner',
            default => null,
        }], self::REPORT_HEADERS);

        $this->actingAs($this->jhon)
            ->put(route('assistant.import.mapping.update', $import), ['columns' => $columns])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('assistant.import.preview', $import));

        return $import->fresh();
    }

    /**
     * Rows of an .xlsx file on disk.
     *
     * @return list<list<mixed>>
     */
    protected function readXlsx(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    /**
     * @return list<string>
     */
    protected function propKeys(string $url, ?User $as = null): array
    {
        $keys = array_keys($this->actingAs($as ?? $this->jhon)->get($url)->assertOk()->viewData('page')['props']);
        sort($keys);

        return $keys;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function withShared(array $keys): array
    {
        $all = [...self::SHARED, ...$keys, 'drafts_count', 'unanswered_count'];
        sort($all);

        return $all;
    }
}
