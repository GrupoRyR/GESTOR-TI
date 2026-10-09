<?php

namespace App\Actions\Ai;

use App\Domain\Ai\FirstResponse;
use App\Domain\Ai\RequestAttachments;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequestComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Public message in the conversation or internal note (`ai.manage` only).
 *
 * - A public reply by TI counts as first response (internal notes do not).
 * - The requester answering a request in "Necesita información" sends it
 *   back to "En evaluación" (design: "La evaluación sigue cuando respondas").
 */
class CommentOnRequest
{
    private const BODY_MAX = 5000;

    public function __construct(
        private readonly RequestAttachments $attachments,
        private readonly FirstResponse $firstResponse,
        private readonly TransitionRequest $transition,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function handle(AiRequest $request, User $author, string $body, CommentVisibility $visibility, array $files = []): AiRequestComment
    {
        Gate::forUser($author)->authorize($visibility === CommentVisibility::Internal ? 'commentInternal' : 'comment', $request);

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > self::BODY_MAX) {
            throw ValidationException::withMessages(['body' => 'Escribe un mensaje de hasta 5.000 caracteres.']);
        }

        $this->attachments->validate($files);

        return DB::transaction(function () use ($request, $author, $body, $visibility, $files): AiRequestComment {
            $comment = $request->comments()->create([
                'user_id' => $author->id,
                'body' => $body,
                'visibility' => $visibility,
            ]);

            foreach ($files as $file) {
                $this->attachments->store($request, $file, $author, $comment);
            }

            if ($visibility === CommentVisibility::Public) {
                $this->afterPublicReply($request, $author);
            }

            return $comment;
        });
    }

    private function afterPublicReply(AiRequest $request, User $author): void
    {
        if (Gate::forUser($author)->allows('evaluate', $request)) {
            if ($request->first_responded_at === null) {
                $this->firstResponse->mark($request);
                $request->save();
            }

            return;
        }

        if ((int) $request->requester_id === $author->id && $request->status === RequestStatus::NeedsInfo) {
            $this->transition->apply($request, RequestStatus::Evaluating, $author);
        }
    }
}
