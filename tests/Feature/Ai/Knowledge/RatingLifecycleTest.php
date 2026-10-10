<?php

namespace Tests\Feature\Ai\Knowledge;

use App\Actions\Ai\TransitionRequest;
use App\Enums\Ai\RequestStatus;
use App\Models\AiHoursSavedReport;
use App\Models\AiRating;
use App\Models\AiRequest;
use App\Notifications\AiHoursSavedQuestion;
use App\Notifications\AiRatingRequested;
use App\Notifications\AiRequestStatusChanged;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

class RatingLifecycleTest extends KnowledgeTestCase
{
    private function inProduction(): AiRequest
    {
        $request = $this->request(['status' => RequestStatus::InTesting]);
        app(TransitionRequest::class)->apply($request, RequestStatus::InProduction, $this->jhon, 'Ya está publicada.');

        return $request->fresh();
    }

    public function test_reaching_production_asks_the_requester_to_rate(): void
    {
        Notification::fake();

        $request = $this->inProduction();

        Notification::assertSentTo($this->paola, AiRatingRequested::class, fn (AiRatingRequested $notice) => $notice->requestKey === $request->key
            && ! $notice->reminder
            && $notice->actionUrl() === route('ai.ratings.create', $request->key)
            && str_contains($notice->body(), 'Ya está publicada.'));
        Notification::assertNotSentTo($this->paola, AiRequestStatusChanged::class);
        $this->assertNotNull($request->rating_requested_at);
    }

    public function test_reminder_at_three_days_close_at_seven_and_hours_question_at_thirty(): void
    {
        Notification::fake();
        $request = $this->inProduction();

        $this->travel(2)->days();
        $this->artisan('ai:ratings-lifecycle')->assertSuccessful();
        Notification::assertSentToTimes($this->paola, AiRatingRequested::class, 1);

        $this->travel(1)->days();
        $this->artisan('ai:ratings-lifecycle')->assertSuccessful();
        Notification::assertSentTo($this->paola, AiRatingRequested::class, fn (AiRatingRequested $notice) => $notice->reminder);
        Notification::assertSentToTimes($this->paola, AiRatingRequested::class, 2);

        // Running again the same day sends nothing new.
        $this->artisan('ai:ratings-lifecycle')->assertSuccessful();
        Notification::assertSentToTimes($this->paola, AiRatingRequested::class, 2);

        $this->travel(4)->days();
        $this->artisan('ai:ratings-lifecycle')->assertSuccessful();
        $request->refresh();
        $this->assertSame(RequestStatus::Closed, $request->status);
        $this->assertSame('Se cerró sin calificación: pasaron 7 días desde que llegó a producción.', $request->statusChanges()->reorder()->latest('id')->first()->note);
        Notification::assertSentTo($this->paola, AiRequestStatusChanged::class, fn (AiRequestStatusChanged $notice) => $notice->status === RequestStatus::Closed);

        $this->travel(22)->days();
        $this->artisan('ai:ratings-lifecycle')->assertSuccessful();
        Notification::assertNotSentTo($this->paola, AiHoursSavedQuestion::class);

        $this->travel(1)->days();
        $this->artisan('ai:ratings-lifecycle')->assertSuccessful();
        Notification::assertSentTo($this->paola, AiHoursSavedQuestion::class, fn (AiHoursSavedQuestion $notice) => $notice->actionUrl() === route('ai.ratings.hours.create', $request->key)
            && $notice->title() === '¿Cuántas horas te ahorra por semana?');

        $this->artisan('ai:ratings-lifecycle')->assertSuccessful();
        Notification::assertSentToTimes($this->paola, AiHoursSavedQuestion::class, 1);
    }

    public function test_rated_requests_are_neither_reminded_nor_closed(): void
    {
        Notification::fake();
        $request = $this->inProduction();
        AiRating::create(['ai_request_id' => $request->id, 'user_id' => $this->paola->id, 'solved_need' => 5, 'ease_of_use' => 4, 'rated_at' => now()]);

        $this->travel(8)->days();
        $this->artisan('ai:ratings-lifecycle')->assertSuccessful();

        Notification::assertSentToTimes($this->paola, AiRatingRequested::class, 1);
        $this->assertSame(RequestStatus::InProduction, $request->fresh()->status);
    }

    public function test_requesters_who_already_reported_hours_are_not_asked(): void
    {
        Notification::fake();
        $request = $this->inProduction();
        AiRating::create(['ai_request_id' => $request->id, 'user_id' => $this->paola->id, 'solved_need' => 5, 'ease_of_use' => 4, 'rated_at' => now()]);
        AiHoursSavedReport::create(['ai_request_id' => $request->id, 'user_id' => $this->paola->id, 'hours_per_week' => 2, 'reported_at' => now()]);

        $this->travel(31)->days();
        $this->artisan('ai:ratings-lifecycle')->assertSuccessful();

        Notification::assertNotSentTo($this->paola, AiHoursSavedQuestion::class);
    }

    public function test_events_are_in_the_catalog_and_the_command_is_scheduled_daily(): void
    {
        $events = config('notifications.events');
        $this->assertArrayHasKey('ai.rating.requested', $events);
        $this->assertArrayHasKey('ai.rating.hours', $events);
        $this->assertSame('ai.rating.requested', (new AiRatingRequested('GIA-1', 'X', false))->eventType());
        $this->assertSame('ai.rating.hours', (new AiHoursSavedQuestion('GIA-1', 'X'))->eventType());

        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'ai:ratings-lifecycle'));
        $this->assertCount(1, $events);
        $this->assertSame('0 9 * * *', $events->first()->expression);
        $this->assertSame('America/Bogota', $events->first()->timezone);
    }
}
