<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\RateRequest;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One-click rating from the "Ya está en producción" email (`delivered`,
 * `ai.ratings.quick`). The link is signed and expires (route middleware
 * `signed`), names the requester (`user`) and the score (1–5 = "solved
 * need"), and works without signing in.
 *
 * - The named user must be the requester of the request (else 403).
 * - Idempotent: the first click saves the rating through RateRequest
 *   (`rate` policy: requester, in production, not rated yet); later clicks
 *   change nothing.
 * - Then it goes to the request, where they can leave a comment.
 */
class QuickRatingController extends Controller
{
    public function __invoke(Request $http, string $request, int $score, RateRequest $rate): RedirectResponse
    {
        $aiRequest = AiRequest::withoutGlobalScopes()->where('key', $request)->firstOrFail();
        $user = User::query()->find($http->query('user'));

        abort_if($user === null || ! $user->isActive() || (int) $aiRequest->requester_id !== $user->id, 403);
        abort_if($score < 1 || $score > 5, 404);

        $target = redirect()->route('ai.requests.show', $aiRequest->key);

        if ($aiRequest->ratings()->where('user_id', $user->id)->exists()) {
            return $target->with('success', 'Ya tenemos tu calificación. ¡Gracias!');
        }

        if (Gate::forUser($user)->denies('rate', $aiRequest)) {
            return $target->with('error', 'Esta entrega ya no espera calificación.');
        }

        try {
            $rate->rate($aiRequest, $user, $score, null, null);
        } catch (UniqueConstraintViolationException) {
            // A second click raced the first one: the rating is already saved.
        }

        return $target->with('success', '¡Gracias por calificar! Si quieres, déjanos un comentario en la conversación.');
    }
}
