<?php

namespace App\Telegram\Handlers;

use App\Domain\Assistant\CredentialDelivery;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Telegram\Assistant\TelegramAssistant;
use App\Telegram\Contracts\CallbackHandler;
use App\Telegram\SignedCallback;
use App\Telegram\TelegramHtml;
use Illuminate\Support\Facades\Log;

/**
 * Person choice of a password delivery on Telegram (prefix `cred`,
 * telegram-format.md §4.1). Data `cred:pick:{choice message}:{option}:{signature}`
 * (App\Telegram\SignedCallback, bound to the chat): only option indexes,
 * never names.
 *
 * The tap re-checks `assistant.credentials` + `credentials.view`, that the
 * choice belongs to a telegram conversation of that person in that chat
 * and that it is still fresh (`gestor.assistant.credentials.reveal_minutes`).
 * It is answered once (App\Domain\Assistant\CredentialDelivery::pick());
 * the chosen credential is delivered like a direct match.
 */
class CredentialCallbackHandler implements CallbackHandler
{
    public const PREFIX = 'cred';

    public const ANSWERED = 'Esta pregunta ya se respondió.';

    public function __construct(
        private readonly TelegramClient $client,
        private readonly CredentialDelivery $delivery,
        private readonly TelegramAssistant $assistant,
    ) {}

    public function handle(User $user, string $payload, array $callbackQuery): ?string
    {
        $chatId = SignedCallback::chatOf($callbackQuery);
        $data = SignedCallback::verify(self::PREFIX, $payload, $chatId);
        $parts = $data === null ? [] : explode(':', $data);

        if (count($parts) !== 3 || $parts[0] !== 'pick' || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            Log::warning('Telegram: botón de contraseña con datos inválidos.', ['user_id' => $user->id]);

            return TelegramHtml::UNAVAILABLE_ACTION;
        }

        if (! CredentialDelivery::allowed($user)) {
            return CredentialDelivery::REFUSED;
        }

        $choice = $this->choice($user, $chatId, (int) $parts[1]);
        $option = $choice?->data('options.'.$parts[2]);

        if ($choice === null || ! is_array($option)) {
            return TelegramHtml::UNAVAILABLE_ACTION;
        }

        $result = TelegramAssistant::actingAs($user, fn () => $this->delivery->pick($user, $choice, (int) ($option['employee_id'] ?? 0)));

        if ($result === null) {
            return self::ANSWERED;
        }

        // The choice is answered: its buttons go away.
        $messageId = $callbackQuery['message']['message_id'] ?? null;
        if (is_int($messageId)) {
            $this->client->editMessageText($chatId, $messageId, TelegramHtml::escape((string) $choice->body), [], TelegramHtml::OPTIONS);
        }

        $this->assistant->reply($user, $chatId, $result);

        return null;
    }

    private function choice(User $user, string $chatId, int $messageId): ?AssistantMessage
    {
        $choice = AssistantMessage::query()
            ->whereKey($messageId)
            ->where('kind', 'person_choice')
            ->whereHas('conversation', fn ($query) => $query->ownedBy($user)->where('channel', 'telegram')->where('telegram_chat_id', $chatId))
            ->first();

        $minutes = (int) config('gestor.assistant.credentials.reveal_minutes', 10);

        return $choice !== null && $choice->created_at->addMinutes($minutes)->isFuture() ? $choice : null;
    }
}
