<?php

namespace App\Support\Ai\Presenters;

use App\Models\AiRequirement;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Requirement shapes of the design: `Requirement` (IT pages) and
 * `RequirementForRecipient` (the person who answers: no costs, no
 * internal notes, no task names), plus `RecipientOption`.
 */
final class RequirementPresenter
{
    /** Relations `requirement()` reads. */
    public const RELATIONS = ['project', 'recipient.employee', 'blocksTask'];

    /**
     * @return array<string, mixed>
     */
    public static function requirement(AiRequirement $requirement): array
    {
        $requirement->loadMissing(self::RELATIONS);
        $task = $requirement->blocksTask;

        return [
            'id' => $requirement->id,
            'project_key' => (string) $requirement->project?->key,
            'title' => $requirement->title,
            'detail' => $requirement->detail,
            'recipient' => $requirement->recipient !== null ? RequestPresenter::person($requirement->recipient) : null,
            'recipient_label' => $requirement->recipient_label,
            'area_name' => $requirement->area_name,
            'company_id' => (int) $requirement->company_id,
            'due_date' => $requirement->due_date->toDateString(),
            'status' => $requirement->displayStatus(),
            'reminders_sent' => $requirement->reminders_sent,
            'blocks_task' => $task !== null ? ['key' => $task->key, 'title' => $task->title] : null,
            'answered_at' => $requirement->answered_at?->toIso8601String(),
            'answer' => $requirement->answer,
            'created_at' => $requirement->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forRecipient(AiRequirement $requirement): array
    {
        $requirement->loadMissing(['project', 'requestedBy.employee']);
        $project = $requirement->project;
        $requester = $requirement->requestedBy;

        return [
            'id' => $requirement->id,
            'title' => $requirement->title,
            'detail' => $requirement->detail,
            'due_date' => $requirement->due_date->toDateString(),
            'status' => $requirement->displayStatus(),
            'requested_by' => $requester !== null
                ? RequestPresenter::person($requester)
                : ['id' => 0, 'name' => 'TI', 'job_title' => null, 'avatar_url' => null],
            'project' => [
                'name' => (string) $project?->name,
                'context' => (string) ($project?->objective ?? ''),
            ],
            'answered_at' => $requirement->answered_at?->toIso8601String(),
            'answer' => $requirement->answer,
            'answer_attachment' => $requirement->answer_name !== null
                ? ['name' => $requirement->answer_name, 'size' => (int) $requirement->answer_size]
                : null,
        ];
    }

    /**
     * `RecipientOption` rows: people with a user and an area, by area then
     * name.
     *
     * @param  Collection<int, Employee>  $employees
     * @return list<array{person: array<string, mixed>, area_name: string, company_id: int}>
     */
    public static function options(Collection $employees): array
    {
        return $employees
            ->filter(fn (Employee $employee) => $employee->user instanceof User)
            ->map(function (Employee $employee): array {
                /** @var User $user */
                $user = $employee->user;
                $user->setRelation('employee', $employee);

                return [
                    'person' => RequestPresenter::person($user),
                    'area_name' => (string) $employee->area?->name,
                    'company_id' => (int) $employee->company_id,
                ];
            })
            ->values()
            ->all();
    }
}
