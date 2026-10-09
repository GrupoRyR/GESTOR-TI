<?php

namespace App\Actions\Ai;

use App\Domain\Ai\FirstResponse;
use App\Domain\Ai\InvalidTransition;
use App\Domain\Ai\RequestStatusMachine;
use App\Enums\Ai\AreaApprovalStatus;
use App\Enums\Ai\RejectReason;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use App\Notifications\AiRequestStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Moves a request to another status: validates the move
 * (RequestStatusMachine), records the history row, stamps timestamps
 * (first response, closed_at), notifies the requester and logs activity.
 *
 * - `handle()`: a manual move by TI (`transition` ability). `approved` is
 *   never manual: only RecordApprovalDecision reaches it.
 * - `apply()`: the same move for other actions that already authorized the
 *   actor (approvals, the requester answering, evaluation).
 *
 * The note is public (timeline and requester notification); internal
 * remarks go to internal notes. The requester is not notified of moves
 * they made themselves.
 */
class TransitionRequest
{
    public function __construct(
        private readonly RequestStatusMachine $machine,
        private readonly FirstResponse $firstResponse,
    ) {}

    public function handle(AiRequest $request, RequestStatus $to, User $actor, ?string $note = null, ?RejectReason $reason = null): AiRequest
    {
        Gate::forUser($actor)->authorize('transition', $request);

        if ($this->machine->requiresApprovalDecision($to)) {
            throw InvalidTransition::between($request->key, $request->status, $to);
        }

        return $this->apply($request, $to, $actor, $note, $reason);
    }

    public function apply(AiRequest $request, RequestStatus $to, ?User $actor, ?string $note = null, ?RejectReason $reason = null): AiRequest
    {
        $from = $request->status;

        if (! $this->machine->allows($from, $to)) {
            throw InvalidTransition::between($request->key, $from, $to);
        }

        if ($from === RequestStatus::Received && $request->area_approval_status === AreaApprovalStatus::Pending && $to !== RequestStatus::Rejected) {
            throw InvalidTransition::awaitingAreaApproval($request->key);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($to === RequestStatus::Rejected && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Escoge el motivo del rechazo.']);
        }

        DB::transaction(function () use ($request, $from, $to, $actor, $note, $reason): void {
            $request->status = $to;

            if ($to === RequestStatus::Rejected) {
                $request->rejection_reason = $reason;
                $request->rejection_message = $note;
            }

            if ($to->isFinished()) {
                $request->closed_at = now();
            } elseif ($from->isFinished()) {
                // Reopened.
                $request->closed_at = null;
                $request->rejection_reason = null;
                $request->rejection_message = null;
            }

            if ($actor !== null && $actor->can('ai.manage')) {
                $this->firstResponse->mark($request);
            }

            $request->save();

            $request->statusChanges()->create([
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => $actor?->id,
                'note' => $note,
            ]);

            activity('ai')
                ->performedOn($request)
                ->causedBy($actor)
                ->event('ai_request_status_changed')
                ->withProperties(['key' => $request->key, 'from' => $from->value, 'to' => $to->value])
                ->log("{$request->key}: {$from->label()} → {$to->label()}");
        });

        $requester = $request->requester;

        if ($requester !== null && $requester->id !== $actor?->id) {
            $requester->notify(AiRequestStatusChanged::for($request, $note));
        }

        return $request;
    }
}
