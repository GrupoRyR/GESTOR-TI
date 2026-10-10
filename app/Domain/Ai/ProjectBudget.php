<?php

namespace App\Domain\Ai;

use App\Models\AiEstimate;
use App\Models\AiProject;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Settings;

/**
 * Estimated vs real of a project (PROMPT 6.4.11, design `ProjectBudget`):
 *
 * - Estimate: the latest `ai_estimates` row linked to the project (the one
 *   the approvers saw, or the one of an approved re-approval); hours 0 and
 *   cost 0 while there is none.
 * - Real hours: Σ `ai_time_entries.hours`. Real cost: the rows of
 *   App\Domain\Ai\ProjectCosts (team hours × rate in force, costs entered
 *   by hand and AI usage).
 * - `cost` is null without `ai.costs.view` (callers pass `$withCosts`).
 * - Due date: the project's, else the estimate's, else today. Projected
 *   due date: App\Domain\Ai\ProjectPace (remaining hours at the pace of
 *   the last two weeks), the due date while nothing is being logged.
 * - `needs_reapproval` (only with an estimate): BudgetRules with Settings
 *   `ai.reapproval_threshold` (0.2 = 20 %) on hours, cost or delivery date.
 */
final class ProjectBudget
{
    public function __construct(
        private readonly ProjectCosts $costs,
        private readonly ProjectPace $pace,
        private readonly Settings $settings,
    ) {}

    public function threshold(): float
    {
        return (float) $this->settings->get('ai.reapproval_threshold', BudgetRules::DEFAULT_REAPPROVAL_THRESHOLD);
    }

    public function estimate(AiProject $project): ?AiEstimate
    {
        return $project->estimates()->reorder()->latest('id')->first();
    }

    public function actualHours(AiProject $project): float
    {
        return round((float) $project->timeEntries()->sum('hours'), 2);
    }

    public function actualCost(AiProject $project): int
    {
        return $this->costs->total($project);
    }

    /**
     * Raw figures (money included): callers decide who sees them.
     *
     * @return array{has_estimate: bool, estimate_hours: float, actual_hours: float, estimate_cost: int, actual_cost: int,
     *     remaining_hours: float, due_date: string, projected_due_date: string, approved_at: string, needs_reapproval: bool}
     */
    public function figures(AiProject $project): array
    {
        $estimate = $this->estimate($project);
        $estimateHours = (float) ($estimate?->hours_total ?? 0);
        $actualHours = $this->actualHours($project);
        $estimateCost = (int) ($estimate?->total_cost ?? 0);
        $actualCost = $this->actualCost($project);
        $remaining = $this->pace->remainingHours($project, $estimateHours, $actualHours);

        $due = ($project->due_date ?? $estimate?->due_date)?->toDateString()
            ?? Week::today()->toDateString();
        $projected = $this->pace->projectedEnd($project, $remaining) ?? $due;
        $approvedAt = ($project->approved_at ?? $project->created_at)->copy()->timezone(BusinessDays::TIMEZONE)->toDateString();
        $threshold = $this->threshold();

        $over = $estimate !== null && (
            ($estimateHours > 0 && BudgetRules::needsReapproval($actualHours, $estimateHours, $threshold))
            || ($estimateCost > 0 && BudgetRules::needsReapproval($actualCost, $estimateCost, $threshold))
            || BudgetRules::scheduleNeedsReapproval($approvedAt, $due, $projected, $threshold)
        );

        return [
            'has_estimate' => $estimate !== null,
            'estimate_hours' => $estimateHours,
            'actual_hours' => $actualHours,
            'estimate_cost' => $estimateCost,
            'actual_cost' => $actualCost,
            'remaining_hours' => $remaining,
            'due_date' => $due,
            'projected_due_date' => $projected,
            'approved_at' => $approvedAt,
            'needs_reapproval' => $over,
        ];
    }

    /**
     * The design `ProjectBudget`.
     *
     * @return array<string, mixed>
     */
    public function for(AiProject $project, bool $withCosts): array
    {
        return self::present($this->figures($project), $withCosts);
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    public static function present(array $figures, bool $withCosts): array
    {
        return [
            'hours' => [
                'estimate' => RequestPresenter::number($figures['estimate_hours']) ?? 0,
                'actual' => RequestPresenter::number($figures['actual_hours']) ?? 0,
            ],
            'cost' => $withCosts ? ['estimate' => $figures['estimate_cost'], 'actual' => $figures['actual_cost']] : null,
            'due_date' => $figures['due_date'],
            'projected_due_date' => $figures['projected_due_date'],
            'approved_at' => $figures['approved_at'],
            'needs_reapproval' => $figures['needs_reapproval'],
        ];
    }
}
