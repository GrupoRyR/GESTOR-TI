<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\Roadmap;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\AiRequestVote;
use App\Models\Company;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\RequesterCopy;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Hoja de ruta" (`Ai/Roadmap/Index`, RoadmapIndexProps), for requesters
 * and IT alike. Items are the requests of the current company context
 * (CompanyScope: the person's company; "Todas" for `companies.view_all`)
 * in the roadmap statuses of App\Domain\Ai\Roadmap; cards never carry
 * costs or estimates. Filters: `area_id`, `company_id`, `mine=1`.
 */
class RoadmapController extends Controller
{
    /** Cards shown in "Más adelante" (`later_total` counts them all). */
    public const LATER_SHOWN = 20;

    public function __construct(
        private readonly Roadmap $roadmap,
        private readonly Settings $settings,
    ) {}

    public function __invoke(Request $http): Response
    {
        /** @var User $user */
        $user = $http->user();
        abort_unless($user->can('ai.requests.create') || $user->can('ai.manage'), 403);

        $filters = [
            'area_id' => $http->filled('area_id') ? (int) $http->query('area_id') : null,
            'company_id' => $http->filled('company_id') ? (int) $http->query('company_id') : null,
            'mine' => $http->boolean('mine'),
        ];

        // Everything of the context on the roadmap (for the area and company filters).
        $all = Roadmap::order(AiRequest::query()->whereIn('status', Roadmap::statusValues()))
            ->with([...RequestPresenter::RELATIONS, 'area.company'])
            ->get();

        $items = $all
            ->when($filters['area_id'] !== null, fn (Collection $items) => $items->where('area_id', $filters['area_id']))
            ->when($filters['company_id'] !== null, fn (Collection $items) => $items->where('company_id', $filters['company_id']))
            ->when($filters['mine'], fn (Collection $items) => $items->where('requester_id', $user->id));

        $ranks = $this->roadmap->ranks($items);
        $voted = array_flip(
            AiRequestVote::query()->where('user_id', $user->id)->whereIn('ai_request_id', $items->modelKeys())->pluck('ai_request_id')->all()
        );

        $columns = ['now' => [], 'next' => [], 'later' => []];

        foreach ($items as $request) {
            $columns[Roadmap::column($request->status)][] = $this->item($request, $user, $ranks[$request->id] ?? null, isset($voted[$request->id]));
        }

        $companies = $all->pluck('company_id')->unique()->values();

        return Inertia::render('Ai/Roadmap/Index', [
            'columns' => [...$columns, 'later' => array_slice($columns['later'], 0, self::LATER_SHOWN)],
            'filters' => $filters,
            'areas' => $this->areas($all, $companies->count() > 1),
            'roadmap_companies' => Company::query()
                ->whereKey($companies->all())
                ->orderBy('name')
                ->get()
                ->map(fn (Company $company) => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'short_name' => $company->short_name,
                    'colors' => $company->toSharedArray()['colors'],
                ])
                ->values()
                ->all(),
            'later_total' => count($columns['later']),
            'wip_limit' => (int) $this->settings->get('ai.wip_limit', 3),
            'mine_count' => $all->where('requester_id', $user->id)->count(),
            'updated_at' => ($all->max('updated_at') ?? now())->toIso8601String(),
        ]);
    }

    /**
     * `RoadmapItem`.
     *
     * @return array<string, mixed>
     */
    private function item(AiRequest $request, User $user, ?int $rank, bool $voted): array
    {
        return [
            'key' => $request->key,
            'title' => $request->title,
            'area' => RequestPresenter::area($request->area),
            'company_id' => (int) $request->company_id,
            'status' => $request->status->value,
            'votes' => $request->votes_count,
            'mine' => (int) $request->requester_id === $user->id,
            'voted' => $voted,
            'note' => RequesterCopy::roadmapNote($request, $rank),
            // Start date of its project (B5; no sprints in Stage B).
            'planned_start' => $request->projects()->first()?->start_date?->toDateString(),
        ];
    }

    /**
     * Areas that have roadmap items, by name; with several companies the
     * name carries the company ("Ventas · Segurex").
     *
     * @param  Collection<int, AiRequest>  $all
     * @return list<array{id: int, name: string}>
     */
    private function areas(Collection $all, bool $withCompany): array
    {
        return $all->pluck('area')
            ->filter()
            ->unique('id')
            ->map(fn ($area) => [
                'id' => (int) $area->id,
                'name' => $withCompany && $area->company !== null ? $area->name.' · '.$area->company->short_name : $area->name,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }
}
