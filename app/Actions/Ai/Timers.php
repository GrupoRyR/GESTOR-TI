<?php

namespace App\Actions\Ai;

use App\Domain\Ai\BusinessDays;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\AiTimeEntry;
use App\Models\AiTimer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The task timer (`ai.timer.start` / `ai.timer.stop`, PROMPT 6.4.11):
 *
 * - One running timer per person (`ai_timers.user_id` unique). Starting
 *   another task stops the running one first; starting the same task
 *   again keeps it running.
 * - Stopping writes an entry of origin `timer` through LogTime: hours
 *   rounded to the minute (as `formatHours` of lib/timer.ts shows them),
 *   at most LogTime::MAX_HOURS (a forgotten timer), on the Bogotá date the
 *   run started, with the task title as note. Runs under a minute are
 *   dropped.
 */
class Timers
{
    public function __construct(private readonly LogTime $log) {}

    /**
     * @return array{timer: AiTimer, stopped: ?AiTimeEntry}
     */
    public function start(User $user, AiProject $project, AiTask $task): array
    {
        Gate::forUser($user)->authorize('logTime', $project);

        return DB::transaction(function () use ($user, $project, $task): array {
            $current = AiTimer::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($current !== null && (int) $current->ai_task_id === $task->id) {
                return ['timer' => $current, 'stopped' => null];
            }

            $stopped = $current === null ? null : $this->finish($user, $current);

            $timer = AiTimer::create([
                'user_id' => $user->id,
                'ai_project_id' => $project->id,
                'ai_task_id' => $task->id,
                'started_at' => now(),
            ]);

            return ['timer' => $timer, 'stopped' => $stopped];
        });
    }

    /**
     * Stops the running timer. Returns the entry, or null when there was
     * none (`$hadTimer` false) or it ran under a minute.
     *
     * @param-out bool $hadTimer
     */
    public function stop(User $user, ?bool &$hadTimer = null): ?AiTimeEntry
    {
        return DB::transaction(function () use ($user, &$hadTimer): ?AiTimeEntry {
            $timer = AiTimer::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $hadTimer = $timer !== null;

            return $timer === null ? null : $this->finish($user, $timer);
        });
    }

    private function finish(User $user, AiTimer $timer): ?AiTimeEntry
    {
        $ended = now();
        $seconds = (int) $timer->started_at->diffInSeconds($ended, true);
        $minutes = min(LogTime::MAX_HOURS * 60, (int) round($seconds / 60));
        $project = AiProject::query()->withoutGlobalScopes()->find($timer->ai_project_id);
        $task = $timer->ai_task_id === null ? null : AiTask::query()->find($timer->ai_task_id);

        $timer->delete();

        if ($seconds < 60 || $project === null) {
            return null;
        }

        return $this->log->handle(
            $project,
            $user,
            $task,
            $timer->started_at->copy()->timezone(BusinessDays::TIMEZONE)->toDateString(),
            round($minutes / 60, 2),
            $task?->title ?? 'Cronómetro',
            'timer',
            $timer->started_at,
            $ended,
        );
    }
}
