<?php

namespace App\Actions\Ai;

use App\Models\AiTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Changes of a board task (board drawer `ai.tasks.update` rules and the MCP
 * tool `actualizar_tarea`): only the given keys change.
 *
 * - `title` (trimmed), `description`, `due_date` (Y-m-d or null),
 *   `labels` (trimmed, unique, empty ones dropped), `assignee_ids`
 *   (replaces the assignees).
 * - `column`: a different column moves the task to its end through
 *   MoveTask (positions, dates, project lifecycle, activity feed).
 *
 * Authorization (AiProjectPolicy::manage), the column key and assignees
 * (BoardPeople) are the caller's.
 */
class UpdateTask
{
    public function __construct(private readonly MoveTask $move) {}

    /**
     * @param  array{title?: string, description?: ?string, due_date?: ?string, labels?: list<string>, assignee_ids?: list<int>, column?: string}  $changes
     */
    public function handle(AiTask $task, array $changes, ?User $actor): AiTask
    {
        DB::transaction(function () use ($task, $changes): void {
            if (array_key_exists('title', $changes)) {
                $task->title = trim($changes['title']);
            }

            if (array_key_exists('description', $changes)) {
                $task->description = $changes['description'];
            }

            if (array_key_exists('due_date', $changes)) {
                $task->due_date = $changes['due_date'];
            }

            if (array_key_exists('labels', $changes)) {
                $task->labels = collect($changes['labels'])
                    ->map(fn ($label) => trim((string) $label))
                    ->filter(fn (string $label) => $label !== '')
                    ->unique()
                    ->values()
                    ->all();
            }

            $task->save();

            if (array_key_exists('assignee_ids', $changes)) {
                $task->assignees()->sync(array_values(array_unique(array_map('intval', $changes['assignee_ids']))));
            }
        });

        if (isset($changes['column']) && $changes['column'] !== $task->column?->key) {
            $this->move->handle($task, $changes['column'], null, $actor);
        }

        return $task->refresh();
    }
}
