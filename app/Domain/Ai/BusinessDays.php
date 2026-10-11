<?php

namespace App\Domain\Ai;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Business-day arithmetic in Bogotá time for the first-response window.
 * Weekends are skipped; Colombian holidays are skipped only when listed
 * (Settings `ai.holidays`, `YYYY-MM-DD` strings; empty by default — no
 * holiday calendar is bundled).
 */
final class BusinessDays
{
    public const TIMEZONE = 'America/Bogota';

    /**
     * The same time of day, `$days` business days later. A start on a
     * non-business day counts from the next business day.
     *
     * @param  list<string>  $holidays
     */
    public static function add(DateTimeInterface $from, int $days, array $holidays = []): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from)->setTimezone(self::TIMEZONE);
        $holidays = array_flip($holidays);

        $added = 0;
        while ($added < max(0, $days)) {
            $date = $date->addDay();

            if (self::isBusinessDay($date, $holidays)) {
                $added++;
            }
        }

        return $date;
    }

    /**
     * @param  array<string, int>  $holidays
     */
    private static function isBusinessDay(CarbonImmutable $date, array $holidays): bool
    {
        return $date->isWeekday() && ! isset($holidays[$date->format('Y-m-d')]);
    }
}
