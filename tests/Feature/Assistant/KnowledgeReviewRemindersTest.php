<?php

namespace Tests\Feature\Assistant;

use App\Notifications\KnowledgeReviewReminder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

/**
 * `assistant:knowledge-review-reminders` (PROMPT 6.8.1): cards whose
 * review date passed (6 months after the last review) reach the knowledge
 * managers once a week.
 */
class KnowledgeReviewRemindersTest extends AssistantTestCase
{
    public function test_managers_get_one_digest_of_the_overdue_cards_they_can_see(): void
    {
        Notification::fake();
        $manager = $this->user($this->derivados, 'Usuario final', 'Gestor Derivados');
        $manager->givePermissionTo('assistant.knowledge.manage');

        $old = $this->card(['title' => 'Informe viejo', 'review_at' => '2026-04-01']);
        $this->card(['title' => 'Al día', 'review_at' => '2027-01-01']);
        $this->card(['title' => 'Vence hoy', 'review_at' => self::TODAY]);

        $this->artisan('assistant:knowledge-review-reminders')->assertSuccessful();

        Notification::assertSentTo($this->jhon, KnowledgeReviewReminder::class, function (KnowledgeReviewReminder $notice) use ($old) {
            return $notice->count === 1
                && $notice->titles === ["{$old->key} · Informe viejo"]
                && $notice->eventType() === 'assistant.knowledge.review'
                && $notice->title() === 'Base de conocimiento: 1 ficha con la revisión vencida'
                && $notice->actionUrl() === route('assistant.knowledge.index', ['vencidas' => 1]);
        });
        Notification::assertSentTo($this->superAdmin, KnowledgeReviewReminder::class);
        Notification::assertNotSentTo($manager, KnowledgeReviewReminder::class);
        Notification::assertNotSentTo($this->paola, KnowledgeReviewReminder::class);
    }

    public function test_six_months_after_a_review_the_card_is_reminded(): void
    {
        Notification::fake();
        $card = $this->card(['review_at' => '2027-01-01']);
        $this->actingAs($this->jhon)->post(route('assistant.knowledge.review', $card));

        $this->travelTo(now()->addMonths(6)->addDay());
        $this->artisan('assistant:knowledge-review-reminders')->assertSuccessful();

        Notification::assertSentTo($this->jhon, KnowledgeReviewReminder::class, fn (KnowledgeReviewReminder $notice) => $notice->count === 1);
    }

    public function test_nothing_is_sent_when_no_card_is_overdue(): void
    {
        Notification::fake();
        $this->card(['review_at' => '2027-01-01']);

        $this->artisan('assistant:knowledge-review-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_it_is_scheduled_weekly_in_bogota_time_and_has_a_catalog_event(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'assistant:knowledge-review-reminders'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * 1', $event->expression);
        $this->assertSame('America/Bogota', (string) $event->timezone);
        $this->assertNotEmpty(config('notifications.events')['assistant.knowledge.review']['label']);
        $this->assertNotEmpty(config('notifications.events')['assistant.knowledge.review']['hint']);
    }
}
