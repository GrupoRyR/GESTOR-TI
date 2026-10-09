<?php

namespace App\Domain\Ai;

use App\Enums\Ai\AcceptanceResult;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use Carbon\CarbonImmutable;

/**
 * "Mi semana" (PROMPT 6.4.3, WeekIndexProps) for the IT team, in the
 * current company context. Columns:
 *
 * - `due`: first responses due by the end of the week (overdue ones
 *   included), soonest first; the person is the assignee ("TI" when
 *   nobody is assigned). TODO B5: tasks and project replanning.
 * - `blocked`: TODO B5 (tasks blocked by requirements).
 * - `waiting`: what waits on somebody else, oldest first — a question to
 *   the requester ("Necesita información", title + the open question), a
 *   validation of the requester ("En pruebas") and project approvals (the
 *   approvers who have not decided). TODO B5: requirements to users.
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
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return [...$this->due(), ...$this->waiting()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function due(): array
    {
        $until = self::endsOn()->endOfDay();

        return Inbox::quick($this->inbox->query(), 'unanswered')
            ->whereNotNull('first_response_due_at')
            ->where('first_response_due_at', '<=', $until)
            ->with(['requester', 'assignee'])
            ->orderBy('first_response_due_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AiRequest $request) => $this->item(
                id: 'fr-'.$request->id,
                column: 'due',
                request: $request,
                title: 'Primera respuesta a '.$request->requester?->name,
                person: $request->assignee?->name ?? self::UNASSIGNED,
                dueAt: $request->first_response_due_at?->toIso8601String(),
                since: null,
                target: ['type' => 'request', 'key' => $request->key],
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function waiting(): array
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

            $rows->push([$since, 0, $request->id, $this->item(
                id: 'wait-'.$request->id,
                column: 'waiting',
                request: $request,
                title: $title,
                person: (string) $request->requester?->name,
                dueAt: null,
                since: $since->toIso8601String(),
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

            $rows->push([$since, 1, $request->id, $this->item(
                id: 'approval-'.$request->id,
                column: 'waiting',
                request: $request,
                title: "Aprobación: {$request->title}",
                person: $names === [] ? 'Aprobadores' : self::join($names),
                dueAt: null,
                since: $since->toIso8601String(),
                target: ['type' => 'approval', 'key' => $request->key],
            )]);
        }

        return $rows
            ->sort(fn (array $a, array $b) => [$a[0]->getTimestamp(), $a[1], $a[2]] <=> [$b[0]->getTimestamp(), $b[1], $b[2]])
            ->map(fn (array $row) => $row[3])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function item(string $id, string $column, AiRequest $request, string $title, string $person, ?string $dueAt, ?string $since, array $target): array
    {
        return [
            'id' => $id,
            'column' => $column,
            'company_id' => (int) $request->company_id,
            'ref_label' => $request->key,
            'title' => $title,
            'person' => $person,
            'due_at' => $dueAt,
            'since' => $since,
            'reason' => null,
            'target' => $target,
        ];
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
