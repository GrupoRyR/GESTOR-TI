<?php

namespace App\Actions\Ai;

use App\Domain\Ai\Board;
use App\Domain\Ai\WipLimit;
use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use App\Models\AiProjectColumn;
use App\Models\AiRequest;
use App\Models\AiTask;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drag and drop on the board (`ai.tasks.move`, 1-based position) and the
 * column change of "Mover a…" / `ai.tasks.update`:
 *
 * - Reorders inside one transaction with the project's tasks locked: the
 *   task is inserted at the position (clamped to the end) and both the
 *   source and the target column are renumbered 1..n.
 * - Stamps `started_at` when the task first enters "En progreso" and
 *   `completed_at` while it is in "Hecho".
 * - Project lifecycle (kept simple, PROMPT 6.4.3): the first task in "En
 *   progreso" starts a planned project ("En desarrollo", refused while the
 *   WIP limit is reached) and moves its planned requests to "En
 *   desarrollo"; when every task is in "Hecho" a project in development
 *   goes to "En pruebas" with its requests (the requester validates the
 *   acceptance criteria). Later moves (production, closing) stay manual.
 * - Writes "movió T3 Endpoint de zonas a En revisión" to the activity feed.
 *
 * The actor is null for system moves (GitHub webhooks whose author is not
 * a Gestor person).
 */
class MoveTask
{
    public function __construct(
        private readonly WipLimit $wip,
        private readonly TransitionRequest $transition,
    ) {}

    public function handle(AiTask $task, string $columnKey, ?int $position, ?User $actor): void
    {
        /** @var AiProject $project */
        $project = $task->project;
        $target = $project->columns()->where('key', $columnKey)->first();

        if (! $target instanceof AiProjectColumn) {
            throw ValidationException::withMessages(['column' => 'Esa columna no existe en el tablero.']);
        }

        $source = $task->column;
        $starts = $columnKey === Board::IN_PROGRESS && $project->status === RequestStatus::Planned;

        if ($starts && $this->wip->isFull()) {
            $limit = $this->wip->limit();

            throw ValidationException::withMessages([
                'column' => "Ya hay {$limit} proyectos en desarrollo (el límite es {$limit}). Termina o pausa uno antes de empezar {$project->key}.",
            ]);
        }

        DB::transaction(function () use ($task, $project, $source, $target, $position, $actor): void {
            AiTask::query()->where('ai_project_id', $project->id)->lockForUpdate()->get(['id']);

            $siblings = $this->ordered($target, $task->id);
            $index = $position === null ? $siblings->count() : max(0, min($position - 1, $siblings->count()));
            $siblings->splice($index, 0, [$task]);

            $changed = $source?->id !== $target->id;
            $task->ai_project_column_id = $target->id;

            if ($target->key === Board::IN_PROGRESS && $task->started_at === null) {
                $task->started_at = now();
            }

            $task->completed_at = $target->is_done ? ($task->completed_at ?? now()) : null;
            $task->save();

            $this->renumber($siblings);

            if ($changed && $source !== null) {
                $this->renumber($this->ordered($source, $task->id));
                $project->recordActivity('task', "movió {$task->key} {$task->title} a {$target->name}", $actor);
            }

            $this->advanceProject($project, $target, $actor);
        });
    }

    /**
     * Tasks of a column in board order, without the moving task.
     *
     * @return Collection<int, AiTask>
     */
    private function ordered(AiProjectColumn $column, int $exceptId): Collection
    {
        return AiTask::query()
            ->where('ai_project_column_id', $column->id)
            ->whereKeyNot($exceptId)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'position']);
    }

    /**
     * @param  Collection<int, AiTask>  $tasks
     */
    private function renumber(Collection $tasks): void
    {
        foreach ($tasks->values() as $index => $task) {
            if ($task->position !== $index + 1) {
                AiTask::query()->whereKey($task->id)->update(['position' => $index + 1]);
            }
        }
    }

    private function advanceProject(AiProject $project, AiProjectColumn $target, ?User $actor): void
    {
        if ($project->status === RequestStatus::Planned && $target->key === Board::IN_PROGRESS) {
            $project->forceFill(['status' => RequestStatus::InDevelopment, 'started_at' => $project->started_at ?? now()])->save();
            $project->recordActivity('task', 'empezó el desarrollo', $actor);
            $this->moveRequests($project, RequestStatus::Planned, RequestStatus::InDevelopment, $actor);

            return;
        }

        if ($project->status !== RequestStatus::InDevelopment) {
            return;
        }

        $total = $project->tasks()->count();
        $done = $project->tasks()->whereHas('column', fn ($query) => $query->where('is_done', true))->count();

        if ($total > 0 && $done === $total) {
            $project->forceFill(['status' => RequestStatus::InTesting])->save();
            $project->recordActivity('task', 'terminó todas las tareas: pasa a pruebas', $actor);
            $this->moveRequests($project, RequestStatus::InDevelopment, RequestStatus::InTesting, $actor);
        }
    }

    private function moveRequests(AiProject $project, RequestStatus $from, RequestStatus $to, ?User $actor): void
    {
        $project->requests()->where('ai_requests.status', $from->value)->get()
            ->each(fn (AiRequest $request) => $this->transition->apply($request, $to, $actor));
    }
}
