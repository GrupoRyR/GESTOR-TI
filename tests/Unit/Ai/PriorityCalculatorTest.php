<?php

namespace Tests\Unit\Ai;

use App\Domain\Ai\PriorityCalculator;
use App\Enums\Ai\EffortSize;
use App\Enums\Ai\Urgency;
use PHPUnit\Framework\TestCase;

/**
 * Port of the design's resources/js/lib/priority.test.ts: the server must
 * compute exactly the suggested priority the UI shows.
 */
class PriorityCalculatorTest extends TestCase
{
    private function score(float $hours, float $people, int $votes, ?EffortSize $size, Urgency $urgency): ?int
    {
        return PriorityCalculator::suggested($hours, $people, $votes, $size, $urgency);
    }

    public function test_it_scores_gia_148_as_77(): void
    {
        $this->assertSame(77, $this->score(6, 8, 3, EffortSize::M, Urgency::High));
    }

    public function test_it_exposes_the_documented_weights(): void
    {
        $this->assertSame(['S' => 1, 'M' => 2, 'L' => 3, 'XL' => 5], PriorityCalculator::SIZE_WEIGHTS);
        $this->assertSame(['low' => 0.8, 'medium' => 1.0, 'high' => 1.2, 'urgent' => 1.5], PriorityCalculator::URGENCY_MULTIPLIERS);
        $this->assertSame(2.5, PriorityCalculator::VOTE_WEIGHT);
    }

    public function test_it_rises_with_saved_hours_people_and_votes(): void
    {
        $baseline = $this->score(3, 2, 0, EffortSize::M, Urgency::Medium);

        $this->assertGreaterThan($baseline, $this->score(6, 2, 0, EffortSize::M, Urgency::Medium));
        $this->assertGreaterThan($baseline, $this->score(3, 4, 0, EffortSize::M, Urgency::Medium));
        $this->assertGreaterThan($baseline, $this->score(3, 2, 4, EffortSize::M, Urgency::Medium));
    }

    public function test_it_falls_as_the_effort_grows(): void
    {
        $scores = array_map(fn (EffortSize $size) => $this->score(4, 5, 0, $size, Urgency::Medium), EffortSize::cases());
        $sorted = $scores;
        rsort($sorted);

        $this->assertSame($sorted, $scores);
        $this->assertCount(4, array_unique($scores));
    }

    public function test_it_rises_with_urgency(): void
    {
        $scores = array_map(fn (Urgency $urgency) => $this->score(4, 5, 0, EffortSize::L, $urgency), Urgency::cases());
        $sorted = $scores;
        sort($sorted);

        $this->assertSame($sorted, $scores);
    }

    public function test_it_stays_within_0_and_100(): void
    {
        $this->assertSame(0, $this->score(0, 0, 0, EffortSize::XL, Urgency::Low));
        $this->assertLessThanOrEqual(100, $this->score(40, 500, 99, EffortSize::S, Urgency::Urgent));
    }

    public function test_it_cannot_be_scored_without_a_size(): void
    {
        $this->assertNull($this->score(6, 8, 0, null, Urgency::High));
    }

    public function test_it_treats_negative_inputs_as_zero(): void
    {
        $this->assertSame(0, $this->score(-5, 3, -2, EffortSize::S, Urgency::Medium));
    }

    public function test_the_breakdown_explains_every_factor(): void
    {
        $this->assertSame([
            'team_hours' => 48.0,
            'impact' => 55.5,
            'effort' => 2,
            'urgency_multiplier' => 1.2,
            'raw' => 33.3,
            'score' => 77,
        ], PriorityCalculator::breakdown(6, 8, 3, EffortSize::M, Urgency::High));
    }

    public function test_a_missing_urgency_counts_as_medium(): void
    {
        $this->assertSame(
            PriorityCalculator::suggested(4, 5, 0, EffortSize::L, Urgency::Medium),
            PriorityCalculator::suggested(4, 5, 0, EffortSize::L, null),
        );
    }
}
