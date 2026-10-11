<?php

namespace App\Telegram\Assistant;

use App\Domain\Assistant\AssistantEngine;
use App\Http\Requests\Assistant\AskRequest;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Telegram\TelegramHtml;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The Asistente TI on Telegram (PROMPT 6.8.2, 6.8.3; telegram-format.md):
 * a plain-text message of a linked private chat is a question.
 *
 * - Needs `assistant.use` (else NO_ACCESS) and at most
 *   `gestor.telegram.assistant_per_minute` questions per chat (else
 *   RATE_LIMITED); 500 characters like the app chat.
 * - Off (no key, monthly cap): "no disponible", nothing stored, no typing.
 * - Runs as the linked user (company scope of Gestor IA data), on the
 *   telegram conversation of that chat that had activity in the last
 *   30 minutes (else a new one), with "escribiendo…" meanwhile.
 * - Replies per App\Telegram\Assistant\ReplyFormatter; credential notices
 *   go through App\Telegram\Assistant\CredentialSender.
 * - Any failure still answers "no disponible" (and is logged).
 */
final class TelegramAssistant
{
    public const NO_ACCESS = 'No tienes acceso al Asistente TI. Si lo necesitas, pídelo a TI.';

    public const RATE_LIMITED = 'Vas muy rápido. Espera un minuto y vuelve a preguntar.';

    public const PERMISSION = 'assistant.use';

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TypingIndicator $typing,
        private readonly ReplyFormatter $formatter,
        private readonly CredentialSender $credentials,
    ) {}

    public function ask(User $user, string $chatId, string $question): void
    {
        if (! $user->can(self::PERMISSION)) {
            $this->send($chatId, self::NO_ACCESS);

            return;
        }

        if (! self::attempt($chatId)) {
            $this->send($chatId, self::RATE_LIMITED);

            return;
        }

        if (mb_strlen($question) > AskRequest::MAX_LENGTH) {
            $this->send($chatId, 'Tu pregunta es muy larga: máximo '.AskRequest::MAX_LENGTH.' caracteres.');

            return;
        }

        try {
            self::actingAs($user, function () use ($user, $chatId, $question): void {
                $engine = app(AssistantEngine::class);

                if ($engine->unavailableReason() !== null) {
                    $this->send($chatId, AssistantEngine::UNAVAILABLE);

                    return;
                }

                $this->typing->start($chatId);
                try {
                    $reply = $engine->ask($user, $this->conversation($user, $chatId), $question, 'telegram');
                } finally {
                    $this->typing->stop();
                }

                $this->reply($user, $chatId, $reply);
            });
        } catch (Throwable $error) {
            Log::error('Telegram: el Asistente TI falló al responder.', ['user_id' => $user->id, 'error' => class_basename($error)]);
            $this->send($chatId, AssistantEngine::UNAVAILABLE);
        }
    }

    /**
     * Sends one assistant message (answer, refusal, choice, vault link,
     * credential notice…) to the chat.
     */
    public function reply(User $user, string $chatId, AssistantMessage $message): void
    {
        if ($message->kind === 'credential_notice') {
            $this->credentials->deliver($user, $chatId, $message);

            return;
        }

        ['text' => $text, 'keyboard' => $keyboard] = $this->formatter->format($user, $message, $chatId);

        $this->client->sendMessage($chatId, $text, $keyboard, TelegramHtml::OPTIONS);
    }

    /**
     * One more assistant request of the chat (questions and `/aprender`).
     */
    public static function attempt(string $chatId): bool
    {
        return RateLimiter::attempt('telegram-assistant:'.$chatId, max(1, (int) config('gestor.telegram.assistant_per_minute', 20)), fn () => true, 60);
    }

    /**
     * Runs the callback authenticated as the linked user (company context
     * of the scopes), restoring the previous state afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function actingAs(User $user, Closure $callback): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->user();
        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                $guard->setUser($previous);
            } else {
                $guard->forgetUser();
            }
        }
    }

    private function conversation(User $user, string $chatId): AssistantConversation
    {
        $since = now()->subMinutes((int) config('gestor.assistant.context_minutes', 30));

        return AssistantConversation::query()
            ->ownedBy($user)
            ->where('channel', 'telegram')
            ->where('telegram_chat_id', $chatId)
            ->where('last_activity_at', '>=', $since)
            ->latest('id')
            ->first()
            ?? AssistantConversation::query()->create([
                'user_id' => $user->id,
                'channel' => 'telegram',
                'telegram_chat_id' => $chatId,
                'title' => AssistantConversation::DEFAULT_TITLE,
                'last_activity_at' => now(),
            ]);
    }

    private function send(string $chatId, string $text): void
    {
        $this->client->sendMessage($chatId, TelegramHtml::escape($text), [], TelegramHtml::OPTIONS);
    }
}
