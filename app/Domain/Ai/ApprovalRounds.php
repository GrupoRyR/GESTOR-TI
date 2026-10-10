<?php

namespace App\Domain\Ai;

use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Project approval rounds. A round is every project approval row asked at
 * the same moment (`requested_at`): SendToApproval opens one per send. The
 * current round is the latest one.
 *
 * - Pending for a viewer: the request is "Pendiente de aprobación", the
 *   viewer may decide it (`approveProject`, which also applies the
 *   "second approver if evaluator" setting) and has not decided in the
 *   current round.
 * - Decision shown to a viewer (`ApprovalDecision` of the design): their own
 *   decision in the current round, else the round's decision once the
 *   request left approval.
 */
final class ApprovalRounds
{
    /**
     * @return Collection<int, AiRequestApproval>
     */
    public static function current(AiRequest $request): Collection
    {
        $rows = $request->relationLoaded('approvals')
            ? $request->approvals->where('kind', ApprovalKind::Project)
            : $request->approvals()->where('kind', ApprovalKind::Project->value)->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $latest = $rows->max(fn (AiRequestApproval $row) => $row->requested_at?->getTimestamp() ?? 0);

        return $rows->filter(fn (AiRequestApproval $row) => ($row->requested_at?->getTimestamp() ?? 0) === $latest)
            ->sortBy('id')
            ->values();
    }

    /**
     * The decided row of the round (approved first), or null while pending.
     */
    public static function decided(Collection $round): ?AiRequestApproval
    {
        return $round->first(fn (AiRequestApproval $row) => $row->decision === ApprovalDecision::Approved)
            ?? $round->first(fn (AiRequestApproval $row) => $row->decision === ApprovalDecision::Rejected);
    }

    /**
     * A project re-approval (B6, App\Actions\Ai\RequestReapproval) is open:
     * the current round was asked again for a project already running
     * (`is_reapproval`) and nobody decided it yet. The request keeps its
     * status ("En desarrollo"…) meanwhile.
     */
    public static function reapprovalPending(AiRequest $request): bool
    {
        $round = self::current($request);

        return $request->status !== RequestStatus::PendingApproval
            && $round->isNotEmpty()
            && $round->every(fn (AiRequestApproval $row) => $row->is_reapproval)
            && self::decided($round) === null;
    }

    /**
     * The request waits for a project approval decision: "Pendiente de
     * aprobación", or an open project re-approval.
     */
    public static function awaiting(AiRequest $request): bool
    {
        return $request->status === RequestStatus::PendingApproval || self::reapprovalPending($request);
    }

    /**
     * Requests waiting for the viewer's decision (company scope applies),
     * newest request for approval first.
     *
     * @return Collection<int, AiRequest>
     */
    public function pendingFor(User $viewer): Collection
    {
        if (! $viewer->can('ai.projects.approve')) {
            return collect();
        }

        return AiRequest::query()
            ->where(fn ($query) => $query
                ->where('status', RequestStatus::PendingApproval->value)
                ->orWhereHas('approvals', fn ($approvals) => $approvals
                    ->where('kind', ApprovalKind::Project->value)
                    ->where('decision', ApprovalDecision::Pending->value)
                    ->where('is_reapproval', true)))
            ->with(['approvals', 'requester.employee', 'area', 'product'])
            ->get()
            ->filter(fn (AiRequest $request) => Gate::forUser($viewer)->allows('approveProject', $request)
                && ! self::current($request)->contains(fn (AiRequestApproval $row) => (int) $row->user_id === $viewer->id
                    && $row->decision !== ApprovalDecision::Pending))
            ->sortByDesc(fn (AiRequest $request) => self::current($request)->first()?->requested_at?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * @return array{result: 'approved'|'rejected', at: string, comment: ?string}|null
     */
    public function decisionFor(AiRequest $request, User $viewer): ?array
    {
        $round = self::current($request);
        $mine = $round->first(fn (AiRequestApproval $row) => (int) $row->user_id === $viewer->id && $row->decision !== ApprovalDecision::Pending);
        $row = $mine ?? (! self::awaiting($request) ? self::decided($round) : null);

        if ($row === null || $row->decision === ApprovalDecision::Pending) {
            return null;
        }

        return [
            'result' => $row->decision->value,
            'at' => ($row->decided_at ?? now())->toIso8601String(),
            'comment' => $row->comment,
        ];
    }
}
