<?php

namespace App\Support\Integrations;

use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Whether the Telegram webhook answers, from `getWebhookInfo`. The result is
 * cached for CACHE_MINUTES so Integraciones never calls Telegram on every
 * page load; "Enviarme un mensaje de prueba" refreshes it.
 *
 * OK = a webhook URL is registered and Telegram reported no delivery error
 * in the last RECENT_ERROR_MINUTES.
 */
class TelegramWebhookStatus
{
    public const CACHE_KEY = 'integrations.telegram.webhook_ok';

    public const CACHE_MINUTES = 10;

    public const RECENT_ERROR_MINUTES = 60;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly Cache $cache,
    ) {}

    public function ok(): bool
    {
        if (! $this->client->isConfigured()) {
            return false;
        }

        return (bool) $this->cache->remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_MINUTES), fn (): bool => $this->check());
    }

    public function refresh(): bool
    {
        $this->cache->forget(self::CACHE_KEY);

        return $this->ok();
    }

    private function check(): bool
    {
        $info = $this->client->getWebhookInfo();

        if ($info === null || ! filled($info['url'] ?? null)) {
            return false;
        }

        $lastError = $info['last_error_date'] ?? null;

        return ! is_int($lastError) || $lastError < now()->subMinutes(self::RECENT_ERROR_MINUTES)->getTimestamp();
    }
}
