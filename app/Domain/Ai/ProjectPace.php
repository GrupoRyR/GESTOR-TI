<?php

namespace App\Domain\Ai;

use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\AiTimeEntry;

/**
 * Pace of a project (B-ProjectCosts): hours logged in the last
 * `RECENT_WEEKS` weeks, hours the open tasks still need and the date they
 * run out at that pace (BudgetRules::projectedEnd, same as the page).
 *
 * Remaining hours = Σ `estimate_hours` of the tasks outside "Hecho"; when
 * no open task has an estimate, what is left of the approved hours.
 */
final class ProjectPace
{
    public const RECENT_WEEKS = 2;

    public function remainingHours(AiProject $project, float $estimateHours, float $actualHours): float
    {
        $open = $project->tasks()
            ->whereHas('column', fn ($query) => $query->where('key', '!=', Board::DONE))
            ->whereNotNull('estimate_hours')
            ->get(['id', 'estimate_hours']);

        if ($open->isNotEmpty()) {
            return round($open->sum(fn (AiTask $task) => (float) $task->estimate_hours), 2);
        }

        return round(max(0, $estimateHours - $actualHours), 2);
    }

    public function recentHours(AiProject $project): float
    {
        $today = Week::today();

        return round((float) $project->timeEntries()
            ->whereDate('date', '>=', $today->subDays(self::RECENT_WEEKS * 7 - 1)->toDateString())
            ->whereDate('date', '<=', $today->toDateString())
            ->sum('hours'), 2);
    }

    public function projectedEnd(AiProject $project, float $remaining): ?string
    {
        return BudgetRules::projectedEnd(Week::today()->toDateString(), $remaining, $this->recentHours($project) / self::RECENT_WEEKS);
    }

    /**
     * "porque el equipo reparte el tiempo con P-05 y P-06": other projects
     * where the people of this one logged hours in the same weeks.
     */
    public function note(AiProject $project): ?string
    {
        $from = Week::today()->subDays(self::RECENT_WEEKS * 7 - 1)->toDateString();
        $people = $project->timeEntries()->whereDate('date', '>=', $from)->distinct()->pluck('user_id')->all();

        if ($people === []) {
            return null;
        }

        $others = AiTimeEntry::query()
            ->whereIn('user_id', $people)
            ->where('ai_project_id', '!=', $project->id)
            ->whereDate('date', '>=', $from)
            ->distinct()
            ->pluck('ai_project_id')
            ->all();

        $keys = AiProject::query()->whereIn('id', $others)->orderBy('id')->pluck('key')->all();

        if ($keys === []) {
            return null;
        }

        $last = array_pop($keys);

        return 'porque el equipo reparte el tiempo con '.($keys === [] ? $last : implode(', ', $keys).' y '.$last);
    }
}
