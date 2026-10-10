<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\ValueDashboard;
use App\Http\Controllers\Controller;
use App\Models\AiProduct;
use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\Presenters\TriagePresenter;
use App\Support\Ai\ValueReportHtml;
use App\Support\Tenancy\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Value dashboard (PROMPT 6.4.5 + 6.4.11, `Ai/Value/Index` of
 * types/pages/ai-value-vault.ts), `ai.manage` (Jhon, Leonardo, gerencia).
 *
 * Filters (query): `period` 3m|6m|12m|year (default 6m), `company_id`
 * (only in "Todas"; otherwise the company in view), `area_id` (an area of
 * the scope). Figures: App\Domain\Ai\ValueDashboard. Money (`monthly_cost`,
 * `roi`, `hour_value`, `payback_months`, notes about cost) only with
 * `ai.costs.view`; without it the keys go out null.
 *
 * `export` ("Descargar para la junta") renders the same figures as a PDF.
 */
class ValueController extends Controller
{
    public function __construct(
        private readonly ValueDashboard $dashboard,
        private readonly CurrentCompany $companies,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = $this->filters($request, $user);
        $data = $this->data($filters, $user);

        return Inertia::render('Ai/Value/Index', [
            'metrics' => $data['metrics'],
            'filters' => $filters,
            'areas' => $this->areas($filters['company_id'])->map(fn (Area $area) => TriagePresenter::areaOption($area))->values()->all(),
            'products' => $data['products'],
            'area_notes' => $data['area_notes'],
            'roi_note' => $data['roi_note'],
            'awaiting_rating' => $data['awaiting'] === null ? null : [
                'request_key' => $data['awaiting']->key,
                'title' => $data['awaiting']->title,
                'requester' => RequestPresenter::person($data['awaiting']->requester),
            ],
            'cost_note' => $data['cost_note'],
            'data_note' => null,
            'now' => now()->toIso8601String(),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $user = $this->user($request);
        $filters = $this->filters($request, $user);
        $data = $this->data($filters, $user);

        $html = ValueReportHtml::render($data['metrics'], $data['products'], $data['area_notes'], $this->scopeLabel($filters));
        $name = 'valor-gestor-ia-'.now()->timezone(ValueDashboard::TIMEZONE)->toDateString().'.pdf';

        return Pdf::loadHTML($html)->setPaper('letter')->download($name)
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /**
     * @return array{period: string, company_id: ?int, area_id: ?int}
     */
    private function filters(Request $request, User $user): array
    {
        $period = (string) $request->query('period', ValueDashboard::DEFAULT_PERIOD);
        $period = array_key_exists($period, ValueDashboard::PERIODS) ? $period : ValueDashboard::DEFAULT_PERIOD;

        $companyId = $this->companies->forUser($user);
        if ($companyId === null && $this->companies->isAll() && is_numeric($request->query('company_id'))) {
            $companyId = $this->companies->canAccess($user, (int) $request->query('company_id')) ? (int) $request->query('company_id') : null;
        }

        $areaId = is_numeric($request->query('area_id')) ? (int) $request->query('area_id') : null;
        if ($areaId !== null && ! $this->areas($companyId)->contains('id', $areaId)) {
            $areaId = null;
        }

        return ['period' => $period, 'company_id' => $companyId, 'area_id' => $areaId];
    }

    /**
     * @param  array{period: string, company_id: ?int, area_id: ?int}  $filters
     * @return array<string, mixed>
     */
    private function data(array $filters, User $user): array
    {
        return $this->dashboard->build(
            $filters['period'],
            $filters['company_id'],
            $filters['area_id'],
            $this->products($user, $filters['company_id'], $filters['area_id']),
            $user->can('ai.costs.view'),
        );
    }

    /**
     * Active areas of the scope, by name.
     *
     * @return Collection<int, Area>
     */
    private function areas(?int $companyId): Collection
    {
        return Area::query()
            ->where('is_active', true)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, AiProduct>
     */
    private function products(User $user, ?int $companyId, ?int $areaId): Collection
    {
        return AiProduct::query()
            ->visibleTo($user)
            ->with(['companies', 'areas'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (AiProduct $product) => ($companyId === null || $product->companies->contains('id', $companyId))
                && ($areaId === null || $product->areas->contains('id', $areaId)))
            ->values();
    }

    /**
     * @param  array{period: string, company_id: ?int, area_id: ?int}  $filters
     */
    private function scopeLabel(array $filters): string
    {
        $parts = [ValueDashboard::PERIODS[$filters['period']]];
        $parts[] = $filters['company_id'] === null ? 'Todas las empresas' : (string) Company::query()->whereKey($filters['company_id'])->value('name');

        if ($filters['area_id'] !== null) {
            $parts[] = (string) Area::query()->whereKey($filters['area_id'])->value('name');
        }

        return implode(' · ', $parts);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
