<?php

namespace App\Support\Mail;

use Carbon\CarbonInterface;

/**
 * Dates already phrased in Spanish for the email templates, in Bogotá time
 * (samples.json formats): "martes 13 oct", "5:00 p. m.", "hoy 5:00 p. m.",
 * "hoy, 9:14 a. m.".
 */
final class MailDates
{
    public const TIMEZONE = 'America/Bogota';

    private const DAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * "martes 13 oct".
     */
    public static function day(CarbonInterface $date): string
    {
        $local = self::local($date);

        return self::DAYS[$local->dayOfWeek].' '.$local->day.' '.self::MONTHS[$local->month - 1];
    }

    /**
     * "5:00 p. m.".
     */
    public static function time(CarbonInterface $date): string
    {
        $local = self::local($date);

        return $local->format('g:i').($local->hour < 12 ? ' a. m.' : ' p. m.');
    }

    /**
     * "hoy 5:00 p. m.", "mañana 9:00 a. m." or "martes 13 oct 5:00 p. m."
     * (`first_response_due`). With `$comma`: "hoy, 9:14 a. m." (`asked_at`).
     */
    public static function moment(CarbonInterface $date, bool $comma = false): string
    {
        $local = self::local($date);
        $today = now(self::TIMEZONE)->startOfDay();
        $day = match (true) {
            $local->isSameDay($today) => 'hoy',
            $local->isSameDay($today->copy()->addDay()) => 'mañana',
            $local->isSameDay($today->copy()->subDay()) => 'ayer',
            default => self::day($local),
        };

        return $day.($comma ? ', ' : ' ').self::time($local);
    }

    private static function local(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->setTimezone(self::TIMEZONE);
    }
}
