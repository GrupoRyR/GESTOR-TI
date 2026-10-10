<?php

namespace App\Domain\Ai;

use App\Models\AiVaultItem;
use Carbon\CarbonImmutable;

/**
 * Expiry and rotation dates of vault credentials (Bogotá calendar):
 *
 * - expiry: `expires_at`;
 * - rotation: (`last_rotated_at`, else `created_at`) + `rotate_every_days`.
 *
 * `next()` is the earliest of both: the date the design shows as
 * `VaultItem.expires_at` ("Rotar en 12 días") and the one Inicio lists.
 * Reminders go out exactly 30, 15 and 7 days before each date.
 */
final class VaultDueDates
{
    public const TIMEZONE = 'America/Bogota';

    /** @var list<int> */
    public const REMINDER_DAYS = [30, 15, 7];

    /** Inicio lists credentials due within this many days (or overdue). */
    public const HOME_WINDOW_DAYS = 30;

    public const EXPIRY = 'expiry';

    public const ROTATION = 'rotation';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    public static function expiry(AiVaultItem $item): ?CarbonImmutable
    {
        return $item->expires_at === null ? null : CarbonImmutable::parse($item->expires_at->toDateString(), self::TIMEZONE);
    }

    public static function rotation(AiVaultItem $item): ?CarbonImmutable
    {
        if ($item->rotate_every_days === null || $item->rotate_every_days <= 0) {
            return null;
        }

        $from = $item->last_rotated_at ?? $item->created_at;

        return CarbonImmutable::parse($from->copy()->timezone(self::TIMEZONE)->toDateString(), self::TIMEZONE)
            ->addDays($item->rotate_every_days);
    }

    /**
     * Both due dates that exist, keyed by kind.
     *
     * @return array<'expiry'|'rotation', CarbonImmutable>
     */
    public static function all(AiVaultItem $item): array
    {
        return array_filter([self::EXPIRY => self::expiry($item), self::ROTATION => self::rotation($item)]);
    }

    /**
     * The earliest due date and its kind; null when the item has none.
     *
     * @return array{kind: 'expiry'|'rotation', date: CarbonImmutable}|null
     */
    public static function next(AiVaultItem $item): ?array
    {
        $next = null;

        foreach (self::all($item) as $kind => $date) {
            if ($next === null || $date->lt($next['date'])) {
                $next = ['kind' => $kind, 'date' => $date];
            }
        }

        return $next;
    }

    /**
     * Whole days from today to the date (negative when past).
     */
    public static function daysUntil(CarbonImmutable $date, ?CarbonImmutable $today = null): int
    {
        return (int) ($today ?? self::today())->diffInDays($date, false);
    }
}
