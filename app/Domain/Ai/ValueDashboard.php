<?php

namespace App\Domain\Ai;

use App\Enums\Ai\ProductStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiHoursSavedReport;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiRating;
use App\Models\AiRequest;
use App\Models\AiRequestStatusChange;
use App\Models\Area;
use App\Support\Ai\Presenters\RequestPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Value dashboard (PROMPT 6.4.5 + 6.4.11, design `ValueMetrics`).
 *
 * Scope: requests of the company context (CompanyScope), narrowed by the
 * `company_id` filter (only in "Todas") and the `area_id` filter (the
 * request's area). Products: AiProduct::visibleTo, same filters through
 * their company/area pivots.
 *
 * - Period: the last 3, 6 or 12 complete months, or this year (January to
 *   last month; January alone in January). Bogotá calendar.
 * - Hours saved per month (real, from `ai_hours_saved_reports`): each
 *   answer (hours/week × 52/12) counts from the month its request first
 *   reached "En producción" (else the month it was reported). `current` is
 *   the last month of the period, `delta` against the month before.
 * - Satisfaction: "resolvió mi necesidad" of the ratings given in the
 *   period; `apps` = distinct products (a request without product counts
 *   as its own app).
 * - Lead time: days from the request to its first move to production, for
 *   requests delivered in the period; `delta` against the previous period
 *   of the same length (0 when that one has no deliveries).
 * - Monthly cost: Σ `monthly_cost` of products in production or pilot.
 * - ROI per product: development cost = real cost (ProjectCosts::total) of
 *   its projects (by `product_id` or through its requests); saving per
 *   month = hours × hour value of each request's area, minus the product's
 *   monthly cost; payback = development cost / saving. Only products with
 *   a positive saving; shortest payback first.
 * - Requests by status: every request of the scope today (not the period).
 *
 * Money (`monthly_cost`, `roi`, `hour_value`) is computed only when the
 * caller passes `$withCosts`; otherwise the keys are null.
 */
final class ValueDashboard
{
    public const PERIODS = ['3m' => 'Últimos 3 meses', '6m' => 'Últimos 6 meses', '12m' => 'Últimos 12 meses', 'year' => 'Este año'];

    public const DEFAULT_PERIOD = '6m';

    public const TIMEZONE = 'America/Bogota';

    private const MONTH_NAMES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** Product statuses whose monthly cost is being paid and whose savings count. */
    private const LIVE_PRODUCTS = [ProductStatus::Production, ProductStatus::Pilot];

    public function __construct(private readonly ProjectCosts $costs) {}

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string, months: list<string>}
     */
    public static function period(string $period, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE);
        $lastMonth = $today->startOfMonth()->subMonth();

        [$from, $to] = match ($period) {
            '3m' => [$lastMonth->subMonths(2), $lastMonth],
            '12m' => [$lastMonth->subMonths(11), $lastMonth],
            'year' => $today->month === 1 ? [$today->startOfMonth(), $today->startOfMonth()] : [$today->startOfYear(), $lastMonth],
            default => [$lastMonth->subMonths(5), $lastMonth],
        };

        $months = [];
        for ($month = $from; $month->lte($to); $month = $month->addMonth()) {
            $months[] = $month->format('Y-m');
        }

        return [
            'from' => $from->startOfMonth(),
            'to' => $to->endOfMonth(),
            'label' => self::PERIODS[$period] ?? self::PERIODS[self::DEFAULT_PERIOD],
            'months' => $months,
        ];
    }

    /**
     * Requests of the scope (CompanyScope + filters).
     *
     * @return Builder<AiRequest>
     */
    public function requests(?int $companyId, ?int $areaId): Builder
    {
        return AiRequest::query()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($areaId !== null, fn (Builder $query) => $query->where('area_id', $areaId));
    }

    /**
     * Everything the page needs (raw; the controller shapes and hides money).
     *
     * @param  Collection<int, AiProduct>  $products  products of the scope
     * @return array{metrics: ?array<string, mixed>, products: list<array<string, mixed>>, area_notes: list<string>,
     *     roi_note: ?string, cost_note: ?string, awaiting: ?AiRequest}
     */
    public function build(string $period, ?int $companyId, ?int $areaId, Collection $products, bool $withCosts): array
    {
        $bounds = self::period($period);
        $requests = $this->requests($companyId, $areaId)->with('area')->get();
        $ids = $requests->pluck('id')->all();

        $production = $this->productionDates($ids);
        $reports = AiHoursSavedReport::query()->whereIn('ai_request_id', $ids)->get();
        $byRequest = $requests->keyBy('id');

        $since = function (AiHoursSavedReport $report) use ($production): CarbonImmutable {
            return $production[$report->ai_request_id] ?? CarbonImmutable::parse($report->reported_at)->timezone(self::TIMEZONE);
        };
        $monthly = fn (AiHoursSavedReport $report): float => (float) $report->hours_per_week * Money::WEEKS_PER_MONTH;
        $activeBy = fn (CarbonImmutable $end) => $reports->filter(fn (AiHoursSavedReport $report) => $since($report)->lte($end));

        $series = [];
        foreach ($bounds['months'] as $month) {
            $end = CarbonImmutable::parse($month.'-01', self::TIMEZONE)->endOfMonth();
            $series[] = ['month' => $month, 'value' => $this->round($activeBy($end)->sum($monthly))];
        }

        $current = $activeBy($bounds['to']);
        $previousEnd = $bounds['to']->startOfMonth()->subMonth()->endOfMonth();
        $currentHours = (float) $current->sum($monthly);
        $previousHours = (float) $activeBy($previousEnd)->sum($monthly);

        $ratings = AiRating::query()
            ->whereIn('ai_request_id', $ids)
            ->whereBetween('rated_at', [$bounds['from'], $bounds['to']])
            ->get();

        $leadNow = $this->leadTime($byRequest, $production, $bounds['from'], $bounds['to']);
        $length = count($bounds['months']);
        $leadBefore = $this->leadTime($byRequest, $production, $bounds['from']->subMonths($length), $bounds['from']->subSecond());

        // Per-product savings (money with the area's hour value).
        $productRows = $this->productRows($products, $current, $byRequest, $monthly);

        $metrics = null;
        if ($currentHours > 0 || $ratings->isNotEmpty() || collect($series)->sum('value') > 0) {
            $grossTotal = (float) $current->sum(fn (AiHoursSavedReport $report) => $monthly($report) * (int) ($byRequest[$report->ai_request_id]?->area?->hour_value ?? 0));

            $metrics = [
                'period' => ['from' => $bounds['from']->toDateString(), 'to' => $bounds['to']->toDateString(), 'label' => $bounds['label']],
                'updated_at' => now()->toIso8601String(),
                'hours_saved_per_month' => [
                    'current' => $this->round($currentHours),
                    'delta' => $this->round($currentHours - $previousHours),
                    'delta_label' => 'frente a '.self::MONTH_NAMES[$previousEnd->month - 1],
                ],
                'satisfaction' => [
                    'average' => $ratings->isEmpty() ? 0 : RequestPresenter::number(round((float) $ratings->avg('solved_need'), 1)),
                    'ratings' => $ratings->count(),
                    'apps' => $ratings->map(fn (AiRating $rating) => ($byRequest[$rating->ai_request_id]?->product_id !== null)
                        ? 'p'.$byRequest[$rating->ai_request_id]->product_id
                        : 'r'.$rating->ai_request_id)->unique()->count(),
                ],
                'lead_time_days' => [
                    'current' => $leadNow ?? 0,
                    'delta' => $leadNow === null || $leadBefore === null ? 0 : $leadNow - $leadBefore,
                ],
                'monthly_cost' => $withCosts ? $this->monthlyCost($products) : null,
                'hours_series' => $series,
                'hours_by_area' => $this->hoursByArea($current, $byRequest, $monthly),
                'requests_by_status' => $this->byStatus($requests),
                'roi' => $withCosts ? $this->roi($productRows) : null,
                'hour_value' => $withCosts ? $this->hourValue($areaId, $currentHours, $grossTotal) : null,
            ];
        }

        return [
            'metrics' => $metrics,
            'products' => $this->valueProducts($productRows, $withCosts),
            'area_notes' => $metrics === null ? [] : $this->areaNotes($current, $byRequest, $monthly, $productRows),
            'roi_note' => $withCosts && $metrics !== null ? $this->roiNote($products, $productRows) : null,
            'cost_note' => $withCosts && $metrics !== null ? $this->costNote($products) : null,
            'awaiting' => $metrics === null ? $this->awaitingRating($requests, $production) : null,
        ];
    }

    /**
     * First move to "En producción" of each request (Bogotá).
     *
     * @param  list<int>  $ids
     * @return array<int, CarbonImmutable>
     */
    private function productionDates(array $ids): array
    {
        return AiRequestStatusChange::query()
            ->whereIn('ai_request_id', $ids)
            ->where('to_status', RequestStatus::InProduction->value)
            ->orderBy('id')
            ->get(['ai_request_id', 'created_at'])
            ->groupBy('ai_request_id')
            ->map(fn (Collection $changes) => CarbonImmutable::parse($changes->first()->created_at)->timezone(self::TIMEZONE))
            ->all();
    }

    /**
     * Average whole days from request to production, for deliveries in the window.
     *
     * @param  Collection<int, AiRequest>  $requests
     * @param  array<int, CarbonImmutable>  $production
     */
    private function leadTime(Collection $requests, array $production, CarbonImmutable $from, CarbonImmutable $to): ?int
    {
        $days = [];

        foreach ($production as $requestId => $at) {
            $request = $requests[$requestId] ?? null;

            if ($request === null || $at->lt($from) || $at->gt($to)) {
                continue;
            }

            $days[] = CarbonImmutable::parse($request->created_at)->timezone(self::TIMEZONE)->startOfDay()->diffInDays($at->startOfDay());
        }

        return $days === [] ? null : (int) round(array_sum($days) / count($days));
    }

    /**
     * @param  Collection<int, AiProduct>  $products
     * @param  Collection<int, AiHoursSavedReport>  $current
     * @param  Collection<int, AiRequest>  $requests
     * @return list<array{product: AiProduct, hours: float, gross: float, development: int}>
     */
    private function productRows(Collection $products, Collection $current, Collection $requests, callable $monthly): array
    {
        $rows = [];

        foreach ($products as $product) {
            $reports = $current->filter(fn (AiHoursSavedReport $report) => ($requests[$report->ai_request_id]?->product_id) === $product->id);

            $rows[] = [
                'product' => $product,
                'hours' => (float) $reports->sum($monthly),
                'gross' => (float) $reports->sum(fn (AiHoursSavedReport $report) => $monthly($report) * (int) ($requests[$report->ai_request_id]?->area?->hour_value ?? 0)),
                'development' => $this->developmentCost($product),
            ];
        }

        return $rows;
    }

    /**
     * Real cost of the product's projects (by product or through its requests).
     */
    private function developmentCost(AiProduct $product): int
    {
        return AiProject::query()
            ->where(fn (Builder $query) => $query
                ->where('product_id', $product->id)
                ->orWhereHas('requests', fn (Builder $requests) => $requests->where('ai_requests.product_id', $product->id)))
            ->get()
            ->sum(fn (AiProject $project) => $this->costs->total($project));
    }

    /**
     * @param  Collection<int, AiProduct>  $products
     */
    private function monthlyCost(Collection $products): int
    {
        return (int) round($this->live($products)->sum(fn (AiProduct $product) => (float) $product->monthly_cost));
    }

    /**
     * @param  Collection<int, AiProduct>  $products
     * @return Collection<int, AiProduct>
     */
    private function live(Collection $products): Collection
    {
        return $products->filter(fn (AiProduct $product) => in_array($product->status, self::LIVE_PRODUCTS, true))->values();
    }

    /**
     * @param  list<array{product: AiProduct, hours: float, gross: float, development: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private function roi(array $rows): array
    {
        return collect($rows)
            ->filter(fn (array $row) => in_array($row['product']->status, self::LIVE_PRODUCTS, true))
            ->map(fn (array $row) => $this->roiRow($row))
            ->filter()
            ->sortBy([['payback_months', 'asc'], ['product', 'asc']])
            ->values()
            ->all();
    }

    /**
     * @param  array{product: AiProduct, hours: float, gross: float, development: int}  $row
     * @return array<string, mixed>|null
     */
    private function roiRow(array $row): ?array
    {
        $saving = (int) round($row['gross'] - (float) $row['product']->monthly_cost);

        if ($row['hours'] <= 0 || $saving <= 0) {
            return null;
        }

        return [
            'product' => $row['product']->name,
            'hours_per_month' => $this->round($row['hours']),
            'total_cost' => $row['development'],
            'saving_per_month' => $saving,
            'payback_months' => RequestPresenter::number(round($row['development'] / $saving, 1)),
        ];
    }

    private function hourValue(?int $areaId, float $hours, float $gross): ?int
    {
        if ($areaId !== null) {
            return Area::query()->whereKey($areaId)->value('hour_value');
        }

        return $hours > 0 && $gross > 0 ? (int) round($gross / $hours) : null;
    }

    /**
     * @param  Collection<int, AiHoursSavedReport>  $current
     * @param  Collection<int, AiRequest>  $requests
     * @return list<array{area: string, hours: int|float}>
     */
    private function hoursByArea(Collection $current, Collection $requests, callable $monthly): array
    {
        return $current
            ->groupBy(fn (AiHoursSavedReport $report) => $requests[$report->ai_request_id]?->area?->name ?? 'Sin área')
            ->map(fn (Collection $reports, string $area) => ['area' => $area, 'hours' => (float) $reports->sum($monthly)])
            ->sortBy([['hours', 'desc'], ['area', 'asc']])
            ->map(fn (array $row) => ['area' => $row['area'], 'hours' => $this->round($row['hours'])])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AiRequest>  $requests
     * @return list<array{status: string, count: int}>
     */
    private function byStatus(Collection $requests): array
    {
        $order = array_flip(array_map(fn (RequestStatus $status) => $status->value, RequestStatus::cases()));

        return $requests
            ->groupBy(fn (AiRequest $request) => $request->status->value)
            ->map(fn (Collection $group, string $status) => ['status' => $status, 'count' => $group->count()])
            ->sort(fn (array $a, array $b) => [$b['count'], $order[$a['status']]] <=> [$a['count'], $order[$b['status']]])
            ->values()
            ->all();
    }

    /**
     * Design `ValueProduct` rows: products with saved hours, most first.
     *
     * @param  list<array{product: AiProduct, hours: float, gross: float, development: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private function valueProducts(array $rows, bool $withCosts): array
    {
        return collect($rows)
            ->filter(fn (array $row) => $row['hours'] > 0)
            ->sortBy([['hours', 'desc'], [fn (array $row) => $row['product']->name, 'asc']])
            ->map(function (array $row) use ($withCosts): array {
                $roi = $withCosts ? $this->roiRow($row) : null;

                return [
                    'slug' => $row['product']->slug,
                    'name' => $row['product']->name,
                    'company_id' => $row['product']->primaryCompany()?->id,
                    'hours_per_month' => $this->round($row['hours']),
                    'payback_months' => $roi['payback_months'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "Ventas ahorra más: 26 h al mes, sobre todo con Visualizador de cartera."
     * and the real against the requesters' estimate.
     *
     * @param  Collection<int, AiHoursSavedReport>  $current
     * @param  Collection<int, AiRequest>  $requests
     * @param  list<array{product: AiProduct, hours: float, gross: float, development: int}>  $productRows
     * @return list<string>
     */
    private function areaNotes(Collection $current, Collection $requests, callable $monthly, array $productRows): array
    {
        $notes = [];
        $areas = $this->hoursByArea($current, $requests, $monthly);

        if ($areas !== []) {
            $top = $areas[0];
            $byProduct = $current
                ->filter(fn (AiHoursSavedReport $report) => ($requests[$report->ai_request_id]?->area?->name ?? 'Sin área') === $top['area']
                    && $requests[$report->ai_request_id]?->product_id !== null)
                ->groupBy(fn (AiHoursSavedReport $report) => $requests[$report->ai_request_id]->product_id)
                ->map(fn (Collection $reports) => (float) $reports->sum($monthly))
                ->sortDesc();
            $productName = $byProduct->isEmpty() ? null
                : collect($productRows)->first(fn (array $row) => $row['product']->id === $byProduct->keys()->first())['product']->name ?? null;

            $notes[] = "{$top['area']} ahorra más: ".self::hours($top['hours']).' al mes'
                .($productName === null ? '.' : ", sobre todo con {$productName}.");
        }

        $estimated = 0.0;
        $reported = 0.0;
        foreach ($current as $report) {
            $estimate = Money::hoursPerMonth($requests[$report->ai_request_id]);

            if ($estimate !== null && $estimate > 0) {
                $estimated += $estimate;
                $reported += $monthly($report);
            }
        }

        if ($estimated > 0) {
            $notes[] = 'Lo reportado por quienes las usan ('.self::hours($reported).' al mes) frente a lo estimado al pedir ('.self::hours($estimated).' al mes).';
        }

        return $notes;
    }

    /**
     * Products that already cost money but are not in the ROI yet.
     *
     * @param  Collection<int, AiProduct>  $products
     * @param  list<array{product: AiProduct, hours: float, gross: float, development: int}>  $rows
     */
    private function roiNote(Collection $products, array $rows): ?string
    {
        $notes = [];

        foreach ($rows as $row) {
            $product = $row['product'];

            if (! in_array($product->status, self::LIVE_PRODUCTS, true)) {
                if ($row['development'] > 0) {
                    $notes[] = "{$product->name} no aparece: aún no está en producción.";
                }
            } elseif ($this->roiRow($row) === null && $row['development'] > 0) {
                $notes[] = "{$product->name} no aparece: aún no reporta ahorro suficiente.";
            }
        }

        return $notes === [] ? null : implode(' ', $notes);
    }

    /**
     * @param  Collection<int, AiProduct>  $products
     */
    private function costNote(Collection $products): ?string
    {
        $count = $this->live($products)->filter(fn (AiProduct $product) => (float) $product->monthly_cost > 0)->count();

        return $count === 0 ? null : 'Hosting, bases de datos y dominios de '.$count.($count === 1 ? ' producto' : ' productos');
    }

    /**
     * Latest delivery in production still waiting for its requester's rating.
     *
     * @param  Collection<int, AiRequest>  $requests
     * @param  array<int, CarbonImmutable>  $production
     */
    private function awaitingRating(Collection $requests, array $production): ?AiRequest
    {
        return $requests
            ->filter(fn (AiRequest $request) => $request->status === RequestStatus::InProduction
                && ! $request->ratings()->where('user_id', $request->requester_id)->exists())
            ->sortByDesc(fn (AiRequest $request) => ($production[$request->id] ?? CarbonImmutable::parse($request->updated_at))->getTimestamp())
            ->first();
    }

    /**
     * "26 h", "34,7 h".
     */
    public static function hours(float $hours): string
    {
        return str_replace('.', ',', (string) RequestPresenter::number(round($hours, 1))).' h';
    }

    private function round(float $value): int|float
    {
        return RequestPresenter::number(round($value, 1)) ?? 0;
    }
}
