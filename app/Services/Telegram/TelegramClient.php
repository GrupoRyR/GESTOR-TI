<?php

namespace App\Services\Telegram;

use App\Jobs\DeleteTelegramMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal Telegram Bot API client (`https://api.telegram.org/bot{token}/…`)
 * on Laravel's HTTP client, 10 s timeout.
 *
 * Never throws: without `TELEGRAM_BOT_TOKEN` every call is a logged no-op,
 * and API or connection errors are logged and return null/false. The token
 * is part of the URL, so URLs are never logged.
 */
class TelegramClient
{
    private const BASE_URL = 'https://api.telegram.org';

    private const TIMEOUT_SECONDS = 10;

    public function isConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /**
     * @param  list<list<array<string, string>>>  $inlineKeyboard  rows of InlineKeyboardButton
     * @param  array<string, mixed>  $options  extra sendMessage fields (parse_mode, …)
     * @return ?array<string, mixed> the sent Message
     */
    public function sendMessage(string|int $chatId, string $text, array $inlineKeyboard = [], array $options = []): ?array
    {
        $payload = ['chat_id' => (string) $chatId, 'text' => $text] + $options;

        if ($inlineKeyboard !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        }

        $result = $this->call('sendMessage', $payload);

        return is_array($result) ? $result : null;
    }

    /**
     * @param  list<list<array<string, string>>>  $inlineKeyboard
     */
    public function editMessageText(string|int $chatId, int $messageId, string $text, array $inlineKeyboard = []): bool
    {
        $payload = ['chat_id' => (string) $chatId, 'message_id' => $messageId, 'text' => $text];

        if ($inlineKeyboard !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        }

        return (bool) $this->call('editMessageText', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): bool
    {
        $payload = ['callback_query_id' => $callbackQueryId];

        if ($text !== null && $text !== '') {
            $payload['text'] = $text;
            $payload['show_alert'] = $showAlert;
        }

        return (bool) $this->call('answerCallbackQuery', $payload);
    }

    public function sendChatAction(string|int $chatId, string $action = 'typing'): bool
    {
        return (bool) $this->call('sendChatAction', ['chat_id' => (string) $chatId, 'action' => $action]);
    }

    public function deleteMessage(string|int $chatId, int $messageId): bool
    {
        return (bool) $this->call('deleteMessage', ['chat_id' => (string) $chatId, 'message_id' => $messageId]);
    }

    /**
     * @param  ?string  $secretToken  sent back by Telegram in `X-Telegram-Bot-Api-Secret-Token`
     */
    public function setWebhook(string $url, ?string $secretToken = null): bool
    {
        $payload = ['url' => $url, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => false];

        if ($secretToken !== null && $secretToken !== '') {
            $payload['secret_token'] = $secretToken;
        }

        return (bool) $this->call('setWebhook', $payload);
    }

    /**
     * Sends a message that deletes itself after `$seconds` (credential
     * delivery, PROMPT §6.8.2). The deletion is a delayed job on the
     * database queue, run by the cron-driven worker: it happens on the first
     * worker run after the delay, so the precision is about one minute.
     *
     * @return ?array<string, mixed> the sent Message
     */
    public function sendEphemeral(string|int $chatId, string $text, int $seconds = 60): ?array
    {
        $message = $this->sendMessage($chatId, $text, [], ['protect_content' => true]);
        $messageId = $message['message_id'] ?? null;

        if (! is_int($messageId)) {
            return null;
        }

        DeleteTelegramMessage::dispatch((string) $chatId, $messageId)->delay(now()->addSeconds($seconds));

        return $message;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, array $payload): mixed
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            Log::warning('Telegram: TELEGRAM_BOT_TOKEN no está configurado; llamada omitida.', ['method' => $method]);

            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post(self::BASE_URL.'/bot'.$token.'/'.$method, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Telegram: error de conexión.', ['method' => $method, 'error' => str_replace($token, '***', $e->getMessage())]);

            return null;
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning('Telegram: la API rechazó la llamada.', [
                'method' => $method,
                'status' => $response->status(),
                'description' => $response->json('description'),
            ]);

            return null;
        }

        return $response->json('result');
    }
}
