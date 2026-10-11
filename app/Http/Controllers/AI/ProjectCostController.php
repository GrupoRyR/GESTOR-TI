<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\WatchProjectBudget;
use App\Domain\Ai\ProjectBudget;
use App\Domain\Ai\ProjectCosts;
use App\Domain\Ai\ProjectPace;
use App\Domain\Ai\Week;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ProjectCostRequest;
use App\Models\AiProject;
use App\Models\AiProjectCost;
use App\Models\AiTimeEntry;
use App\Models\AiTimer;
use App\Models\User;
use App\Support\Ai\Presenters\ProjectPresenter;
use App\Support\Ai\Presenters\RequestPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * "Tiempo y costos" of a project (B-ProjectCosts, PROMPT 6.4.11):
 *
 * - `Ai/Projects/Costs` (`ai.projects.costs`, `ai.manage` + view):
 *   estimate vs real (ProjectBudget), hours curve (plan, real, projection
 *   at the pace of the last 2 weeks), the hours log of `?week=` (Monday;
 *   this week by default), hours per person, running timers, costs
 *   (ProjectCosts) and the open tasks. Without `ai.costs.view` the page
 *   renders NoPermission: the same keys go out empty (no hours log, no
 *   money).
 * - `ai.projects.costs.export`: CSV (`;`, UTF-8 BOM, opens in Excel) of the
 *   week's log; `ai.costs.view`.
 * - `ai.costs.store` (+ `update|destroy` and the signed `ai.costs.attachment`,
 *   not in the contract): costs entered by hand, AiProjectPolicy::manageCosts
 *   (`ai.costs.manage`); receipts on the private disk.
 */
class ProjectCostController extends Controller
{
    public function __construct(
        private readonly ProjectPresenter $projects,
        private readonly ProjectBudget $budget,
        private readonly ProjectCosts $costs,
        private readonly ProjectPace $pace,
    ) {}

    public function index(Request $http, AiProject $project): Response
    {
        $user = $this->user($http);
        Gate::authorize('view', $project);

        $week = $this->week($http);
        $request = $project->originRequest();
        $withCosts = $user->can('ai.costs.view');
        $figures = $this->budget->figures($project);
        $recent = $this->pace->recentHours($project);

        return Inertia::render('Ai/Projects/Costs', [
            'project' => $this->projects->header($project),
            'budget' => ProjectBudget::present($figures, $withCosts),
            'curve' => $withCosts ? $this->curve($project, $figures) : ['plan' => [], 'actual' => [], 'projection' => []],
            'remaining_hours' => $withCosts ? (RequestPresenter::number($figures['remaining_hours']) ?? 0) : 0,
            'recent_hours' => $withCosts ? (RequestPresenter::number($recent) ?? 0) : 0,
            'recent_weeks' => ProjectPace::RECENT_WEEKS,
            'pace_note' => $withCosts ? $this->pace->note($project) : null,
            'week' => ['from' => $week->toDateString(), 'to' => $week->addDays(6)->toDateString()],
            'entries' => $withCosts ? $this->entries($project, $week)->map(fn (AiTimeEntry $entry) => $this->entry($project, $entry))->values()->all() : [],
            'total_by_person' => $withCosts ? ProjectCosts::hoursByPerson($project) : [],
            'running_timers' => $withCosts ? $this->runningTimers($project) : [],
            'costs' => $withCosts ? $this->costs->rows($project) : [],
            'tasks' => $withCosts ? TimeEntryController::openTasks($project) : [],
            'telegram_example' => '/horas '.($request?->key ?? $project->key).' 2 qué hiciste',
            'today' => Week::today()->toDateString(),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function export(Request $http, AiProject $project): StreamedResponse
    {
        Gate::authorize('view', $project);
        abort_unless($this->user($http)->can('ai.costs.view'), 403);

        $week = $this->week($http);
        $rows = $this->entries($project, $week);
        $name = "horas-{$project->key}-{$week->toDateString()}.csv";

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha', 'Persona', 'Tarea', 'Horas', 'Origen', 'Nota'], ';', '"', '');

            foreach ($rows as $entry) {
                fputcsv($out, [
                    $entry->date->toDateString(),
                    (string) $entry->user?->name,
                    $entry->task !== null ? "{$entry->task->key} {$entry->task->title}" : 'Sin tarea',
                    str_replace('.', ',', (string) (RequestPresenter::number((float) $entry->hours) ?? 0)),
                    match ($entry->origin) {
                        'timer' => 'Cronómetro',
                        'telegram' => 'Telegram',
                        default => 'Manual',
                    },
                    (string) $entry->note,
                ], ';', '"', '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(ProjectCostRequest $http, AiProject $project, WatchProjectBudget $watch): RedirectResponse
    {
        Gate::authorize('manageCosts', $project);

        $cost = new AiProjectCost(['ai_project_id' => $project->id, 'created_by_id' => $this->user($http)->id]);
        $this->fill($cost, $http, $project);
        $watch->handle($project);

        return back()->with('success', 'Costo agregado.');
    }

    public function update(ProjectCostRequest $http, AiProject $project, AiProjectCost $cost, WatchProjectBudget $watch): RedirectResponse
    {
        abort_unless((int) $cost->ai_project_id === $project->id, 404);
        Gate::authorize('manageCosts', $project);

        $this->fill($cost, $http, $project);
        $watch->handle($project);

        return back()->with('success', 'Costo actualizado.');
    }

    public function destroy(AiProject $project, AiProjectCost $cost, WatchProjectBudget $watch): RedirectResponse
    {
        abort_unless((int) $cost->ai_project_id === $project->id, 404);
        Gate::authorize('manageCosts', $project);

        $this->deleteFile($cost);
        $cost->delete();
        $watch->handle($project);

        return back()->with('success', 'Costo borrado.');
    }

    public function attachment(Request $http, AiProject $project, AiProjectCost $cost): StreamedResponse
    {
        abort_unless((int) $cost->ai_project_id === $project->id && $cost->attachment_path !== null, 404);
        Gate::authorize('view', $project);
        abort_unless($this->user($http)->can('ai.costs.view'), 403);

        return Storage::disk((string) $cost->attachment_disk)->download((string) $cost->attachment_path, (string) $cost->attachment_name);
    }

    private function fill(AiProjectCost $cost, ProjectCostRequest $http, AiProject $project): void
    {
        $cost->fill([
            'type' => $http->validated('type'),
            'concept' => trim((string) $http->validated('concept')),
            'recurrence' => $http->validated('recurrence'),
            'amount' => (int) $http->validated('amount'),
            'date' => $http->validated('date'),
        ]);

        $file = $http->file('attachment');

        if ($file !== null) {
            $this->deleteFile($cost);
            $disk = (string) config('gestor.ai.attachments.disk', 'local');
            $cost->fill([
                'attachment_disk' => $disk,
                'attachment_path' => $file->store("ai/projects/{$project->id}/costs", $disk),
                'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'attachment_mime' => (string) ($file->getMimeType() ?? $file->getClientMimeType()),
                'attachment_size' => (int) $file->getSize(),
            ]);
        }

        $cost->save();
    }

    private function deleteFile(AiProjectCost $cost): void
    {
        if ($cost->attachment_path === null) {
            return;
        }

        try {
            Storage::disk((string) $cost->attachment_disk)->delete($cost->attachment_path);
        } catch (Throwable) {
            // A missing file never blocks the change.
        }
    }

    /**
     * Monday of `?week=` (any day of that week), else this week (Bogotá).
     */
    private function week(Request $http): CarbonImmutable
    {
        $value = $http->query('week');
        $day = Week::today();

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $day->timezone) ?: $day;
            } catch (Throwable) {
                // Keep this week.
            }
        }

        return $day->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * Entries of the week, newest first.
     *
     * @return Collection<int, AiTimeEntry>
     */
    private function entries(AiProject $project, CarbonImmutable $monday): Collection
    {
        return $project->timeEntries()
            ->with(['user.employee', 'task'])
            ->whereDate('date', '>=', $monday->toDateString())
            ->whereDate('date', '<=', $monday->addDays(6)->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Design `TimeEntry`.
     *
     * @return array<string, mixed>
     */
    private function entry(AiProject $project, AiTimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->date->toDateString(),
            'person' => $entry->user !== null ? RequestPresenter::person($entry->user) : ['id' => 0, 'name' => '', 'job_title' => null, 'avatar_url' => null],
            'project_key' => $project->key,
            'task' => $entry->task !== null ? ['key' => $entry->task->key, 'title' => $entry->task->title] : null,
            'hours' => RequestPresenter::number((float) $entry->hours) ?? 0,
            'origin' => $entry->origin,
            'note' => (string) $entry->note,
        ];
    }

    /**
     * @return list<array{person: array<string, mixed>, task_key: string, started_at: string}>
     */
    private function runningTimers(AiProject $project): array
    {
        return $project->timers()
            ->with(['user.employee', 'task'])
            ->orderBy('started_at')
            ->get()
            ->filter(fn (AiTimer $timer) => $timer->user !== null && $timer->task !== null)
            ->map(fn (AiTimer $timer) => [
                'person' => RequestPresenter::person($timer->user),
                'task_key' => $timer->task->key,
                'started_at' => $timer->started_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * `HoursCurve`: plan (approval → due date), cumulative real hours per
     * day with entries from the approval date, projection from today.
     *
     * @param  array<string, mixed>  $figures
     * @return array{plan: list<array{date: string, value: int|float}>, actual: list<array{date: string, value: int|float}>, projection: list<array{date: string, value: int|float}>}
     */
    private function curve(AiProject $project, array $figures): array
    {
        $start = (string) $figures['approved_at'];
        $point = fn (string $date, float $value) => ['date' => $date, 'value' => RequestPresenter::number(round($value, 2)) ?? 0];

        $actual = [$point($start, 0)];
        $sum = 0.0;

        $project->timeEntries()
            ->orderBy('date')
            ->get(['date', 'hours'])
            ->groupBy(fn (AiTimeEntry $entry) => $entry->date->toDateString())
            ->each(function (Collection $entries, string $date) use (&$sum, &$actual, $point, $start): void {
                $sum += $entries->sum(fn (AiTimeEntry $entry) => (float) $entry->hours);

                if ($date > $start) {
                    $actual[] = $point($date, $sum);
                } else {
                    $actual[0] = $point($start, $sum);
                }
            });

        $today = Week::today()->toDateString();
        $end = $this->pace->projectedEnd($project, (float) $figures['remaining_hours']);
        $projection = $end !== null && $figures['remaining_hours'] > 0
            ? [$point($today, $figures['actual_hours']), $point($end, $figures['actual_hours'] + $figures['remaining_hours'])]
            : [];

        return [
            'plan' => $figures['has_estimate'] ? [$point($start, 0), $point((string) $figures['due_date'], $figures['estimate_hours'])] : [],
            'actual' => $actual,
            'projection' => $projection,
        ];
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
