<?php

namespace Tests\Unit\Ai;

use App\Domain\Ai\BusinessDays;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BusinessDaysTest extends TestCase
{
    public function test_it_adds_days_inside_the_same_week(): void
    {
        // Monday 2026-10-05 09:00 + 3 business days = Thursday.
        $due = BusinessDays::add(CarbonImmutable::parse('2026-10-05 09:00', 'America/Bogota'), 3);

        $this->assertSame('2026-10-08 09:00', $due->format('Y-m-d H:i'));
    }

    public function test_it_skips_the_weekend(): void
    {
        // Thursday + 3 business days = Tuesday.
        $due = BusinessDays::add(CarbonImmutable::parse('2026-10-08 15:30', 'America/Bogota'), 3);

        $this->assertSame('2026-10-13 15:30', $due->format('Y-m-d H:i'));
    }

    public function test_a_request_filed_on_saturday_counts_from_monday(): void
    {
        // Saturday + 1 business day = Monday.
        $due = BusinessDays::add(CarbonImmutable::parse('2026-10-10 10:00', 'America/Bogota'), 1);

        $this->assertSame('2026-10-12', $due->format('Y-m-d'));
        $this->assertTrue($due->isWeekday());
    }

    public function test_configured_holidays_are_skipped(): void
    {
        // Friday + 1 business day with Monday 2026-10-12 as holiday = Tuesday.
        $due = BusinessDays::add(CarbonImmutable::parse('2026-10-09 09:00', 'America/Bogota'), 1, ['2026-10-12']);

        $this->assertSame('2026-10-13', $due->format('Y-m-d'));
    }

    public function test_the_result_is_in_bogota_time(): void
    {
        $due = BusinessDays::add(CarbonImmutable::parse('2026-10-05 14:00', 'UTC'), 1);

        $this->assertSame('America/Bogota', $due->getTimezone()->getName());
        $this->assertSame('2026-10-06 09:00', $due->format('Y-m-d H:i'));
    }
}
