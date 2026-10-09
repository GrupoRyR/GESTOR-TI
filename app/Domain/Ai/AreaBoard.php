<?php

namespace App\Domain\Ai;

use App\Enums\Ai\ProductStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiProduct;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\Scopes\CompanyScope;
use App\Support\Ai\Presenters\RequestPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Por área" (PROMPT 6.4.9, AreasIndexProps): empresa → área with counters
 * and a semáforo per area.
 *
 * Counters (`AreaSummary`):
 * - products_in_use: products of the area in production or pilot;
 * - projects_in_progress: 0 until projects exist (B5);
 * - backlog: requests of the area "En backlog";
 * - hours_saved_per_month: requests of the area in production or closed,
 *   requester's hours/week × people × 52/12 (one decimal), null when none
 *   (the page legend: "salen de las solicitudes ya en producción").
 *
 * Semáforo (`semaphore` + `reason`, first match wins):
 * - late: a request of the area without first response past its deadline
 *   (oldest deadline first): "GIA-148 sin primera respuesta, vencida hace 2
 *   días". (B5 adds overdue requirements and late projects.)
 * - attention: a request waiting — without first response yet ("…, vence
 *   hoy"), on the requester ("GIA-150 espera la respuesta de Andrés") or on
 *   approval ("GIA-144 espera aprobación").
 * - ok: reason = the first backlog request ("GIA-110 Cierre de mes, en
 *   backlog") or null.
 *
 * Owner: the area approver's name, else "[RESPONSABLE]" (design
 * placeholder). Areas of the company in name order, inactive ones left out.
 */
final class AreaBoard
{
    public const OWNER_PLACEHOLDER = '[RESPONSABLE]';

    private const REASON_TITLE_MAX = 40;

    public function __construct(private readonly Roadmap $roadmap) {}

    /**
     * @return Collection<int, Area>
     */
    public function areas(int $companyId): Collection
    {
        return Area::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with('approver')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * `AreaSummary` of every area of the company.
     *
     * @return list<array<string, mixed>>
     */
    public function summaries(int $companyId): array
    {
        $areas = $this->areas($companyId);
        $requests = $this->requests($areas->modelKeys())->groupBy('area_id');

        return $areas->map(fn (Area $area) => $this->summary($area, $requests->get($area->id, collect())))->values()->all();
    }

    /**
     * `AreaDetail` of one area.
     *
     * @return array<string, mixed>
     */
    public function detail(Area $area): array
    {
        $requests = $this->requests([$area->id]);
        $backlog = Roadmap::order(AiRequest::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('area_id', $area->id)
            ->where('status', RequestStatus::Backlog->value)
            ->with(RequestPresenter::RELATIONS))->get();
        $ranks = $this->roadmap->ranks($backlog);

        return [
            'summary' => $this->summary($area, $requests),
            'products' => $this->products($area)->map(fn (AiProduct $product) => [
                'slug' => $product->slug,
                'name' => $product->name,
                'version' => (string) ($product->current_version ?? ''),
                'stack' => (string) ($product->stack ?? ''),
                'host' => (string) ($product->hosting ?? ''),
                'owner' => $product->ownerLabel() ?? '[DUEÑO]',
                'state' => $product->status->value,
                'monitoring_active' => $product->health()['monitoring_active'],
            ])->values()->all(),
            // TODO B5: projects of the area (`AreaProject`).
            'projects' => [],
            'backlog' => $backlog->map(fn (AiRequest $request) => RequestPresenter::summary($request, $ranks[$request->id] ?? null))->values()->all(),
            'inbox_keys' => $requests
                ->filter(fn (AiRequest $request) => in_array($request->status, Inbox::STATUSES, true))
                ->sortBy('id')
                ->pluck('key')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, AiRequest>  $requests  requests of the area
     * @return array<string, mixed>
     */
    private function summary(Area $area, Collection $requests): array
    {
        [$semaphore, $reason] = $this->semaphore($requests);

        return [
            'area' => ['id' => $area->id, 'name' => $area->name, 'company_id' => (int) $area->company_id],
            'owner' => $area->approver?->name ?? self::OWNER_PLACEHOLDER,
            'semaphore' => $semaphore,
            'reason' => $reason,
            'products_in_use' => $this->products($area)->count(),
            // TODO B5: projects in development of the area.
            'projects_in_progress' => 0,
            'backlog' => $requests->where('status', RequestStatus::Backlog)->count(),
            'hours_saved_per_month' => $this->hoursSaved($requests),
        ];
    }

    /**
     * @param  Collection<int, AiRequest>  $requests
     * @return array{0: 'ok'|'attention'|'late', 1: ?string}
     */
    private function semaphore(Collection $requests): array
    {
        $unanswered = $requests
            ->filter(fn (AiRequest $request) => in_array($request->status, Inbox::STATUSES, true)
                && $request->first_responded_at === null
                && $request->first_response_due_at !== null)
            ->sortBy(fn (AiRequest $request) => $request->first_response_due_at->getTimestamp());

        $late = $unanswered->first(fn (AiRequest $request) => $request->first_response_due_at->isPast());

        if ($late !== null) {
            return ['late', "{$late->key} sin primera respuesta, ".DueLabel::for($late->first_response_due_at)['label']];
        }

        $waiting = $unanswered->first();

        if ($waiting !== null) {
            return ['attention', "{$waiting->key} sin primera respuesta, ".DueLabel::for($waiting->first_response_due_at)['label']];
        }

        $question = $requests->where('status', RequestStatus::NeedsInfo)->sortBy('id')->first();

        if ($question !== null) {
            return ['attention', "{$question->key} espera la respuesta de ".Str::before(trim((string) $question->requester?->name), ' ')];
        }

        $approval = $requests->where('status', RequestStatus::PendingApproval)->sortBy('id')->first();

        if ($approval !== null) {
            return ['attention', "{$approval->key} espera aprobación"];
        }

        $queued = Roadmap::order(AiRequest::query()->withoutGlobalScope(CompanyScope::class)->whereKey($requests->where('status', RequestStatus::Backlog)->pluck('id')->all()))->first();

        return ['ok', $queued === null ? null : "{$queued->key} ".Str::limit($queued->title, self::REASON_TITLE_MAX).', en backlog'];
    }

    /**
     * @param  Collection<int, AiRequest>  $requests
     */
    private function hoursSaved(Collection $requests): int|float|null
    {
        $delivered = $requests->filter(fn (AiRequest $request) => in_array($request->status, [RequestStatus::InProduction, RequestStatus::Closed], true)
            && $request->teamHours() !== null);

        if ($delivered->isEmpty()) {
            return null;
        }

        $monthly = $delivered->sum(fn (AiRequest $request) => (float) Money::hoursPerMonth($request));

        return RequestPresenter::number(round($monthly, 1));
    }

    /**
     * @return Collection<int, AiProduct>
     */
    private function products(Area $area): Collection
    {
        return AiProduct::query()
            ->whereHas('areas', fn ($query) => $query->whereKey($area->id))
            ->whereIn('status', [ProductStatus::Production->value, ProductStatus::Pilot->value])
            ->with(['owner', 'monitoredApp'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Requests of the areas (any company context: the caller checked access).
     *
     * @param  list<int>  $areaIds
     * @return Collection<int, AiRequest>
     */
    private function requests(array $areaIds): Collection
    {
        if ($areaIds === []) {
            return collect();
        }

        return AiRequest::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('area_id', $areaIds)
            ->where('status', '!=', RequestStatus::Rejected->value)
            ->with('requester')
            ->get();
    }
}
