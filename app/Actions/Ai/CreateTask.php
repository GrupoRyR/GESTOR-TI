<?php

namespace App\Actions\Ai;

use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A new board task at the end of a column (board "Nueva tarea" and the MCP
 * tool `crear_tarea`): key T-n, creator, optional description, estimate
 * and assignees, and "creó T5 Título" in the project's activity feed
 * (with the request key when it comes from one).
 *
 * Authorization (AiProjectPolicy::manage), the column key (Board::keys())
 * and assignees (BoardPeople) are the caller's.
 */
class CreateTask
{
    /**
     * @param  list<int>  $assigneeIds
     */
    public function handle(
        AiProject $project,
        string $columnKey,
        string $title,
        User $actor,
        ?string $description = null,
        array $assigneeIds = [],
        ?float $estimateHours = null,
        ?string $requestKey = null,
    ): AiTask {
        return DB::transaction(function () use ($project, $columnKey, $title, $actor, $description, $assigneeIds, $estimateHours, $requestKey): AiTask {
            $task = AiTask::create([
                'ai_project_id' => $project->id,
                'ai_project_column_id' => $project->column($columnKey)->id,
                'title' => trim($title),
                'description' => $description,
                'estimate_hours' => $estimateHours,
                'labels' => [],
                'created_by_id' => $actor->id,
            ]);

            if ($assigneeIds !== []) {
                $task->assignees()->sync(array_values(array_unique($assigneeIds)));
            }

            $origin = $requestKey === null ? '' : " ({$requestKey})";
            $project->recordActivity('task', "creó {$task->key} {$task->title}{$origin}", $actor);

            return $task;
        });
    }
}
