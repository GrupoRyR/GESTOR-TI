<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\AreaBoard;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Por área" (`Ai/Areas/Index`, AreasIndexProps, PROMPT 6.4.9): query
 * `{ company_id, area_id? }`. The company switch offers the active
 * companies the viewer reaches (every one with `companies.view_all`, else
 * their own); an unreachable `company_id` falls back to the company in
 * view, the viewer's company or the first reachable one. Counters and
 * semáforo rules live in App\Domain\Ai\AreaBoard; `ai.manage` is checked by
 * the route.
 */
class AreaBoardController extends Controller
{
    public function __construct(
        private readonly AreaBoard $board,
        private readonly CurrentCompany $companies,
    ) {}

    public function index(Request $http): Response
    {
        /** @var User $user */
        $user = $http->user();
        $reachable = $this->reachable($user);
        $companyId = $this->companyId($http, $user, $reachable);
        $areaId = $http->query('area_id');

        $area = $companyId !== null && is_string($areaId) && ctype_digit($areaId)
            ? Area::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->find((int) $areaId)
            : null;

        return Inertia::render('Ai/Areas/Index', [
            'company_id' => (int) $companyId,
            'area_companies' => $reachable->map(fn (Company $company) => ['id' => $company->id, 'short_name' => $company->short_name])->values()->all(),
            'areas' => $companyId === null ? [] : $this->board->summaries($companyId),
            'selected_area' => $area === null ? null : $this->board->detail($area),
        ]);
    }

    /**
     * @return Collection<int, Company>
     */
    private function reachable(User $user): Collection
    {
        return Company::query()
            ->active()
            ->when(! $this->companies->canViewAll($user), fn ($query) => $query->whereKey((int) $user->company_id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Company>  $reachable
     */
    private function companyId(Request $http, User $user, Collection $reachable): ?int
    {
        $ids = $reachable->modelKeys();
        $candidates = [$http->query('company_id'), $this->companies->id(), $user->company_id];

        foreach ($candidates as $candidate) {
            if ((is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) && in_array((int) $candidate, $ids, true)) {
                return (int) $candidate;
            }
        }

        return $ids[0] ?? null;
    }
}
