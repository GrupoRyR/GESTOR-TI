<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\Alert;
use App\Notifications\AlertRaised;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

class FirstResponseAlertsTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_overdue_requests_raise_one_grouped_warning_for_ai_managers(): void
    {
        $overdue = $this->request(['first_response_due_at' => now()->subHour()]);
        $this->request(['first_response_due_at' => now()->addDay()]);
        $this->request(['first_response_due_at' => now()->subDay(), 'first_responded_at' => now()->subDays(2)]);
        $this->request(['first_response_due_at' => now()->subDay(), 'status' => RequestStatus::Rejected]);
        $otherCompany = $this->request([
            'company_id' => $this->derivados->id,
            'area_id' => $this->derivadosVentas->id,
            'requester_id' => $this->derivadosUser->id,
            'first_response_due_at' => now()->subHour(),
        ]);

        $this->artisan('ai:first-response-alerts')->assertSuccessful();

        $alerts = Alert::query()->orderBy('id')->get();
        $this->assertSame(["ai.first_response:{$overdue->key}", "ai.first_response:{$otherCompany->key}"], $alerts->pluck('group_key')->all());
        $this->assertSame('warning', $alerts[0]->level);
        $this->assertSame('ai', $alerts[0]->source);
        $this->assertSame($this->segurex->id, $alerts[0]->company_id);
        $this->assertSame($overdue->id, $alerts[0]->source_id);
        $this->assertStringContainsString($overdue->key, $alerts[0]->title);
        Notification::assertSentTo($this->jhon, AlertRaised::class);
        Notification::assertNotSentTo($this->paola, AlertRaised::class);

        $this->artisan('ai:first-response-alerts')->assertSuccessful();

        $this->assertSame(2, Alert::count(), 'the same request keeps one alert');
        $this->assertSame(2, $alerts[0]->refresh()->occurrences);
    }

    public function test_it_runs_every_fifteen_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'ai:first-response-alerts'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
