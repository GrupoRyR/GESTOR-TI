<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditEntries;
use App\Support\Paginated;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administración › Auditoría (`Admin/Audit/Index`): who did what, when and
 * from where, with filters `{causer_id, module, sensitive_only, range}`,
 * and the same listing as a CSV download. Company scope, rendering and
 * sanitizing live in App\Support\Audit\AuditEntries.
 */
class AuditController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(private readonly AuditEntries $entries) {}

    public function index(Request $request): Response
    {
        $filters = $this->entries->filters($request);

        $paginator = $this->entries->query($filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Admin/Audit/Index', [
            'entries' => Paginated::from($paginator, fn (Activity $activity) => $this->entries->present($activity)),
            'filters' => $filters,
            'causer_options' => $this->entries->causerOptions(),
            'module_options' => $this->entries->moduleOptions(),
        ]);
    }

    /**
     * CSV (`;`-separated, UTF-8 with BOM for Excel in es-CO), newest first,
     * at most `gestor.audit.export_limit` rows. Cells that a spreadsheet
     * would run as a formula are prefixed with `'`.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->entries->filters($request);
        $limit = (int) config('gestor.audit.export_limit', 10000);
        $filename = 'auditoria-'.now()->setTimezone('America/Bogota')->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($filters, $limit) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, AuditEntries::csvHeaders(), ';', '"', '');

            $ids = $this->entries->query($filters)->limit($limit)->pluck('id')->all();

            foreach (array_chunk($ids, 500) as $chunk) {
                Activity::query()
                    ->with(['causer', 'subject'])
                    ->whereIn('id', $chunk)
                    ->orderByDesc('id')
                    ->get()
                    ->each(function (Activity $activity) use ($out) {
                        fputcsv($out, array_map($this->safeCell(...), $this->entries->csvRow($activity)), ';', '"', '');
                    });
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
