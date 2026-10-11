<?php

namespace App\Domain\Ai;

use Carbon\CarbonInterface;

/**
 * Deadline wording shared by Inicio, Por área and Mi semana (Bogotá
 * calendar days, lowercase so callers can embed it): "vencida hace 2 días",
 * "vencida hace 3 h", "vence hoy", "vence mañana", "vence en 4 días".
 *
 * Levels: `late` once the deadline passed, `attention` when it falls within
 * the next 24 hours ("near"), `ok` otherwise.
 */
final class DueLabel
{
    /**
     * @return array{level: 'late'|'attention'|'ok', label: string}
     */
    public static function for(CarbonInterface $due, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $dueDay = $due->copy()->timezone(BusinessDays::TIMEZONE)->startOfDay();
        $nowDay = $now->copy()->timezone(BusinessDays::TIMEZONE)->startOfDay();
        $days = (int) $nowDay->diffInDays($dueDay, false);

        if ($due->lessThan($now)) {
            if ($days < 0) {
                return ['level' => 'late', 'label' => 'vencida hace '.self::days(-$days)];
            }

            $hours = max(1, (int) floor($due->diffInMinutes($now, true) / 60));

            return ['level' => 'late', 'label' => "vencida hace {$hours} h"];
        }

        $level = $due->lessThanOrEqualTo($now->copy()->addDay()) ? 'attention' : 'ok';

        return ['level' => $level, 'label' => match (true) {
            $days === 0 => 'vence hoy',
            $days === 1 => 'vence mañana',
            default => 'vence en '.self::days($days),
        }];
    }

    public static function isNear(CarbonInterface $due, ?CarbonInterface $now = null): bool
    {
        return self::for($due, $now)['level'] !== 'ok';
    }

    private static function days(int $count): string
    {
        return $count === 1 ? '1 día' : "{$count} días";
    }
}
