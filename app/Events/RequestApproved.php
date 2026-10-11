<?php

namespace App\Events;

use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A request reached "Aprobada" (App\Actions\Ai\RecordApprovalDecision, web
 * or Telegram). Hook for B5: the project is created from it. Dispatched
 * after the decision's transaction commits.
 */
class RequestApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AiRequest $request,
        public readonly User $approver,
    ) {}
}
