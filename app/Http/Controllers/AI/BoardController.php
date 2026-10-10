<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\Board;
use App\Domain\Ai\BoardPeople;
use App\Domain\Ai\Week;
use App\Http\Controllers\Controller;
use App\Models\AiProject;
use App\Models\AiProjectColumn;
use App\Models\AiTask;
use App\Models\AiTaskAttachment;
use App\Models\User;
use App\Support\Ai\Presenters\ProjectPresenter;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\Presenters\TaskPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Board pages (types/pages/ai-projects.ts), AiProjectPolicy::view:
 *
 * - `Ai/Projects/Board` (`ai.projects.board`): tasks after the filters
 *   (`assignee_id`, `label`, `blocked=1`), totals without filters, the
 *   "En progreso" limit, people and labels; `selected_task` is the task of
 *   `?tarea=` (partial reload `only: ['selected_task']`), null when absent.
 * - `Ai/Tasks/Show` (`ai.tasks.show`, phone): the task detail and the
 *   hours the viewer logged today on the project.
 * - `ai.tasks.attachments.show`: signed link of a task file, authorized
 *   again (view).
 *
 * `running_timer` and task timers stay null until B6 (timer).
 */
class BoardController extends Controller
{
    public function __construct(
        private readonly ProjectPresenter $projects,
        private readonly BoardPeople $people,
    ) {}

    public function board(Request $http, AiProject $project): Response
    {
        Gate::authorize('view', $project);

        $filters = [
            'assignee_id' => is_numeric($http->query('assignee_id')) ? (int) $http->query('assignee_id') : null,
            'label' => is_string($http->query('label')) && $http->query('label') !== '' ? (string) $http->query('label') : null,
            'blocked' => filter_var($http->query('blocked'), FILTER_VALIDATE_BOOLEAN),
        ];

        $all = $project->tasks()
            ->with(TaskPresenter::RELATIONS)
            ->get()
            ->sortBy(fn (AiTask $task) => [$task->column?->position, $task->position, $task->id])
            ->values();

        $tasks = $all
            ->filter(fn (AiTask $task) => $filters['assignee_id'] === null || $task->assignees->contains('id', $filters['assignee_id']))
            ->filter(fn (AiTask $task) => $filters['label'] === null || in_array($filters['label'], $task->labelList(), true))
            ->filter(fn (AiTask $task) => ! $filters['blocked'] || $task->blockingRequirements->isNotEmpty())
            ->values();

        $labels = $all->flatMap(fn (AiTask $task) => $task->labelList())->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        $limits = $project->columns()->whereNotNull('wip_limit')->get()
            ->mapWithKeys(fn (AiProjectColumn $column) => [$column->key => $column->wip_limit])
            ->all();

        return Inertia::render('Ai/Projects/Board', [
            'project' => $this->projects->header($project),
            'tasks' => $tasks->map(fn (AiTask $task) => TaskPresenter::task($task, $project))->all(),
            'total_tasks' => $all->count(),
            'blocked_total' => $all->filter(fn (AiTask $task) => $task->blockingRequirements->isNotEmpty())->count(),
            'column_limits' => (object) $limits,
            'filters' => $filters,
            'people' => $this->people->for($project)->map(fn (User $user) => RequestPresenter::person($user))->values()->all(),
            'labels' => array_values($labels),
            'selected_task' => fn () => $this->selected($http, $project),
            // TODO B6: the viewer's running timer.
            'running_timer' => null,
            'now' => now()->toIso8601String(),
        ]);
    }

    public function task(Request $http, AiProject $project, AiTask $task): Response
    {
        $user = $this->user($http);
        Gate::authorize('view', $project);

        return Inertia::render('Ai/Tasks/Show', [
            'project' => ['key' => $project->key, 'name' => $project->name, 'company_id' => (int) $project->company_id],
            'task' => TaskPresenter::detail($task, $project),
            'today_project_hours' => RequestPresenter::number(round((float) $project->timeEntries()
                ->where('user_id', $user->id)
                ->whereDate('date', Week::today()->toDateString())
                ->sum('hours'), 2)) ?? 0,
            'now' => now()->toIso8601String(),
        ]);
    }

    public function attachment(AiProject $project, AiTask $task, AiTaskAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $project);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selected(Request $http, AiProject $project): ?array
    {
        $key = $http->query('tarea');

        if (! is_string($key) || $key === '') {
            return null;
        }

        $task = $project->tasks()->where('key', $key)->first();

        return $task === null ? null : TaskPresenter::detail($task, $project);
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
