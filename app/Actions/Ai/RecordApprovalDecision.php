<?php

namespace App\Actions\Ai;

use App\Enums\Ai\ApprovalChannel;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use App\Enums\Ai\AreaApprovalStatus;
use App\Enums\Ai\RejectReason;
use App\Enums\Ai\RequestStatus;
use App\Events\RequestApproved;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Records an approver's decision (web or Telegram ✅/❌), with date and
 * comment. A rejection needs a comment.
 *
 * - Area (`approveArea`: the area approver of the request's area): approved
 *   → the request reaches TI; rejected → the request is rejected.
 * - Project (`approveProject`: `ai.projects.approve`, never inherited by
 *   Súper Admin; optionally not the evaluator): approved → "Aprobada" as
 *   soon as one approver approves, or once every asked approver approved
 *   when the request was sent with `require_all`; rejected → "Rechazada".
 *   The estimate snapshot of the pending row stays with the decision.
 *   Reaching "Aprobada" dispatches App\Events\RequestApproved (B5 hook).
 *   A project re-approval (`is_reapproval` while the request is past
 *   approval) goes to App\Actions\Ai\DecideReapproval instead.
 *
 * Each approver decides once.
 */
class RecordApprovalDecision
{
    public function __construct(
        private readonly TransitionRequest $transition,
        private readonly DecideReapproval $reapprovals,
    ) {}

    public function handle(
        AiRequest $request,
        User $approver,
        ApprovalKind $kind,
        ApprovalDecision $decision,
        ?string $comment = null,
        ApprovalChannel $channel = ApprovalChannel::Web,
    ): AiRequestApproval {
        if ($decision === ApprovalDecision::Pending) {
            throw new InvalidArgumentException('A decision must be approved or rejected.');
        }

        Gate::forUser($approver)->authorize($kind === ApprovalKind::Area ? 'approveArea' : 'approveProject', $request);

        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        if ($decision === ApprovalDecision::Rejected && $comment === null) {
            throw ValidationException::withMessages(['comment' => 'Cuéntale al equipo por qué la rechazas.']);
        }

        return DB::transaction(function () use ($request, $approver, $kind, $decision, $comment, $channel): AiRequestApproval {
            $approval = $this->row($request, $approver, $kind);

            $approval->fill([
                'decision' => $decision,
                'comment' => $comment,
                'channel' => $channel,
                'decided_at' => now(),
            ])->save();

            $kind === ApprovalKind::Area
                ? $this->afterAreaDecision($request, $approver, $decision, $comment)
                : $this->afterProjectDecision($request, $approver, $approval, $decision, $comment);

            return $approval;
        });
    }

    /**
     * The approver's pending row (created when missing, copying the
     * estimate snapshot of the request's pending project rows).
     */
    private function row(AiRequest $request, User $approver, ApprovalKind $kind): AiRequestApproval
    {
        $rows = $request->approvals()->where('kind', $kind->value)->lockForUpdate()->get();
        $mine = $rows->first(fn (AiRequestApproval $row) => (int) $row->user_id === $approver->id && $row->decision === ApprovalDecision::Pending);

        if ($mine !== null) {
            return $mine;
        }

        // Current round = since the latest time approval was requested; an
        // approver who already decided in it cannot decide again.
        $round = $rows->max('requested_at');
        $alreadyDecided = $rows->contains(fn (AiRequestApproval $row) => (int) $row->user_id === $approver->id
            && $row->decision !== ApprovalDecision::Pending
            && ($round === null || $row->decided_at?->gte($round)));

        if ($alreadyDecided) {
            throw ValidationException::withMessages(['decision' => 'Ya registraste tu decisión.']);
        }

        $template = $rows->first(fn (AiRequestApproval $row) => $row->decision === ApprovalDecision::Pending);

        return $request->approvals()->make([
            'kind' => $kind,
            'user_id' => $approver->id,
            'estimate' => $template?->estimate,
            'require_all' => $template?->require_all ?? false,
            'is_reapproval' => $template?->is_reapproval ?? false,
            'requested_by_id' => $template?->requested_by_id,
            'requested_at' => $template?->requested_at,
        ]);
    }

    private function afterAreaDecision(AiRequest $request, User $approver, ApprovalDecision $decision, ?string $comment): void
    {
        if ($decision === ApprovalDecision::Approved) {
            $request->forceFill(['area_approval_status' => AreaApprovalStatus::Approved])->save();

            return;
        }

        $request->forceFill(['area_approval_status' => AreaApprovalStatus::Rejected])->save();
        $this->transition->apply($request, RequestStatus::Rejected, $approver, $comment, RejectReason::Other);
    }

    private function afterProjectDecision(AiRequest $request, User $approver, AiRequestApproval $approval, ApprovalDecision $decision, ?string $comment): void
    {
        // A project re-approval (B6) never moves the request: the project decides.
        if ($approval->is_reapproval && $request->status !== RequestStatus::PendingApproval) {
            $this->reapprovals->handle($request, $approver, $approval, $decision, $comment);

            return;
        }

        if ($decision === ApprovalDecision::Rejected) {
            $this->transition->apply($request, RequestStatus::Rejected, $approver, $comment, RejectReason::Other);

            return;
        }

        $waiting = $approval->require_all
            && $request->approvals()
                ->where('kind', ApprovalKind::Project->value)
                ->pending()
                ->exists();

        if (! $waiting) {
            $this->transition->apply($request, RequestStatus::Approved, $approver, $comment);

            // B5 creates the project from it (after the commit).
            RequestApproved::dispatch($request, $approver);
        }
    }
}
