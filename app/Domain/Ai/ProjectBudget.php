<?php

namespace App\Domain\Ai;

use App\Models\AiEstimate;
use App\Models\AiProject;
use App\Models\AiTimeEntry;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Settings;

/**
 * Estimated vs real of a project (PROMPT 6.4.11, design `ProjectBudget`):
 *
 * - Estimate: the latest `ai_estimates` row linked to the project (the one
 *   the approvers saw); hours 0 and cost 0 while there is none.
 * - Real hours: Σ `ai_time_entries.hours`. Real cost: Σ hours × the rate in
 *   force for the person on the entry's date (App\Domain\Ai\CostRates).
 *   Project costs (licences, AI usage…) are added in B6.
 * - `cost` is null without `ai.costs.view` (callers pass `$withCosts`).
 * - Due date: the project's, else the estimate's, else today.
 *   `projected_due_date` equals it until B6 computes the pace.
 * - `needs_reapproval`: real hours or cost above the estimate by more than
 *   Settings `ai.reapproval_threshold` (0.2 = 20 %).
 */
final class ProjectBudget
{
    public function __construct(
        private readonly CostRates $rates,
        private readonly Settings $settings,
    ) {}

    public function threshold(): float
    {
        return (float) $this->settings->get('ai.reapproval_threshold', 0.2);
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
        $total = 0.0;

        $project->timeEntries()->get(['user_id', 'date', 'hours'])->each(function (AiTimeEntry $entry) use (&$total): void {
            $rate = $this->rates->rateFor($entry->user_id, $entry->date);
            $total += (float) $entry->hours * (int) $rate;
        });

        return (int) round($total);
    }

    /**
     * @return array<string, mixed>
     */
    public function for(AiProject $project, bool $withCosts): array
    {
        $estimate = $this->estimate($project);
        $estimateHours = (float) ($estimate?->hours_total ?? 0);
        $actualHours = $this->actualHours($project);
        $estimateCost = (int) ($estimate?->total_cost ?? 0);
        $actualCost = $this->actualCost($project);
        $limit = 1 + $this->threshold();

        $over = ($estimateHours > 0 && $actualHours > $estimateHours * $limit)
            || ($estimateCost > 0 && $actualCost > $estimateCost * $limit);

        $due = ($project->due_date ?? $estimate?->due_date)?->toDateString()
            ?? Week::today()->toDateString();

        return [
            'hours' => [
                'estimate' => RequestPresenter::number($estimateHours) ?? 0,
                'actual' => RequestPresenter::number($actualHours) ?? 0,
            ],
            'cost' => $withCosts ? ['estimate' => $estimateCost, 'actual' => $actualCost] : null,
            'due_date' => $due,
            'projected_due_date' => $due,
            'approved_at' => ($project->approved_at ?? $project->created_at)->copy()->timezone(BusinessDays::TIMEZONE)->toDateString(),
            'needs_reapproval' => $over,
        ];
    }
}
