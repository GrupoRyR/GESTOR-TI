<?php

namespace App\Support\Ai\Presenters;

use App\Domain\Ai\CostRates;
use App\Domain\Ai\TriagePeople;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\User;

/**
 * Shared pieces of the IT triage pages (types/pages/ai-triage.ts):
 * `TriageActionData` (team with rates, approvers), `AreaOption` and the
 * estimate shapes. Costs and rates only for `ai.costs.view`; without it the
 * fields are null (never just hidden).
 */
final class TriagePresenter
{
    public function __construct(
        private readonly TriagePeople $people,
        private readonly CostRates $rates,
    ) {}

    /**
     * `TriageActionData` without `merge_candidates` (partial reload only).
     *
     * @return array{team: list<array<string, mixed>>, approvers: list<array<string, mixed>>}
     */
    public function actionData(User $viewer): array
    {
        $team = $this->people->team();
        $rates = $viewer->can('ai.costs.view') ? $this->rates->ratesFor($team->modelKeys()) : [];

        return [
            'team' => $team->map(fn (User $user) => [
                'person' => RequestPresenter::person($user),
                'hourly_rate' => $rates[$user->id] ?? null,
            ])->values()->all(),
            'approvers' => $this->people->approvers()->map(fn (User $user) => RequestPresenter::person($user))->values()->all(),
        ];
    }

    /**
     * @return array{id: int, name: string, company_id: int}
     */
    public static function areaOption(Area $area): array
    {
        return ['id' => $area->id, 'name' => $area->name, 'company_id' => (int) $area->company_id];
    }

    /**
     * `similar_to` of a summary.
     *
     * @return array{key: string, title: string, votes: int}
     */
    public static function similar(AiRequest $request): array
    {
        return ['key' => $request->key, 'title' => $request->title, 'votes' => (int) $request->votes_count];
    }

    /**
     * Design `Estimate` from an approval snapshot (see SendToApproval).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function estimateFromSnapshot(array $snapshot, bool $withCosts): array
    {
        $rows = array_values((array) ($snapshot['hours_by_person'] ?? []));
        $users = User::query()->with('employee')->findMany(array_filter(array_map(fn (array $row) => $row['user_id'] ?? null, $rows)))->keyBy('id');

        return [
            'hours_by_person' => array_values(array_map(fn (array $row) => [
                'person' => isset($row['user_id'], $users[$row['user_id']])
                    ? RequestPresenter::person($users[$row['user_id']])
                    : ['id' => (int) ($row['user_id'] ?? 0), 'name' => (string) ($row['name'] ?? $row['role'] ?? ''), 'job_title' => null, 'avatar_url' => null],
                'hours' => RequestPresenter::number($row['hours'] ?? 0) ?? 0,
            ], $rows)),
            'hours_total' => RequestPresenter::number($snapshot['hours_total'] ?? 0) ?? 0,
            'external_costs' => $withCosts ? (int) ($snapshot['external_costs'] ?? 0) : null,
            'due_date' => (string) ($snapshot['due_date'] ?? ''),
            'total_cost' => $withCosts && isset($snapshot['total_cost']) ? (int) $snapshot['total_cost'] : null,
        ];
    }
}
