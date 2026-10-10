<?php

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\AssistantEngine;
use App\Domain\Assistant\CredentialDelivery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\AskRequest;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Support\Assistant\ChatPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Asistente TI chat (`Assistant/Chat`, types/pages/assistant-chat.ts).
 *
 * - `index`: everyone in the module; without `assistant.use` the page shows
 *   the no-access state (`access_holders`). `?conversation=` must be one
 *   of the person's own (else 404); `?fuente=` fills `selected_source`.
 * - Writes need `assistant.use` (route middleware) and answer with a
 *   redirect to the conversation (normal Inertia POST, no streaming):
 *   `conversations.store` (reuses an empty one), `messages.store` (the
 *   question is stored before the AI is called; off → `errors.question`),
 *   `messages.retry` (only the last, retryable error; no new user bubble),
 *   `people.pick` (person choice of a password delivery, answered once).
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly ChatPage $page,
        private readonly AssistantEngine $engine,
        private readonly CredentialDelivery $delivery,
    ) {}

    public function index(Request $http): Response
    {
        $user = $this->user($http);
        $conversation = $http->has('conversation') ? $this->conversation($user, $http->query('conversation')) : null;

        return $this->page->render($http, $user, $conversation);
    }

    public function storeConversation(Request $http): RedirectResponse
    {
        $user = $this->user($http);

        $conversation = AssistantConversation::query()->ownedBy($user)->where('channel', 'app')->whereDoesntHave('messages')->latest('id')->first()
            ?? AssistantConversation::query()->create([
                'user_id' => $user->id,
                'channel' => 'app',
                'title' => AssistantConversation::DEFAULT_TITLE,
                'last_activity_at' => now(),
            ]);

        return $this->toConversation($conversation);
    }

    public function storeMessage(AskRequest $http): RedirectResponse
    {
        $user = $this->user($http);
        $question = trim((string) $http->validated('question'));
        $conversation = $http->query('conversation') !== null ? $this->conversation($user, $http->query('conversation')) : null;

        if (! AssistantEngine::isLearn($question)) {
            $reason = $this->engine->unavailableReason();
            if ($reason !== null) {
                throw ValidationException::withMessages(['question' => $reason]);
            }
        }

        $conversation ??= AssistantConversation::query()->create([
            'user_id' => $user->id,
            'channel' => 'app',
            'title' => AssistantConversation::titleFrom(AssistantEngine::isLearn($question) ? 'Aprender' : $question),
            'last_activity_at' => now(),
        ]);

        $this->engine->ask($user, $conversation, $question, 'app');

        return $this->toConversation($conversation);
    }

    public function retry(Request $http, AssistantMessage $message): RedirectResponse
    {
        $user = $this->user($http);
        $conversation = $this->conversation($user, $message->conversation_id);

        $last = $conversation->messages()->latest('id')->first();
        abort_unless($message->kind === 'error' && $message->data('retryable') === true && $last?->is($message), 404);

        $question = $conversation->messages()->where('role', 'user')->where('id', '<', $message->id)->latest('id')->first();
        abort_if($question === null, 404);

        $reason = $this->engine->unavailableReason();
        if ($reason !== null) {
            return $this->toConversation($conversation)->with('error', $reason);
        }

        $message->delete();
        $this->engine->answer($user, $conversation, $question, 'app');

        return $this->toConversation($conversation);
    }

    public function pick(Request $http, AssistantMessage $message): RedirectResponse
    {
        $user = $this->user($http);
        $conversation = $this->conversation($user, $message->conversation_id);
        abort_unless($message->kind === 'person_choice', 404);

        $option = $http->validate(['option' => ['present', 'nullable', 'integer']])['option'];

        if ($this->delivery->pick($user, $message, $option === null ? null : (int) $option) === null) {
            return $this->toConversation($conversation)->with('error', 'Esta pregunta ya se respondió.');
        }

        return $this->toConversation($conversation);
    }

    private function conversation(User $user, mixed $id): AssistantConversation
    {
        abort_unless(is_numeric($id), 404);

        return AssistantConversation::query()->ownedBy($user)->whereKey((int) $id)->firstOrFail();
    }

    private function toConversation(AssistantConversation $conversation): RedirectResponse
    {
        return redirect()->route('assistant.index', ['conversation' => $conversation->id]);
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
