<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\Board;
use App\Domain\Ai\BoardPeople;
use App\Domain\Ai\Week;
use App\Domain\GitHub\TaskActivity;
use App\Http\Controllers\Controller;
use App\Models\AiProject;
use App\Models\AiProjectColumn;
use App\Models\AiTask;
use App\Models\AiTaskAttachment;
use App\Models\AiTimer;
use App\Models\User;
use App\Support\Ai\Presenters\ProjectPresenter;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\Presenters\TaskPresenter;
use App\Support\Ai\Presenters\TimerPresenter;
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
 *   Stage D: `selected_task_github` (App\Domain\GitHub\TaskActivity, lazy
 *   like `selected_task`) and `issue_created` (flashed once by
 *   `ai.tasks.issue.store`, always sent).
 * - `Ai/Tasks/Show` (`ai.tasks.show`, phone): the task detail and the
 *   hours the viewer logged today on the project; Stage D `github`
 *   (`TaskGitHub`, null without `ai.manage`).
 * - `ai.tasks.attachments.show`: signed link of a task file, authorized
 *   again (view).
 *
 * `running_timer` and `Task.timer` are the viewer's own running timer.
 */
class BoardController extends Controller
{
    public function __construct(
        private readonly ProjectPresenter $projects,
        private readonly BoardPeople $people,
        private readonly TaskActivity $github,
    ) {}

    public function board(Request $http, AiProject $project): Response
    {
        Gate::authorize('view', $project);
        $timer = TimerPresenter::forUser($this->user($http));

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
            'tasks' => $tasks->map(fn (AiTask $task) => TaskPresenter::task($task, $project, $timer))->all(),
            'total_tasks' => $all->count(),
            'blocked_total' => $all->filter(fn (AiTask $task) => $task->blockingRequirements->isNotEmpty())->count(),
            'column_limits' => (object) $limits,
            'filters' => $filters,
            'people' => $this->people->for($project)->map(fn (User $user) => RequestPresenter::person($user))->values()->all(),
            'labels' => array_values($labels),
            'selected_task' => fn () => $this->selected($http, $project, $timer),
            'selected_task_github' => fn () => $this->selectedGitHub($http, $project),
            'running_timer' => TimerPresenter::running($timer),
            'issue_created' => Inertia::always(fn () => $http->session()->get(GitHubController::ISSUE_CREATED)),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function task(Request $http, AiProject $project, AiTask $task): Response
    {
        $user = $this->user($http);
        Gate::authorize('view', $project);

        return Inertia::render('Ai/Tasks/Show', [
            'project' => ['key' => $project->key, 'name' => $project->name, 'company_id' => (int) $project->company_id],
            'task' => TaskPresenter::detail($task, $project, TimerPresenter::forUser($user)),
            'today_project_hours' => RequestPresenter::number(round((float) $project->timeEntries()
                ->where('user_id', $user->id)
                ->whereDate('date', Week::today()->toDateString())
                ->sum('hours'), 2)) ?? 0,
            'github' => $this->github->for($task, $project, $user, $http->session()->get('errors')),
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
    private function selected(Request $http, AiProject $project, ?AiTimer $timer): ?array
    {
        $key = $http->query('tarea');

        if (! is_string($key) || $key === '') {
            return null;
        }

        $task = $project->tasks()->where('key', $key)->first();

        return $task === null ? null : TaskPresenter::detail($task, $project, $timer);
    }

    /**
     * `TaskGitHub` of the `?tarea=` task; null when the drawer is closed.
     *
     * @return array<string, mixed>|null
     */
    private function selectedGitHub(Request $http, AiProject $project): ?array
    {
        $key = $http->query('tarea');
        $task = is_string($key) && $key !== '' ? $project->tasks()->where('key', $key)->first() : null;

        return $task === null ? null : $this->github->for($task, $project, $this->user($http), $http->session()->get('errors'));
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
