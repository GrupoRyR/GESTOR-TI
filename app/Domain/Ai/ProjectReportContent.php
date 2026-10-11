<?php

namespace App\Domain\Ai;

use App\Enums\Ai\AcceptanceResult;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiRequirement;
use App\Models\AiTask;

/**
 * Content of the project report (design `ReportContent`): the page
 * preview, the e-mail and the PDF use the same data.
 *
 * - done: tasks finished in the last 7 days (newest first, 8 max).
 * - next: tasks in "En progreso", then "Por hacer", in board order (5 max).
 * - blockers: open requirements, soonest due first, in plain words.
 * - budget: App\Domain\Ai\ProjectBudget, only when `$withCosts` (the
 *   caller decides: `ai.costs.view`, internal version, section chosen).
 */
final class ProjectReportContent
{
    private const DONE_DAYS = 7;

    private const DONE_MAX = 8;

    private const NEXT_MAX = 5;

    public function __construct(private readonly ProjectBudget $budget) {}

    /**
     * @return array<string, mixed>
     */
    public function for(AiProject $project, bool $withCosts): array
    {
        $project->loadMissing(['company', 'area']);
        $requests = $project->requests()->with(['requester', 'criteria'])->get();
        /** @var AiRequest|null $first */
        $first = $requests->first();
        $criteria = $requests->flatMap(fn (AiRequest $request) => $request->criteria);

        $tasks = $project->tasks()->with('column')->get();

        return [
            'as_of' => Week::today()->toDateString(),
            'company_id' => (int) $project->company_id,
            'project' => ['key' => $project->key, 'name' => $project->name, 'status' => $project->status->value],
            'requester_name' => (string) ($first?->requester?->name ?? $project->area?->name ?? ''),
            'request_key' => $first?->key,
            'tasks' => [
                'done' => $tasks->filter(fn (AiTask $task) => (bool) $task->column?->is_done)->count(),
                'total' => $tasks->count(),
            ],
            'criteria' => [
                'done' => $criteria->filter(fn ($criterion) => $criterion->result === AcceptanceResult::Pass)->count(),
                'total' => $criteria->count(),
            ],
            'done' => $tasks
                ->filter(fn (AiTask $task) => (bool) $task->column?->is_done && $task->completed_at !== null && $task->completed_at->greaterThanOrEqualTo(now()->subDays(self::DONE_DAYS)))
                ->sortByDesc(fn (AiTask $task) => $task->completed_at?->getTimestamp())
                ->take(self::DONE_MAX)
                ->map(fn (AiTask $task) => $task->title)
                ->values()
                ->all(),
            'next' => $tasks
                ->filter(fn (AiTask $task) => in_array($task->column?->key, [Board::IN_PROGRESS, Board::TODO], true))
                ->sortBy(fn (AiTask $task) => [$task->column?->key === Board::IN_PROGRESS ? 0 : 1, $task->position, $task->id])
                ->take(self::NEXT_MAX)
                ->map(fn (AiTask $task) => $task->title)
                ->values()
                ->all(),
            'blockers' => $this->blockers($project),
            'budget' => $withCosts ? $this->budget->for($project, true) : null,
            'project_url' => route('ai.projects.show', $project->key),
            'sender_email' => (string) ($project->company?->sender_email ?: config('mail.from.address')),
        ];
    }

    /**
     * @return list<string>
     */
    private function blockers(AiProject $project): array
    {
        return $project->requirements()
            ->where('status', AiRequirement::PENDING)
            ->with('blocksTask')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(function (AiRequirement $requirement): string {
                $line = "Esperamos de {$requirement->area_name} «{$requirement->title}». Vence el {$requirement->due_date->format('d/m/Y')}";
                $task = $requirement->blocksTask;

                return $task !== null ? "{$line}; sin eso, «{$task->title}» queda en pausa." : "{$line}.";
            })
            ->values()
            ->all();
    }
}
