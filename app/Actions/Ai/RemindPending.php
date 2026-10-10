<?php

namespace App\Actions\Ai;

use App\Domain\Ai\ApprovalRounds;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\User;
use App\Notifications\AiRequestReminder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Recordar" of Mi semana:
 *
 * - requester(): what waits on the requester — the open question while
 *   "Necesita información" or the validation while "En pruebas".
 * - approvers(): the approvers of the current round who have not decided
 *   while "Pendiente de aprobación" (same notice as the first one, with the
 *   ✅/❌ buttons).
 *
 * Both need `evaluate` (IT) and leave a `notification` event on the IT
 * timeline.
 */
class RemindPending
{
    public function __construct(private readonly SendToApproval $sendToApproval) {}

    public function requester(AiRequest $request, User $actor): void
    {
        Gate::forUser($actor)->authorize('evaluate', $request);

        $requester = $request->requester;

        $notification = match ($request->status) {
            RequestStatus::NeedsInfo => AiRequestReminder::question($request, ...$this->openQuestion($request)),
            RequestStatus::InTesting => AiRequestReminder::validation($request),
            default => null,
        };

        if ($notification === null || $requester === null) {
            throw ValidationException::withMessages(['request' => "Nada de {$request->key} espera respuesta de quien la pidió."]);
        }

        $requester->notify($notification);
        $request->recordEvent('notification', 'amber', "Recordatorio enviado a {$requester->name}", $actor);
    }

    /**
     * @return int approvers reminded
     */
    public function approvers(AiRequest $request, User $actor): int
    {
        Gate::forUser($actor)->authorize('evaluate', $request);

        if ($request->status !== RequestStatus::PendingApproval) {
            throw ValidationException::withMessages(['request' => "{$request->key} no está esperando aprobación."]);
        }

        $rows = ApprovalRounds::current($request)
            ->filter(fn (AiRequestApproval $row) => $row->decision === ApprovalDecision::Pending)
            ->each(fn (AiRequestApproval $row) => $row->loadMissing('approver'))
            ->values();

        $this->sendToApproval->notify($request, $rows, reminder: true);

        return $rows->count();
    }

    /**
     * TI's latest public message and who wrote it.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function openQuestion(AiRequest $request): array
    {
        $comment = $request->comments()
            ->with('author')
            ->where('visibility', CommentVisibility::Public->value)
            ->where('user_id', '!=', $request->requester_id)
            ->latest('id')
            ->first();

        return [is_string($comment?->body) ? $comment->body : null, $comment?->author?->name];
    }
}
