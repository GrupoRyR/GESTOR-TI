<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\CommentOnRequest;
use App\Actions\Ai\VoteRequest;
use App\Enums\Ai\CommentVisibility;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\AiRequestAttachment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a requester (or a colleague from the roadmap) does on a request:
 * "+1" (`ai.requests.vote` POST/DELETE), "Seguir" (`ai.requests.follow`
 * POST/DELETE), a public message (`ai.requests.comments.store`) and file
 * downloads (`ai.requests.attachments.show`, signed links only).
 */
class RequestInteractionController extends Controller
{
    public function vote(Request $http, AiRequest $request, VoteRequest $votes): RedirectResponse
    {
        $votes->vote($request, $http->user());

        return back();
    }

    public function unvote(Request $http, AiRequest $request, VoteRequest $votes): RedirectResponse
    {
        Gate::authorize('view', $request);

        $votes->unvote($request, $http->user());

        return back();
    }

    public function follow(Request $http, AiRequest $request): RedirectResponse
    {
        Gate::authorize('follow', $request);

        $request->followers()->syncWithoutDetaching([$http->user()->id]);

        return back();
    }

    public function unfollow(Request $http, AiRequest $request): RedirectResponse
    {
        $request->followers()->detach($http->user()->id);

        return back();
    }

    /**
     * "Enviar" in Conversación: multipart `{ body, attachments[] }`.
     */
    public function comment(Request $http, AiRequest $request, CommentOnRequest $comments): RedirectResponse
    {
        /** @var User $user */
        $user = $http->user();

        $comments->handle(
            $request,
            $user,
            (string) $http->input('body', ''),
            CommentVisibility::Public,
            array_values(array_filter((array) $http->file('attachments', []))),
        );

        return back();
    }

    /**
     * Private file download. Files of internal notes need `viewInternal`.
     */
    public function attachment(AiRequest $request, AiRequestAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $request);

        if ($attachment->comment?->isInternal()) {
            Gate::authorize('viewInternal', $request);
        }

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }
}
