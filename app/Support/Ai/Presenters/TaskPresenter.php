<?php

namespace App\Support\Ai\Presenters;

use App\Domain\Ai\BusinessDays;
use App\Models\AiProject;
use App\Models\AiRequirement;
use App\Models\AiSubtask;
use App\Models\AiTask;
use App\Models\AiTaskAttachment;
use App\Models\AiTaskComment;
use App\Models\AiTimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Board shapes of resources/js/types/ai.ts: `Task` and `TaskDetail`.
 * Hours only, never money. `timer` stays null until B6 (timer);
 * `github` stays null until Stage D fills the issue/PR columns.
 * Task criteria are not linked yet (empty list).
 */
final class TaskPresenter
{
    /** Relations `task()` reads. */
    public const RELATIONS = ['column', 'assignees.employee', 'subtasks', 'blockingRequirements'];

    /** Minutes a signed download link stays valid. */
    private const LINK_MINUTES = 30;

    /**
     * @return array<string, mixed>
     */
    public static function task(AiTask $task, AiProject $project): array
    {
        $task->loadMissing(self::RELATIONS);
        $blocking = $task->blockingRequirements->first();

        return [
            'key' => $task->key,
            'project_key' => $project->key,
            'title' => $task->title,
            'column' => (string) $task->column?->key,
            'labels' => $task->labelList(),
            'assignees' => $task->assignees->map(fn (User $user) => RequestPresenter::person($user))->values()->all(),
            'due_at' => $task->due_date?->toDateString(),
            'subtasks_done' => $task->subtasks->where('done', true)->count(),
            'subtasks_total' => $task->subtasks->count(),
            'blocked_by' => $blocking instanceof AiRequirement
                ? ['id' => $blocking->id, 'title' => $blocking->title, 'area_name' => $blocking->area_name]
                : null,
            'github' => self::github($task),
            'timer' => null,
            'position' => $task->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(AiTask $task, AiProject $project): array
    {
        $task->loadMissing(['comments.author.employee', 'attachments']);

        return [
            ...self::task($task, $project),
            'description' => (string) $task->description,
            'subtasks' => $task->subtasks->map(fn (AiSubtask $subtask) => [
                'id' => $subtask->id,
                'title' => $subtask->title,
                'done' => $subtask->done,
            ])->values()->all(),
            'criteria' => [],
            'comments' => $task->comments
                ->filter(fn (AiTaskComment $comment) => $comment->author !== null)
                ->map(fn (AiTaskComment $comment) => [
                    'id' => $comment->id,
                    'author' => RequestPresenter::person($comment->author),
                    'is_it' => $comment->author->can('ai.manage'),
                    'body' => $comment->body,
                    'created_at' => $comment->created_at->toIso8601String(),
                ])->values()->all(),
            'time_by_person' => self::timeByPerson($task),
            'attachments' => $task->attachments->map(fn (AiTaskAttachment $attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'size' => $attachment->size,
                'mime' => $attachment->mime,
                'url' => self::fileUrl($project, $task, $attachment),
            ])->values()->all(),
            'started_at' => $task->started_at?->copy()->timezone(BusinessDays::TIMEZONE)->toDateString(),
        ];
    }

    public static function fileUrl(AiProject $project, AiTask $task, AiTaskAttachment $attachment): string
    {
        return URL::temporarySignedRoute('ai.tasks.attachments.show', now()->addMinutes(self::LINK_MINUTES), [
            'project' => $project->key,
            'task' => $task->key,
            'attachment' => $attachment->id,
        ]);
    }

    /**
     * Hours per person on the task, most hours first.
     *
     * @return list<array{person: array<string, mixed>, hours: int|float}>
     */
    private static function timeByPerson(AiTask $task): array
    {
        return $task->timeEntries()
            ->with('user.employee')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($entries) => [
                'user' => $entries->first()->user,
                'hours' => round($entries->sum(fn (AiTimeEntry $entry) => (float) $entry->hours), 2),
            ])
            ->filter(fn (array $row) => $row['user'] !== null)
            ->sortByDesc('hours')
            ->map(fn (array $row) => [
                'person' => RequestPresenter::person($row['user']),
                'hours' => RequestPresenter::number($row['hours']) ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Stage D fills the columns; null while the task has no issue nor PR.
     *
     * @return array<string, mixed>|null
     */
    private static function github(AiTask $task): ?array
    {
        if ($task->github_issue_number === null && $task->github_pr_number === null) {
            return null;
        }

        return [
            'issue' => $task->github_issue_number === null ? null : ['number' => $task->github_issue_number, 'state' => $task->github_issue_state ?? 'open'],
            'pr' => $task->github_pr_number === null ? null : ['number' => $task->github_pr_number, 'state' => $task->github_pr_state ?? 'open'],
            'ci' => $task->github_ci,
        ];
    }
}
