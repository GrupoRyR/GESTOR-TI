<?php

namespace Tests\Feature\Ai\Costs;

use App\Notifications\AiHoursReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

/**
 * Friday 15:00 Bogotá: IT people and project members who logged no hours
 * this week get a reminder (`ai:hours-reminder`).
 */
class HoursReminderTest extends CostsTestCase
{
    public function test_people_without_hours_this_week_are_reminded(): void
    {
        Notification::fake();
        $project = $this->activeProject();
        $dev = $this->developer();
        $this->member($project, $dev);
        $this->hours($project, $this->jhon, 2, null, '2026-10-05');
        // Last week's hours do not count.
        $this->hours($project, $this->superAdmin, 3, null, '2026-10-02');

        $this->travelTo(CarbonImmutable::parse('2026-10-09 15:00', 'America/Bogota'));
        $this->artisan('ai:hours-reminder')->assertSuccessful();

        Notification::assertSentTo($this->superAdmin, AiHoursReminder::class, fn (AiHoursReminder $n) => $n->eventType() === 'ai.hours.reminder'
            && str_contains($n->title(), 'horas'));
        Notification::assertSentTo($dev, AiHoursReminder::class);
        Notification::assertNotSentTo($this->jhon, AiHoursReminder::class);
        Notification::assertNotSentTo($this->paola, AiHoursReminder::class);
        Notification::assertNotSentTo($this->edward, AiHoursReminder::class);
    }

    public function test_the_reminder_is_scheduled_on_fridays_at_three_pm_bogota(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'ai:hours-reminder'));

        $this->assertNotNull($event);
        $this->assertSame('0 15 * * 5', $event->expression);
        $this->assertSame('America/Bogota', (string) $event->timezone);
    }
}
