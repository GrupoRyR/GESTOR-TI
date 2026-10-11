<?php

namespace App\Support\Vault;

use App\Http\Middleware\RequireRecentMicrosoftAuth;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

/**
 * Server-side state of the vault for one browser session (PROMPT 6.4.10):
 *
 * - Opened by `ai.vault.reauth` after a fresh Microsoft sign-in
 *   (`reauth:10`); opening consumes that confirmation, so the next unlock
 *   asks Microsoft again.
 * - Every vault request refreshes `last_used_at`; after 10 minutes without
 *   use the next request finds it expired and locks it (design
 *   `VaultSessionState.expires_at` = last use + 10 minutes).
 */
final class VaultSession
{
    public const UNLOCKED_AT = 'vault.unlocked_at';

    public const LAST_USED_AT = 'vault.last_used_at';

    public const IDLE_MINUTES = 10;

    public static function isOpen(Session $session): bool
    {
        return is_numeric($session->get(self::UNLOCKED_AT)) && is_numeric($session->get(self::LAST_USED_AT));
    }

    /**
     * Open and idle for more than 10 minutes.
     */
    public static function expired(Session $session): bool
    {
        return self::isOpen($session)
            && (int) $session->get(self::LAST_USED_AT) < now()->subMinutes(self::IDLE_MINUTES)->getTimestamp();
    }

    public static function open(Session $session): void
    {
        $now = now()->getTimestamp();

        $session->put(self::UNLOCKED_AT, $now);
        $session->put(self::LAST_USED_AT, $now);
        $session->forget(RequireRecentMicrosoftAuth::SESSION_KEY);
    }

    public static function touch(Session $session): void
    {
        if (self::isOpen($session)) {
            $session->put(self::LAST_USED_AT, now()->getTimestamp());
        }
    }

    public static function lock(Session $session): void
    {
        $session->forget([self::UNLOCKED_AT, self::LAST_USED_AT, RequireRecentMicrosoftAuth::SESSION_KEY]);
    }

    /**
     * Design `VaultSessionState`.
     *
     * @return array{state: 'locked'}|array{state: 'open', confirmed_at: string, expires_at: string}
     */
    public static function state(Session $session): array
    {
        if (! self::isOpen($session)) {
            return ['state' => 'locked'];
        }

        return [
            'state' => 'open',
            'confirmed_at' => CarbonImmutable::createFromTimestamp((int) $session->get(self::UNLOCKED_AT), config('app.timezone'))->toIso8601String(),
            'expires_at' => CarbonImmutable::createFromTimestamp((int) $session->get(self::LAST_USED_AT), config('app.timezone'))
                ->addMinutes(self::IDLE_MINUTES)
                ->toIso8601String(),
        ];
    }
}
