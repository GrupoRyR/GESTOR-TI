<?php

namespace App\Domain\Ai;

use App\Models\AiRequest;

/**
 * Money helpers of the Gestor IA: COP formatting and the value of the hours
 * a request would save (requester's hours/week × people × weeks per month
 * × the hour value of its area).
 */
final class Money
{
    /** 52 weeks / 12 months. */
    public const WEEKS_PER_MONTH = 52 / 12;

    /**
     * "$ 1.170.000".
     */
    public static function cop(int|float $amount): string
    {
        return '$ '.number_format(round($amount), 0, ',', '.');
    }

    /**
     * Team hours per month the request would save (null while unknown).
     */
    public static function hoursPerMonth(AiRequest $request): ?float
    {
        $weekly = $request->teamHours();

        return $weekly === null ? null : $weekly * self::WEEKS_PER_MONTH;
    }

    /**
     * Money impact per month in COP: null without hours or area hour value.
     */
    public static function impactPerMonth(AiRequest $request): ?int
    {
        $hours = self::hoursPerMonth($request);
        $value = $request->area?->hour_value;

        return $hours === null || $value === null ? null : (int) round($hours * $value);
    }

    /**
     * Months to pay back `$cost` with the hours saved (one decimal), or null
     * when the cost, the hours or the area hour value are unknown.
     */
    public static function paybackMonths(AiRequest $request, ?int $cost): ?float
    {
        $saving = self::impactPerMonth($request);

        if ($cost === null || $saving === null || $saving <= 0) {
            return null;
        }

        return round($cost / $saving, 1);
    }
}
