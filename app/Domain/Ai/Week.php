<?php

namespace App\Domain\Ai;

use App\Enums\Ai\AcceptanceResult;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\AiRequirement;
use App\Models\AiTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Mi semana" (PROMPT 6.4.3, WeekIndexProps) for the IT team, in the
 * current company context. Columns:
 *
 * - `due`: first responses and open tasks due by the end of the week
 *   (overdue ones included), soonest first; the person is the assignee
 *   ("TI" when nobody is assigned).
 * - `blocked`: open tasks waiting on a pending requirement (the one due
 *   soonest), oldest wait first; the action reminds the requirement.
 * - `waiting`: what waits on somebody else, oldest first — a question to
 *   the requester ("Necesita información", title + the open question), a
 *   validation of the requester ("En pruebas"), project approvals (the
 *   approvers who have not decided) and requirements to users that block
 *   no task.
 */
final class Week
{
    /** Shown when nobody is assigned. */
    public const UNASSIGNED = 'TI';

    public function __construct(private readonly Inbox $inbox) {}

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(BusinessDays::TIMEZONE)->startOfDay();
    }

    /**
     * Sunday of the current week (Bogotá).
     */
    public static function endsOn(): CarbonImmutable
    {
        return self::today()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
    }

    /**
     * Open tasks of the projects in the current company context.
     *
     * @return Builder<AiTask>
     */
    public static function openTasks(): Builder
    {
        return AiTask::query()
            ->whereIn('ai_project_id', AiProject::query()->select('id'))
            ->whereHas('column', fn ($query) => $query->where('is_done', false));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        [$blocked, $blockingIds] = $this->blocked();

        return [...$this->due(), ...$blocked, ...$this->waiting($blockingIds)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function due(): array
    {
        $until = self::endsOn()->endOfDay();
        $rows = collect();

        Inbox::quick($this->inbox->query(), 'unanswered')
            ->whereNotNull('first_response_due_at')
            ->where('first_response_due_at', '<=', $until)
            ->with(['requester', 'assignee'])
            ->get()
            ->each(fn (AiRequest $request) => $rows->push([$request->first_response_due_at->getTimestamp(), 0, $request->id, $this->item(
                id: 'fr-'.$request->id,
                column: 'due',
                companyId: (int) $request->company_id,
                refLabel: $request->key,
                title: 'Primera respuesta a '.$request->requester?->name,
                person: $request->assignee?->name ?? self::UNASSIGNED,
                dueAt: $request->first_response_due_at?->toIso8601String(),
                since: null,
                reason: null,
                target: ['type' => 'request', 'key' => $request->key],
            )]));

        self::openTasks()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', self::endsOn()->toDateString())
            ->with(['project', 'assignees'])
            ->get()
            ->each(fn (AiTask $task) => $rows->push([
                CarbonImmutable::parse($task->due_date->toDateString(), BusinessDays::TIMEZONE)->endOfDay()->getTimestamp(), 1, $task->id,
                $this->item(
                    id: 'task-'.$task->id,
                    column: 'due',
                    companyId: (int) $task->project?->company_id,
                    refLabel: $task->project?->key.' · '.$task->key,
                    title: $task->title,
                    person: self::people($task),
                    dueAt: $task->due_date->toDateString(),
                    since: null,
                    reason: null,
                    target: ['type' => 'task', 'project_key' => (string) $task->project?->key, 'task_key' => $task->key],
                ),
            ]));

        return self::sorted($rows);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<int>}
     */
    private function blocked(): array
    {
        $rows = collect();
        $blockingIds = [];

        self::openTasks()
            ->whereHas('blockingRequirements')
            ->with(['project', 'assignees', 'blockingRequirements'])
            ->get()
            ->each(function (AiTask $task) use ($rows, &$blockingIds): void {
                /** @var AiRequirement $requirement */
                $requirement = $task->blockingRequirements->first();
                $blockingIds = [...$blockingIds, ...$task->blockingRequirements->pluck('id')->all()];

                $rows->push([$requirement->created_at->getTimestamp(), 0, $task->id, $this->item(
                    id: 'blocked-'.$task->id,
                    column: 'blocked',
                    companyId: (int) $task->project?->company_id,
                    refLabel: $task->project?->key.' · '.$task->key,
                    title: $task->title,
                    person: self::people($task),
                    dueAt: null,
                    since: $requirement->created_at->toIso8601String(),
                    reason: "Espera el requerimiento “{$requirement->title}” a {$requirement->area_name}",
                    target: ['type' => 'requirement', 'id' => $requirement->id, 'area_name' => $requirement->area_name],
                )]);
            });

        return [self::sorted($rows), $blockingIds];
    }

    /**
     * @param  list<int>  $blockingIds  requirements already shown as blocking a task
     * @return list<array<string, mixed>>
     */
    private function waiting(array $blockingIds): array
    {
        $rows = collect();

        $requests = AiRequest::query()
            ->whereIn('status', [RequestStatus::NeedsInfo->value, RequestStatus::InTesting->value])
            ->with(['requester', 'criteria'])
            ->get();

        foreach ($requests as $request) {
            $since = $this->since($request);
            $title = $request->status === RequestStatus::NeedsInfo
                ? $this->questionTitle($request)
                : $this->validationTitle($request);

            $rows->push([$since->getTimestamp(), 0, $request->id, $this->item(
                id: 'wait-'.$request->id,
                column: 'waiting',
                companyId: (int) $request->company_id,
                refLabel: $request->key,
                title: $title,
                person: (string) $request->requester?->name,
                dueAt: null,
                since: $since->toIso8601String(),
                reason: null,
                target: ['type' => 'request', 'key' => $request->key],
            )]);
        }

        $approvals = AiRequest::query()
            ->where('status', RequestStatus::PendingApproval->value)
            ->with('approvals.approver')
            ->get();

        foreach ($approvals as $request) {
            $round = ApprovalRounds::current($request);
            $pending = $round->filter(fn (AiRequestApproval $row) => $row->decision === ApprovalDecision::Pending);
            $since = ($round->first()?->requested_at ?? $request->updated_at)->toImmutable();
            $names = $pending->map(fn (AiRequestApproval $row) => $row->approver?->name)->filter()->values()->all();

            $rows->push([$since->getTimestamp(), 1, $request->id, $this->item(
                id: 'approval-'.$request->id,
                column: 'waiting',
                companyId: (int) $request->company_id,
                refLabel: $request->key,
                title: "Aprobación: {$request->title}",
                person: $names === [] ? 'Aprobadores' : self::join($names),
                dueAt: null,
                since: $since->toIso8601String(),
                reason: null,
                target: ['type' => 'approval', 'key' => $request->key],
            )]);
        }

        AiRequirement::query()
            ->where('status', AiRequirement::PENDING)
            ->whereKeyNot($blockingIds)
            ->with('project')
            ->get()
            ->each(fn (AiRequirement $requirement) => $rows->push([$requirement->created_at->getTimestamp(), 2, $requirement->id, $this->item(
                id: 'requirement-'.$requirement->id,
                column: 'waiting',
                companyId: (int) $requirement->company_id,
                refLabel: (string) $requirement->project?->key,
                title: $requirement->title,
                person: $requirement->recipient_label,
                dueAt: null,
                since: $requirement->created_at->toIso8601String(),
                reason: null,
                target: ['type' => 'requirement', 'id' => $requirement->id, 'area_name' => $requirement->area_name],
            )]));

        return self::sorted($rows);
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function item(string $id, string $column, int $companyId, string $refLabel, string $title, string $person, ?string $dueAt, ?string $since, ?string $reason, array $target): array
    {
        return [
            'id' => $id,
            'column' => $column,
            'company_id' => $companyId,
            'ref_label' => $refLabel,
            'title' => $title,
            'person' => $person,
            'due_at' => $dueAt,
            'since' => $since,
            'reason' => $reason,
            'target' => $target,
        ];
    }

    /**
     * Rows `[timestamp, family, id, item]` by time, then family, then id.
     *
     * @param  Collection<int, array{0: int, 1: int, 2: int, 3: array<string, mixed>}>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sorted($rows): array
    {
        return $rows
            ->sort(fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]])
            ->map(fn (array $row) => $row[3])
            ->values()
            ->all();
    }

    private static function people(AiTask $task): string
    {
        $names = $task->assignees->map(fn (User $user) => $user->name)->values()->all();

        return $names === [] ? self::UNASSIGNED : self::join($names);
    }

    /**
     * When the request entered its current status.
     */
    private function since(AiRequest $request): CarbonImmutable
    {
        $change = $request->statusChanges()
            ->reorder()
            ->where('to_status', $request->status->value)
            ->latest('id')
            ->first();

        return ($change?->created_at ?? $request->updated_at ?? now())->toImmutable();
    }

    private function questionTitle(AiRequest $request): string
    {
        $question = $request->comments()
            ->where('visibility', CommentVisibility::Public->value)
            ->where('user_id', '!=', $request->requester_id)
            ->latest('id')
            ->value('body');

        return is_string($question) && $question !== '' ? "{$request->title}: {$question}" : $request->title;
    }

    private function validationTitle(AiRequest $request): string
    {
        $total = $request->criteria->count();
        $missing = $request->criteria->filter(fn ($criterion) => $criterion->result !== AcceptanceResult::Pass)->count();

        return "Validar {$request->title}".($total > 0 ? " (faltan {$missing} de {$total} criterios)" : '');
    }

    /**
     * @param  list<string>  $items
     */
    private static function join(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' y '.$last;
    }
}
