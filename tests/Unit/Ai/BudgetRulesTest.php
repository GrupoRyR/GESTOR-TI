<?php

namespace Tests\Unit\Ai;

use App\Domain\Ai\BudgetRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Same cases as resources/js/lib/budget.test.ts: the server and the pages
 * must agree on the budget states and the re-approval rules.
 */
class BudgetRulesTest extends TestCase
{
    public function test_ratio_divides_actual_by_estimate(): void
    {
        $this->assertEqualsWithDelta(0.8875, BudgetRules::ratio(14_200_000, 16_000_000), 0.0001);
        $this->assertSame(0.0, BudgetRules::ratio(0, 0));
        $this->assertSame(INF, BudgetRules::ratio(5, 0));
    }

    public function test_state_is_ok_below_80_warning_up_to_100_and_over_above(): void
    {
        $this->assertSame('ok', BudgetRules::state(92, 120));
        $this->assertSame('ok', BudgetRules::state(79.9, 100));
        $this->assertSame('warning', BudgetRules::state(80, 100));
        $this->assertSame('warning', BudgetRules::state(14_200_000, 16_000_000));
        $this->assertSame('warning', BudgetRules::state(100, 100));
        $this->assertSame('over', BudgetRules::state(100.5, 100));
        $this->assertSame('over', BudgetRules::state(1, 0));
    }

    public function test_needs_reapproval_only_above_the_threshold(): void
    {
        $this->assertFalse(BudgetRules::needsReapproval(120, 100));
        $this->assertTrue(BudgetRules::needsReapproval(120.1, 100));
        $this->assertTrue(BudgetRules::needsReapproval(110, 100, 0.05));
        $this->assertFalse(BudgetRules::needsReapproval(130, 100, 0.5));
    }

    public function test_a_negative_threshold_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BudgetRules::needsReapproval(1, 1, -0.1);
    }

    public function test_days_late_counts_calendar_days_of_the_date_part(): void
    {
        $this->assertSame(14, BudgetRules::daysLate('2026-11-14', '2026-10-31'));
        $this->assertSame(0, BudgetRules::daysLate('2026-10-31', '2026-10-31'));
        $this->assertSame(0, BudgetRules::daysLate('2026-10-20', '2026-10-31'));
        $this->assertSame(1, BudgetRules::daysLate('2026-11-01T23:00:00-05:00', '2026-10-31'));
    }

    public function test_schedule_needs_reapproval_compares_the_delay_with_the_approved_duration(): void
    {
        $this->assertTrue(BudgetRules::scheduleNeedsReapproval('2026-09-30', '2026-10-31', '2026-11-14'));
        $this->assertFalse(BudgetRules::scheduleNeedsReapproval('2026-10-01', '2026-10-11', '2026-10-13'));
        $this->assertTrue(BudgetRules::scheduleNeedsReapproval('2026-10-01', '2026-10-11', '2026-10-14'));
    }

    public function test_projected_end_mirrors_the_costs_page(): void
    {
        $this->assertSame('2026-10-07', BudgetRules::projectedEnd('2026-10-07', 0, 3));
        $this->assertNull(BudgetRules::projectedEnd('2026-10-07', 5, 0));
        $this->assertSame('2026-10-17', BudgetRules::projectedEnd('2026-10-07', 5, 3.5));
    }
}
