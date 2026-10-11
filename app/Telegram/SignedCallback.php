<?php

namespace App\Telegram;

/**
 * Opaque, signed `callback_data` bound to one chat:
 * `{prefix}:{payload}:{signature}`. The signature is an HMAC (app key) of
 * the prefix, the payload and the chat id (12 base64url characters), so a
 * button only works in the chat it was sent to and cannot be forged or
 * edited. Payloads are short ids (`ok:31`, `pick:88:1`), never names,
 * emails or passwords; the whole data stays under Telegram's 64 bytes.
 */
final class SignedCallback
{
    public static function make(string $prefix, string $payload, string $chatId): string
    {
        return $prefix.CallbackRegistry::SEPARATOR.$payload.CallbackRegistry::SEPARATOR.self::signature($prefix, $payload, $chatId);
    }

    /**
     * The payload when the signature matches that chat, else null.
     *
     * @param  string  $signed  callback data after `{prefix}:`
     */
    public static function verify(string $prefix, string $signed, string $chatId): ?string
    {
        $position = strrpos($signed, CallbackRegistry::SEPARATOR);

        if ($position === false || $chatId === '') {
            return null;
        }

        $payload = substr($signed, 0, $position);
        $signature = substr($signed, $position + 1);

        return hash_equals(self::signature($prefix, $payload, $chatId), $signature) ? $payload : null;
    }

    /**
     * Chat id of a callback query (the chat of the message with the button).
     *
     * @param  array<string, mixed>  $callbackQuery
     */
    public static function chatOf(array $callbackQuery): string
    {
        $chatId = $callbackQuery['message']['chat']['id'] ?? $callbackQuery['from']['id'] ?? null;

        return is_int($chatId) || is_string($chatId) ? (string) $chatId : '';
    }

    private static function signature(string $prefix, string $payload, string $chatId): string
    {
        $raw = hash_hmac('sha256', "telegram-callback|{$prefix}|{$payload}|{$chatId}", (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(substr($raw, 0, 9)), '+/', '-_'), '=');
    }
}
