<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\LogTime;
use App\Actions\Ai\WatchProjectBudget;
use App\Domain\Ai\Board;
use App\Domain\Ai\Week;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\TimeEntryRequest;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\AiTimeEntry;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Agregar horas" (types/pages/ai-projects.ts, PROMPT 6.4.11):
 *
 * - `Ai/TimeEntries/Create` (`ai.time-entries.create`, phone): open
 *   projects (planned, in development, in testing) where the viewer may
 *   log hours (AiProjectPolicy::logTime) with their open tasks;
 *   `?project=&task=` preselect when they are in the list; the request key
 *   of each project for the Telegram example.
 * - `ai.time-entries.store {project}`: manual entry (LogTime, then the
 *   budget alerts).
 * - Added (not in the design contract): `ai.time-entries.update|destroy
 *   {project, entry}`, the owner while on the project or IT
 *   (AiProjectPolicy::editTime).
 */
class TimeEntryController extends Controller
{
    private const OPEN_STATUSES = ['planned', 'in_development', 'in_testing'];

    public function create(Request $http): Response
    {
        $user = $this->user($http);
        $projects = $this->projectsFor($user);

        $projectKey = $http->query('project');
        $selected = is_string($projectKey) ? $projects->firstWhere('key', $projectKey) : null;
        $taskKey = $http->query('task');
        $task = $selected !== null && is_string($taskKey) ? collect($selected['tasks'])->firstWhere('key', $taskKey) : null;

        $telegramKeys = AiProject::query()
            ->whereIn('key', $projects->pluck('key')->all())
            ->get()
            ->mapWithKeys(fn (AiProject $project) => [$project->key => $project->originRequest()?->key])
            ->filter()
            ->all();

        return Inertia::render('Ai/TimeEntries/Create', [
            'projects' => $projects->values()->all(),
            'defaults' => ['project_key' => $selected['key'] ?? null, 'task_key' => $task['key'] ?? null],
            'telegram_keys' => (object) $telegramKeys,
            'today' => Week::today()->toDateString(),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function store(TimeEntryRequest $http, AiProject $project, LogTime $log): RedirectResponse
    {
        $user = $this->user($http);
        Gate::authorize('logTime', $project);

        $log->handle(
            $project,
            $user,
            $http->task($project),
            (string) $http->validated('date'),
            (float) $http->validated('hours'),
            (string) $http->validated('note'),
            'manual',
        );

        return back()->with('success', 'Horas guardadas.');
    }

    public function update(TimeEntryRequest $http, AiProject $project, AiTimeEntry $entry, WatchProjectBudget $watch): RedirectResponse
    {
        abort_unless((int) $entry->ai_project_id === $project->id, 404);
        Gate::authorize('editTime', [$project, $entry]);

        $entry->fill([
            'ai_task_id' => $http->task($project)?->id,
            'date' => (string) $http->validated('date'),
            'hours' => round((float) $http->validated('hours'), 2),
            'note' => trim((string) $http->validated('note')),
        ])->save();

        $watch->handle($project);

        return back()->with('success', 'Horas actualizadas.');
    }

    public function destroy(AiProject $project, AiTimeEntry $entry, WatchProjectBudget $watch): RedirectResponse
    {
        abort_unless((int) $entry->ai_project_id === $project->id, 404);
        Gate::authorize('editTime', [$project, $entry]);

        $entry->delete();
        $watch->handle($project);

        return back()->with('success', 'Horas borradas.');
    }

    /**
     * @return Collection<int, array{key: string, name: string, tasks: list<array{key: string, title: string}>}>
     */
    private function projectsFor(User $user): Collection
    {
        return AiProject::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderByDesc('id')
            ->get()
            ->filter(fn (AiProject $project) => Gate::forUser($user)->allows('logTime', $project))
            ->map(fn (AiProject $project) => [
                'key' => $project->key,
                'name' => $project->name,
                'tasks' => self::openTasks($project),
            ])
            ->values();
    }

    /**
     * Tasks outside "Hecho", board order.
     *
     * @return list<array{key: string, title: string}>
     */
    public static function openTasks(AiProject $project): array
    {
        return $project->tasks()
            ->with('column')
            ->get()
            ->filter(fn (AiTask $task) => $task->column?->key !== Board::DONE)
            ->sortBy(fn (AiTask $task) => [$task->column?->position, $task->position, $task->id])
            ->map(fn (AiTask $task) => ['key' => $task->key, 'title' => $task->title])
            ->values()
            ->all();
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
