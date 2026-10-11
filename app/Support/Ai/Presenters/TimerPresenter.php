<?php

namespace App\Support\Ai\Presenters;

use App\Models\AiTask;
use App\Models\AiTimer;
use App\Models\User;

/**
 * Timer shapes: `RunningTimer` (types/pages/ai-triage.ts, the viewer's
 * running timer on the board and Mi semana) and `TaskTimer` (types/ai.ts,
 * on a task: only the viewer's own timer, so "Parar" stops theirs).
 */
final class TimerPresenter
{
    public static function forUser(User $user): ?AiTimer
    {
        return AiTimer::query()->where('user_id', $user->id)->with(['project', 'task.subtasks'])->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function running(?AiTimer $timer): ?array
    {
        if ($timer === null || $timer->project === null || ! $timer->task instanceof AiTask) {
            return null;
        }

        $task = $timer->task;

        return [
            'project_key' => $timer->project->key,
            'task_key' => $task->key,
            'task_title' => $task->title,
            'started_at' => $timer->started_at->toIso8601String(),
            'subtasks_done' => $task->subtasks->where('done', true)->count(),
            'subtasks_total' => $task->subtasks->count(),
            'due_at' => $task->due_date?->toDateString(),
        ];
    }

    /**
     * `Task.timer`: the viewer's timer when it runs on this task.
     *
     * @return array{running: bool, started_at: string, person_id: int}|null
     */
    public static function task(AiTask $task, ?AiTimer $timer): ?array
    {
        if ($timer === null || (int) $timer->ai_task_id !== $task->id) {
            return null;
        }

        return [
            'running' => true,
            'started_at' => $timer->started_at->toIso8601String(),
            'person_id' => (int) $timer->user_id,
        ];
    }
}
