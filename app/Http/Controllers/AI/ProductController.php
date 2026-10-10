<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\IncidentForm;
use App\Enums\Ai\ProductStatus;
use App\Enums\Ai\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AiDocument;
use App\Models\AiHoursSavedReport;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\User;
use App\Support\Ai\Presenters\DocumentPresenter;
use App\Support\Ai\Presenters\ProductPresenter;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\Presenters\TriagePresenter;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Products (PROMPT 6.4.2, types/pages/ai-knowledge.ts), `ai.manage`:
 *
 * - `Ai/Products/Index`: products of the company context
 *   (AiProduct::visibleTo) with filters `q` (name, stack, owner),
 *   `company_id` (only in "Todas"), `area_id` and `state`; `state_counts`
 *   keep the other filters.
 * - `Ai/Products/Show`: the product sheet with versions, open requests of
 *   the context, docs, incidents and the explanatory notes. Health is the
 *   Stage B placeholder `{ enabled: false }`. `incident_form` sets up the
 *   "Registrar incidente" drawer (ProductIncidentController stores it).
 *
 * Money is null without `ai.costs.view`.
 */
class ProductController extends Controller
{
    /** Open requests on the sheet ("Ver todas" opens the inbox). */
    private const REQUESTS_LIMIT = 10;

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(
        private readonly CurrentCompany $companies,
        private readonly IncidentForm $incidentForm,
    ) {}

    public function index(Request $http): Response
    {
        $user = $this->user($http);

        $q = trim((string) $http->query('q', ''));
        $q = $q === '' ? null : mb_substr($q, 0, 100);
        $companyId = $this->companies->isAll() && is_numeric($http->query('company_id')) ? (int) $http->query('company_id') : null;
        $areaId = is_numeric($http->query('area_id')) ? (int) $http->query('area_id') : null;
        $state = ProductStatus::tryFrom((string) $http->query('state'));

        $filtered = AiProduct::query()
            ->visibleTo($user)
            ->with([...ProductPresenter::RELATIONS])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (AiProduct $product) => $this->matches($product, $q, $companyId, $areaId))
            ->values();

        $counts = array_fill_keys(array_map(fn (ProductStatus $status) => $status->value, [
            ProductStatus::Production, ProductStatus::Pilot, ProductStatus::Development, ProductStatus::Retired,
        ]), 0);
        foreach ($filtered as $product) {
            $counts[$product->status->value]++;
        }

        $products = $state === null ? $filtered : $filtered->filter(fn (AiProduct $product) => $product->status === $state)->values();

        return Inertia::render('Ai/Products/Index', [
            'products' => $products->map(fn (AiProduct $product) => ProductPresenter::product($product, $user))->all(),
            'filters' => ['q' => $q, 'company_id' => $companyId, 'area_id' => $areaId, 'state' => $state?->value],
            'state_counts' => $counts,
            'areas' => Area::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get()
                ->map(fn (Area $area) => TriagePresenter::areaOption($area))->values()->all(),
        ]);
    }

    public function show(Request $http, AiProduct $product): Response
    {
        return $this->sheet($http, $product, false);
    }

    /**
     * The product sheet; `$incidentOpen` starts the "Registrar incidente"
     * drawer open (deep link `ai.products.incidents.create`, failed store).
     */
    public function sheet(Request $http, AiProduct $product, bool $incidentOpen): Response
    {
        $user = $this->user($http);
        Gate::authorize('view', $product);
        $product->loadMissing(ProductPresenter::RELATIONS);

        $requests = $this->openRequests($product, $user);
        $docs = $product->documents()
            ->with(DocumentPresenter::RELATIONS)
            ->get();

        return Inertia::render('Ai/Products/Show', [
            'product' => ProductPresenter::detail(
                $product,
                $user,
                $requests->all(),
                DocumentPresenter::sort($docs)->map(fn (AiDocument $document) => DocumentPresenter::summary($document))->all(),
            ),
            'health' => ['enabled' => false],
            'incidents' => $product->incidents()->with('reportedBy.employee')->get()
                ->map(fn ($incident) => [
                    'id' => $incident->id,
                    'title' => $incident->title,
                    'started_at' => $incident->started_at->toIso8601String(),
                    'resolved_at' => $incident->resolved_at?->toIso8601String(),
                    'reported_by' => RequestPresenter::person($incident->reportedBy),
                ])
                ->values()
                ->all(),
            'hours_note' => $this->hoursNote($product),
            'cost_note' => $this->costNote($product, $user),
            'request_notes' => (object) $requests->mapWithKeys(fn (AiRequest $request) => [$request->key => $this->requestNote($request)])->all(),
            'incident_form' => $this->incidentForm->setup($product, $user, $incidentOpen, (array) $http->old()),
            'now' => now()->toIso8601String(),
        ]);
    }

    private function matches(AiProduct $product, ?string $q, ?int $companyId, ?int $areaId): bool
    {
        if ($companyId !== null && ! $product->companies->contains('id', $companyId)) {
            return false;
        }

        if ($areaId !== null && ! $product->areas->contains('id', $areaId)) {
            return false;
        }

        if ($q === null) {
            return true;
        }

        $haystack = Str::lower(Str::ascii(implode(' ', [$product->name, $product->stack, $product->ownerLabel(), $product->slug])));

        return str_contains($haystack, Str::lower(Str::ascii($q)));
    }

    /**
     * Open requests of the product in the company context, newest first.
     *
     * @return Collection<int, AiRequest>
     */
    private function openRequests(AiProduct $product, User $user): Collection
    {
        $companyId = $this->companies->forUser($user);

        return $product->requests()
            ->whereNotIn('status', [RequestStatus::InProduction->value, RequestStatus::Rejected->value, RequestStatus::Closed->value])
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->with(RequestPresenter::RELATIONS)
            ->latest('id')
            ->limit(self::REQUESTS_LIMIT)
            ->get()
            ->values();
    }

    /**
     * "4 h a la semana, según 2 personas que la usan" from the 30-day answers.
     */
    private function hoursNote(AiProduct $product): ?string
    {
        $reports = AiHoursSavedReport::query()->whereIn('ai_request_id', $product->requests()->select('id'))->get(['user_id', 'hours_per_week']);

        if ($reports->isEmpty()) {
            return null;
        }

        $hours = RequestPresenter::number(round((float) $reports->sum('hours_per_week'), 1));
        $people = $reports->pluck('user_id')->unique()->count();

        return "{$hours} h a la semana, según ".($people === 1 ? '1 persona que la usa' : "{$people} personas que la usan");
    }

    /**
     * What the monthly cost covers; null without `ai.costs.view` or cost.
     */
    private function costNote(AiProduct $product, User $user): ?string
    {
        if (ProductPresenter::monthlyCost($product, $user) === null) {
            return null;
        }

        $parts = array_values(array_filter([
            $product->hosting !== null && $product->hosting !== '' ? "hosting en {$product->hosting}" : null,
            $product->ai_provider !== null && $product->ai_provider !== '' ? "consumo de IA ({$product->ai_provider})" : null,
        ]));

        return $parts === [] ? null : Str::ucfirst(implode(' y ', $parts));
    }

    /**
     * Second line of an open request: its project and projected delivery,
     * else who asked and where it stands.
     */
    private function requestNote(AiRequest $request): string
    {
        /** @var AiProject|null $project */
        $project = $request->projects->first();

        if ($project !== null) {
            return $project->due_date !== null
                ? "Proyecto {$project->key} · entrega proyectada ".$this->shortDate($project->due_date)
                : "Proyecto {$project->key}";
        }

        return "{$request->requester->name} · {$request->status->label()}";
    }

    private function shortDate(CarbonInterface $date): string
    {
        return $date->day.' '.self::MONTHS[$date->month - 1];
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
