<?php

namespace App\Domain\Ai;

use App\Enums\Ai\EffortSize;
use App\Enums\Ai\Urgency;

/**
 * Suggested priority of a request (0–100). Exact port of the design's
 * resources/js/lib/priority.ts (the UI shows the same number live):
 *
 *   team_hours = hours_per_week × people
 *   impact     = team_hours + 2.5 × votes
 *   raw        = impact ÷ SIZE_WEIGHTS[size] × URGENCY_MULTIPLIERS[urgency]
 *   score      = round(100 × raw ÷ (raw + 10))
 *
 * Negative inputs count as zero; no size means no score. A missing urgency
 * counts as `medium` (multiplier 1): the wizard always asks for it, but
 * requests created on behalf may come without one.
 */
final class PriorityCalculator
{
    /** @var array<string, int> */
    public const SIZE_WEIGHTS = ['S' => 1, 'M' => 2, 'L' => 3, 'XL' => 5];

    /** @var array<string, float> */
    public const URGENCY_MULTIPLIERS = ['low' => 0.8, 'medium' => 1.0, 'high' => 1.2, 'urgent' => 1.5];

    /** Hours per week each +1 vote adds to the impact. */
    public const VOTE_WEIGHT = 2.5;

    /** Raw value that scores 50. */
    private const HALF_POINT = 10;

    public static function suggested(?float $hoursPerWeek, ?float $people, int $votes, ?EffortSize $size, ?Urgency $urgency): ?int
    {
        if ($size === null) {
            return null;
        }

        return self::breakdown($hoursPerWeek, $people, $votes, $size, $urgency)['score'];
    }

    /**
     * Every factor of the score, for the "¿Cómo se calcula?" explanation.
     *
     * @return array{team_hours: float, impact: float, effort: int, urgency_multiplier: float, raw: float, score: int}
     */
    public static function breakdown(?float $hoursPerWeek, ?float $people, int $votes, EffortSize $size, ?Urgency $urgency): array
    {
        $teamHours = round(self::positive($hoursPerWeek) * self::positive($people), 4);
        $impact = round($teamHours + self::VOTE_WEIGHT * self::positive($votes), 4);
        $effort = self::SIZE_WEIGHTS[$size->value];
        $multiplier = self::URGENCY_MULTIPLIERS[($urgency ?? Urgency::Medium)->value];
        $raw = round(($impact / $effort) * $multiplier, 4);
        $score = (int) min(100, max(0, round((100 * $raw) / ($raw + self::HALF_POINT))));

        return [
            'team_hours' => $teamHours,
            'impact' => $impact,
            'effort' => $effort,
            'urgency_multiplier' => $multiplier,
            'raw' => $raw,
            'score' => $score,
        ];
    }

    private static function positive(float|int|null $value): float
    {
        return $value !== null && is_finite((float) $value) && $value > 0 ? (float) $value : 0.0;
    }
}
