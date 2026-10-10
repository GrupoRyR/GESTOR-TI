<?php

namespace App\Actions\Ai;

use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\AiTimeEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Writes one time entry (PROMPT 6.4.11) for the timer, the "Agregar horas"
 * form and Telegram `/horas`, then checks the budget alerts.
 * Needs AiProjectPolicy::logTime; callers validate date, hours and note.
 */
class LogTime
{
    /** Hours allowed in one entry (one day of work). */
    public const MAX_HOURS = 16;

    public function __construct(private readonly WatchProjectBudget $watch) {}

    public function handle(
        AiProject $project,
        User $user,
        ?AiTask $task,
        string $date,
        float $hours,
        string $note,
        string $origin,
        ?CarbonInterface $startedAt = null,
        ?CarbonInterface $endedAt = null,
    ): AiTimeEntry {
        Gate::forUser($user)->authorize('logTime', $project);

        if (! in_array($origin, AiTimeEntry::ORIGINS, true)) {
            throw new InvalidArgumentException("Invalid time entry origin [{$origin}].");
        }

        if ($task !== null && (int) $task->ai_project_id !== $project->id) {
            throw new InvalidArgumentException('The task belongs to another project.');
        }

        $entry = AiTimeEntry::create([
            'user_id' => $user->id,
            'ai_project_id' => $project->id,
            'ai_task_id' => $task?->id,
            'date' => $date,
            'hours' => round($hours, 2),
            'origin' => $origin,
            'note' => mb_substr(trim($note), 0, 255),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ]);

        $this->watch->handle($project);

        return $entry;
    }
}
