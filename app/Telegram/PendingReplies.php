<?php

namespace App\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * "The next message of this chat is the answer to my question": the reason
 * of a ❌ Rechazar (B4) or of a discarded `/aprender` draft (C4). One
 * pending reply per chat (a new one replaces the previous), with a kind,
 * its data and an expiry (15 minutes by default). UpdateHandler gives the
 * next plain-text message (or `/cancelar`) to the handler of that kind
 * before anything else, the Asistente TI included.
 */
final class PendingReplies
{
    public const MINUTES = 15;

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $chatId, string $kind, array $data, int $minutes = self::MINUTES): void
    {
        Cache::put(self::key($chatId), ['kind' => $kind, 'data' => $data], now()->addMinutes($minutes));
    }

    /**
     * @return ?array{kind: string, data: array<string, mixed>}
     */
    public function get(string $chatId): ?array
    {
        $pending = Cache::get(self::key($chatId));

        if (! is_array($pending) || ! is_string($pending['kind'] ?? null) || ! is_array($pending['data'] ?? null)) {
            return null;
        }

        return ['kind' => $pending['kind'], 'data' => $pending['data']];
    }

    public function forget(string $chatId): void
    {
        Cache::forget(self::key($chatId));
    }

    public static function key(string $chatId): string
    {
        return 'telegram:pending-reply:'.$chatId;
    }
}
