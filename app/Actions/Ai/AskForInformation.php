<?php

namespace App\Actions\Ai;

use App\Domain\Ai\InvalidTransition;
use App\Domain\Ai\RequestStatusMachine;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use App\Notifications\AiRequestReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Pedir información" (AskInfoDialog, also from the bulk bar): the question
 * goes to the public conversation and the request moves to "Necesita
 * información" (the requester is notified with the question; counts as
 * first response). When it already waits on the requester, the question is
 * added and the requester is reminded.
 */
class AskForInformation
{
    public const QUESTION_MAX = 2000;

    public function __construct(
        private readonly CommentOnRequest $comments,
        private readonly TransitionRequest $transition,
        private readonly RequestStatusMachine $machine,
    ) {}

    public function handle(AiRequest $request, User $actor, string $question): AiRequest
    {
        Gate::forUser($actor)->authorize('evaluate', $request);

        $question = trim($question);

        if ($question === '' || mb_strlen($question) > self::QUESTION_MAX) {
            throw ValidationException::withMessages(['question' => 'Escribe la pregunta (hasta 2.000 caracteres).']);
        }

        if ($request->status === RequestStatus::NeedsInfo) {
            $this->comments->handle($request, $actor, $question, CommentVisibility::Public);
            $request->requester?->notify(AiRequestReminder::question($request, $question));

            return $request;
        }

        if (! $this->machine->allows($request->status, RequestStatus::NeedsInfo)) {
            throw InvalidTransition::between($request->key, $request->status, RequestStatus::NeedsInfo);
        }

        DB::transaction(function () use ($request, $actor, $question): void {
            $this->transition->apply($request, RequestStatus::NeedsInfo, $actor, $question);
            $this->comments->handle($request, $actor, $question, CommentVisibility::Public);
        });

        return $request;
    }
}
