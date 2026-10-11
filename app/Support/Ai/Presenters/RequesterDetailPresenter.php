<?php

namespace App\Support\Ai\Presenters;

use App\Domain\Ai\ApprovalRounds;
use App\Enums\Ai\AcceptanceResult;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\AiRequestAttachment;
use App\Models\AiRequestComment;
use App\Models\AiRequestStatusChange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * `RequesterRequestDetail` (types/pages/ai-requester.ts): RequestDetail
 * without `internal_notes`, and an estimate reduced to due date + total
 * hours. Only public comments and wizard files (never files of internal
 * notes); costs, rates and hours per person are never read.
 */
final class RequesterDetailPresenter
{
    /** Minutes a signed download link stays valid. */
    private const LINK_MINUTES = 30;

    /**
     * @return array<string, mixed>
     */
    public static function detail(AiRequest $request, ?int $rank): array
    {
        $request->loadMissing([
            ...RequestPresenter::RELATIONS,
            'criteria.validatedBy',
            'statusChanges.user',
            'approvals.approver.employee',
            'approvals.requestedBy.employee',
        ]);

        return [
            ...RequestPresenter::summary($request, $rank),
            'answers' => [
                'problem' => $request->problem,
                'current_process' => $request->current_process,
                'expected_result' => (string) $request->expected_result,
                'success_criteria' => $request->success_criteria,
                'systems' => array_values((array) $request->systems),
                'systems_notes' => $request->systems_notes,
                'deadline' => $request->deadline?->toDateString(),
                'deadline_reason' => $request->deadline_reason,
            ],
            'attachments' => self::attachments($request),
            'criteria' => $request->criteria->map(fn (AcceptanceCriterion $criterion) => [
                'id' => $criterion->id,
                'text' => $criterion->text,
                'validated' => $criterion->result === AcceptanceResult::Pass && $criterion->validated_at !== null
                    ? ['by' => (string) $criterion->validatedBy?->name, 'at' => $criterion->validated_at->toIso8601String()]
                    : null,
                'note' => $criterion->note,
            ])->values()->all(),
            'timeline' => self::timeline($request),
            'comments' => self::comments($request),
            'estimate' => self::estimate($request),
            'approval' => self::approval($request),
            'rating' => self::rating($request),
            'followers_count' => $request->followers()->count(),
        ];
    }

    /**
     * Signed, authorized download link of a request file.
     */
    public static function fileUrl(AiRequest $request, AiRequestAttachment $attachment): string
    {
        return URL::temporarySignedRoute('ai.requests.attachments.show', now()->addMinutes(self::LINK_MINUTES), [
            'request' => $request->key,
            'attachment' => $attachment->id,
        ]);
    }

    /**
     * Wizard files only: conversation files and criterion photos hang from
     * comments or criteria and internal-note files must never leak.
     *
     * @return list<array<string, mixed>>
     */
    public static function attachments(AiRequest $request): array
    {
        $photoIds = $request->criteria->pluck('attachment_id')->filter()->all();

        return $request->attachments()
            ->whereNull('ai_request_comment_id')
            ->whereNotIn('id', $photoIds)
            ->orderBy('id')
            ->get()
            ->map(fn (AiRequestAttachment $attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'size' => $attachment->size,
                'mime' => $attachment->mime,
                'url' => self::fileUrl($request, $attachment),
            ])
            ->values()
            ->all();
    }

    /**
     * Status history, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private static function timeline(AiRequest $request): array
    {
        return $request->statusChanges
            ->sortByDesc('id')
            ->map(fn (AiRequestStatusChange $change) => [
                'id' => $change->id,
                'kind' => match ($change->to_status) {
                    RequestStatus::Approved => 'approval',
                    RequestStatus::Rejected => 'rejection',
                    default => 'status',
                },
                'family' => self::family($change->to_status),
                'title' => $change->to_status->label(),
                'quote' => $change->note,
                'detail' => null,
                'actor' => $change->user?->name,
                'at' => ($change->created_at ?? now())->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Status families of resources/js/lib/status.ts.
     */
    public static function family(RequestStatus $status): string
    {
        return match ($status) {
            RequestStatus::Received, RequestStatus::Evaluating => 'gray',
            RequestStatus::NeedsInfo, RequestStatus::PendingApproval, RequestStatus::InTesting => 'amber',
            RequestStatus::Approved, RequestStatus::InProduction => 'green',
            RequestStatus::Backlog, RequestStatus::Closed => 'slate',
            RequestStatus::Rejected => 'red',
            RequestStatus::Planned, RequestStatus::InDevelopment => 'blue',
        };
    }

    /**
     * Public conversation, oldest first; "TI" badge for `ai.manage` authors.
     *
     * @return list<array<string, mixed>>
     */
    public static function comments(AiRequest $request): array
    {
        return $request->comments()
            ->where('visibility', CommentVisibility::Public->value)
            ->with('author.employee')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (AiRequestComment $comment) => $comment->author !== null)
            ->map(fn (AiRequestComment $comment) => [
                'id' => $comment->id,
                'author' => RequestPresenter::person($comment->author),
                'is_it' => $comment->author->can('ai.manage'),
                'body' => $comment->body,
                'created_at' => $comment->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Rows of the latest project approval round.
     *
     * @return Collection<int, AiRequestApproval>
     */
    private static function projectRound(AiRequest $request): Collection
    {
        return ApprovalRounds::current($request);
    }

    /**
     * Due date and total hours of the estimate sent to approval (the
     * snapshot on the approval row); null before it.
     *
     * @return array{due_date: string, hours_total: int|float}|null
     */
    private static function estimate(AiRequest $request): ?array
    {
        $estimate = self::projectRound($request)->first()?->estimate;

        if (! is_array($estimate) || ! isset($estimate['due_date'], $estimate['hours_total'])) {
            return null;
        }

        return [
            'due_date' => (string) $estimate['due_date'],
            'hours_total' => RequestPresenter::number($estimate['hours_total']) ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function approval(AiRequest $request): ?array
    {
        $round = self::projectRound($request);

        if ($round->isEmpty()) {
            return null;
        }

        $decided = ApprovalRounds::decided($round);
        $first = $round->first();
        $requestedBy = $first->requestedBy ?? $request->requester;

        return [
            'decision' => ($decided?->decision ?? ApprovalDecision::Pending)->value,
            'approvers' => $round->map(fn (AiRequestApproval $row) => $row->approver)
                ->filter()
                ->map(fn ($user) => RequestPresenter::person($user))
                ->values()
                ->all(),
            'decided_by' => $decided?->approver !== null ? RequestPresenter::person($decided->approver) : null,
            'decided_at' => $decided?->decided_at?->toIso8601String(),
            'comment' => $decided?->comment,
            'requested_by' => RequestPresenter::person($requestedBy),
            'requested_at' => ($first->requested_at ?? $first->created_at ?? now())->toIso8601String(),
            'is_reapproval' => (bool) $first->is_reapproval,
        ];
    }

    /**
     * The requester's rating and 30-day answer (`RequestRating`).
     *
     * @return array<string, mixed>|null
     */
    public static function rating(AiRequest $request): ?array
    {
        $rating = $request->ratings()->where('user_id', $request->requester_id)->first();

        if ($rating === null) {
            return null;
        }

        $hours = $request->hoursSavedReports()->where('user_id', $request->requester_id)->value('hours_per_week');

        return [
            'solved' => $rating->solved_need,
            'ease' => $rating->ease_of_use,
            'comment' => $rating->comment,
            'hours_saved_per_week' => RequestPresenter::number($hours),
            'rated_at' => $rating->rated_at->toIso8601String(),
        ];
    }
}
