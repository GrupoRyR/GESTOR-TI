<?php

namespace App\Domain\Ai;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Server twin of resources/js/lib/budget.ts and `projectedEnd()` of
 * Components/ai/projects/projects.ts (same cases in BudgetRulesTest):
 *
 * - ratio = actual ÷ estimate (no estimate: 0 when nothing was spent,
 *   INF otherwise); state ok < 80 % ≤ warning ≤ 100 % < over.
 * - Re-approval when actual exceeds the estimate by more than the
 *   threshold (0.2 = 20 %), or the delay passes that share of the
 *   approved duration (approval date → approved due date).
 * - Projected end: today when nothing remains, null without a pace, else
 *   today + ceil(remaining ÷ hours per week × 7) days.
 */
final class BudgetRules
{
    public const WARNING_RATIO = 0.8;

    public const DEFAULT_REAPPROVAL_THRESHOLD = 0.2;

    public static function ratio(float $actual, float $estimate): float
    {
        if ($estimate <= 0) {
            return $actual > 0 ? INF : 0.0;
        }

        return $actual / $estimate;
    }

    /**
     * @return 'ok'|'warning'|'over'
     */
    public static function state(float $actual, float $estimate): string
    {
        $ratio = self::ratio($actual, $estimate);

        return match (true) {
            $ratio > 1 => 'over',
            $ratio >= self::WARNING_RATIO => 'warning',
            default => 'ok',
        };
    }

    public static function needsReapproval(float $actual, float $estimate, float $threshold = self::DEFAULT_REAPPROVAL_THRESHOLD): bool
    {
        self::assertThreshold($threshold);

        return $actual > $estimate * (1 + $threshold);
    }

    public static function daysLate(string $projected, string $approved): int
    {
        return max(0, self::dayNumber($projected) - self::dayNumber($approved));
    }

    public static function scheduleNeedsReapproval(string $approvedAt, string $approvedDue, string $projectedDue, float $threshold = self::DEFAULT_REAPPROVAL_THRESHOLD): bool
    {
        self::assertThreshold($threshold);
        $duration = max(1, self::dayNumber($approvedDue) - self::dayNumber($approvedAt));

        return self::daysLate($projectedDue, $approvedDue) > $duration * $threshold;
    }

    public static function projectedEnd(string $today, float $remainingHours, float $hoursPerWeek): ?string
    {
        if ($remainingHours <= 0) {
            return $today;
        }

        if ($hoursPerWeek <= 0) {
            return null;
        }

        return CarbonImmutable::parse(substr($today, 0, 10))
            ->addDays((int) ceil(round($remainingHours / $hoursPerWeek * 7, 9)))
            ->toDateString();
    }

    private static function dayNumber(string $iso): int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $match) !== 1) {
            throw new InvalidArgumentException("Invalid ISO date: {$iso}");
        }

        return intdiv((int) gmmktime(0, 0, 0, (int) $match[2], (int) $match[3], (int) $match[1]), 86_400);
    }

    private static function assertThreshold(float $threshold): void
    {
        if (! ($threshold >= 0)) {
            throw new InvalidArgumentException("Invalid re-approval threshold: {$threshold}");
        }
    }
}
