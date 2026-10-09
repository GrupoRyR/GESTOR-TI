<?php

namespace App\Telegram;

use App\Models\TelegramLink;
use App\Services\Telegram\TelegramClient;
use App\Support\Audit\AuditOrigin;
use App\Support\Integrations\IntegrationsOverview;
use App\Support\Settings;
use App\Telegram\Handlers\ApprovalCallbackHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes one Telegram update received by the webhook. Dispatched after
 * the 200 response (or to the database queue, `gestor.telegram.processing`).
 *
 * - `/start {token}` links the private chat to the user that generated the
 *   token (single use, 30 min).
 * - `/start` without token and any other command or text: help message,
 *   except the reason of a ❌ Rechazar pressed just before (and `/cancelar`),
 *   which ApprovalCallbackHandler takes.
 *   (The Asistente TI takes over plain text in Stage C.)
 * - `callback_query`: routed through `CallbackRegistry` for linked chats.
 *
 * Nothing about unlinked chats is stored (only logs). The time of the last
 * handled message/callback is kept in the setting `telegram.last_update_at`
 * (Integraciones › Telegram).
 */
class UpdateHandler implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public const HELP_TEXT = "Hola, soy el bot del Gestor TI RYR.\n\n"
        .'Para recibir avisos aquí, entra al Gestor TI, abre «Perfil y notificaciones» (en el menú de tu nombre) y pulsa «Vincular Telegram». '
        .'El enlace que se genera abre este chat y deja tu cuenta vinculada.';

    public const LINKED_TEXT = 'Listo, tu cuenta del Gestor TI quedó vinculada. Desde ahora recibirás aquí los avisos que elijas en «Perfil y notificaciones».';

    public const INVALID_TOKEN_TEXT = 'El enlace no es válido o ya venció. Genera uno nuevo desde «Perfil y notificaciones» en el Gestor TI.';

    public const CHAT_TAKEN_TEXT = 'Este chat ya está vinculado a otra cuenta del Gestor TI. Desvincúlala primero desde esa cuenta.';

    /**
     * @param  array<string, mixed>  $update  raw Telegram Update
     */
    public function __construct(public array $update) {}

    public function handle(TelegramClient $client, CallbackRegistry $callbacks): void
    {
        // Activities logged while handling the update are audited as `telegram`.
        AuditOrigin::runAs(AuditOrigin::TELEGRAM, fn () => $this->process($client, $callbacks));
    }

    private function process(TelegramClient $client, CallbackRegistry $callbacks): void
    {
        // Telegram redelivers an update when it did not get a 200 in time:
        // handle each update_id once.
        $updateId = $this->update['update_id'] ?? null;

        if (is_int($updateId) && ! Cache::add('telegram:update:'.$updateId, true, now()->addDay())) {
            return;
        }

        $message = $this->update['message'] ?? null;
        $callbackQuery = $this->update['callback_query'] ?? null;

        if (is_array($message) || is_array($callbackQuery)) {
            // Integraciones › Telegram "Último mensaje".
            app(Settings::class)->set(IntegrationsOverview::TELEGRAM_LAST_UPDATE_KEY, now()->toIso8601String());
        }

        if (is_array($callbackQuery)) {
            $this->handleCallback($callbackQuery, $client, $callbacks);

            return;
        }

        if (is_array($message)) {
            $this->handleMessage($message, $client);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleMessage(array $message, TelegramClient $client): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $chatType = $message['chat']['type'] ?? 'private';
        $text = trim((string) ($message['text'] ?? ''));

        if (! is_int($chatId) && ! is_string($chatId)) {
            return;
        }

        // Only private chats are linked or answered (no group spam).
        if ($chatType !== 'private') {
            Log::info('Telegram: mensaje ignorado de un chat no privado.', ['chat_type' => $chatType]);

            return;
        }

        [$command, $argument] = $this->parseCommand($text);

        // The reason of a ❌ Rechazar pressed just before (or /cancelar).
        if (($command === null || $command === ApprovalCallbackHandler::CANCEL_COMMAND) && $this->handlePendingReason((string) $chatId, $text)) {
            return;
        }

        if ($command === '/start' && $argument !== '') {
            $client->sendMessage($chatId, $this->link((string) $chatId, $argument, $message['from']['username'] ?? null));

            return;
        }

        $client->sendMessage($chatId, self::HELP_TEXT);
    }

    private function handlePendingReason(string $chatId, string $text): bool
    {
        if (! Cache::has(ApprovalCallbackHandler::reasonCacheKey($chatId))) {
            return false;
        }

        $link = TelegramLink::findLinkedChat($chatId);

        return $link?->user !== null && app(ApprovalCallbackHandler::class)->handleReason($link->user, $chatId, $text);
    }

    /**
     * Consumes the link token and links the chat. Returns the reply text.
     */
    private function link(string $chatId, string $token, mixed $username): string
    {
        return DB::transaction(function () use ($chatId, $token, $username): string {
            $link = TelegramLink::findByValidToken($token);

            if ($link === null) {
                Log::info('Telegram: intento de vinculación con un token inválido o vencido.');

                return self::INVALID_TOKEN_TEXT;
            }

            $owner = TelegramLink::query()->where('chat_id', $chatId)->first();

            if ($owner !== null && $owner->user_id !== $link->user_id) {
                Log::warning('Telegram: el chat ya está vinculado a otro usuario.', ['user_id' => $link->user_id]);

                return self::CHAT_TAKEN_TEXT;
            }

            $link->forceFill([
                'chat_id' => $chatId,
                'telegram_username' => is_string($username) ? $username : null,
                'linked_at' => now(),
                'link_token' => null,
                'token_expires_at' => null,
            ])->save();

            Log::info('Telegram: chat vinculado.', ['user_id' => $link->user_id]);

            return self::LINKED_TEXT;
        });
    }

    /**
     * @param  array<string, mixed>  $callbackQuery
     */
    private function handleCallback(array $callbackQuery, TelegramClient $client, CallbackRegistry $callbacks): void
    {
        $id = $callbackQuery['id'] ?? null;

        if (! is_string($id)) {
            return;
        }

        $chatId = $callbackQuery['message']['chat']['id'] ?? $callbackQuery['from']['id'] ?? null;
        $link = (is_int($chatId) || is_string($chatId)) ? TelegramLink::findLinkedChat((string) $chatId) : null;

        if ($link === null || $link->user === null) {
            $client->answerCallbackQuery($id, 'Este chat no está vinculado a una cuenta del Gestor TI.', true);

            return;
        }

        $resolved = $callbacks->resolve((string) ($callbackQuery['data'] ?? ''));

        if ($resolved === null) {
            $client->answerCallbackQuery($id, 'Esta acción ya no está disponible.');

            return;
        }

        [$handler, $payload] = $resolved;

        $client->answerCallbackQuery($id, $handler->handle($link->user, $payload, $callbackQuery));
    }

    /**
     * Splits `/command@Bot argument` into ['/command', 'argument'].
     *
     * @return array{0: ?string, 1: string}
     */
    private function parseCommand(string $text): array
    {
        if (! str_starts_with($text, '/')) {
            return [null, $text];
        }

        $parts = preg_split('/\s+/', $text, 2) ?: [$text];
        $command = strtolower(explode('@', $parts[0], 2)[0]);

        return [$command, trim($parts[1] ?? '')];
    }
}
