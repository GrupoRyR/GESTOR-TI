<?php

namespace Tests\Feature\Ai\Costs;

use App\Models\AiProject;
use App\Models\AiTimeEntry;
use App\Models\Alert;
use App\Notifications\AiBudgetAlert;
use Illuminate\Support\Facades\Notification;

/**
 * Estimate vs real alerts (6.4.11): approvers hear once when the project
 * reaches 80 % and once when it goes over 100 % of what they approved.
 */
class BudgetAlertsTest extends CostsTestCase
{
    private AiProject $p;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->p = $this->activeProject(10, null);
    }

    private function log(float $hours): void
    {
        $this->actingAs($this->jhon)
            ->post(route('ai.time-entries.store', $this->p), ['task_key' => null, 'date' => '2026-10-07', 'hours' => $hours, 'note' => 'Trabajo'])
            ->assertSessionHasNoErrors();
    }

    public function test_80_and_100_percent_alerts_reach_the_approvers_once_per_crossing(): void
    {
        $this->log(7);
        Notification::assertNothingSent();

        $this->log(1);
        Notification::assertSentToTimes($this->edward, AiBudgetAlert::class, 1);
        Notification::assertSentTo($this->edward, AiBudgetAlert::class, fn (AiBudgetAlert $alert) => $alert->level === 80
            && $alert->eventType() === 'ai.project.budget'
            && str_contains($alert->title(), $this->p->key)
            && str_contains($alert->title(), '80 %'));
        Notification::assertNotSentTo($this->paola, AiBudgetAlert::class);

        $this->log(1);
        Notification::assertSentToTimes($this->edward, AiBudgetAlert::class, 1);

        $this->log(1.5);
        Notification::assertSentToTimes($this->edward, AiBudgetAlert::class, 2);
        Notification::assertSentTo($this->edward, AiBudgetAlert::class, fn (AiBudgetAlert $alert) => $alert->level === 100);

        $this->log(1);
        Notification::assertSentToTimes($this->edward, AiBudgetAlert::class, 2);

        $this->assertSame(1, Alert::query()->where('group_key', "ai.budget:{$this->p->key}:80")->count());
        $this->assertSame(1, Alert::query()->where('group_key', "ai.budget:{$this->p->key}:100")->count());
        $this->assertSame(2, $this->p->activities()->where('kind', 'budget')->count());
    }

    public function test_going_back_below_lets_a_new_crossing_alert_again(): void
    {
        $this->log(8);
        Notification::assertSentToTimes($this->edward, AiBudgetAlert::class, 1);

        $entry = AiTimeEntry::query()->latest('id')->first();
        $this->actingAs($this->jhon)->delete(route('ai.time-entries.destroy', ['project' => $this->p->key, 'entry' => $entry->id]));
        $this->assertNotNull(Alert::query()->where('group_key', "ai.budget:{$this->p->key}:80")->sole()->resolved_at);

        $this->log(8);
        Notification::assertSentToTimes($this->edward, AiBudgetAlert::class, 2);
    }

    public function test_projects_without_an_estimate_never_alert(): void
    {
        $bare = $this->project(['status' => 'in_development']);

        $this->actingAs($this->jhon)
            ->post(route('ai.time-entries.store', $bare), ['task_key' => null, 'date' => '2026-10-07', 'hours' => 8, 'note' => 'x'])
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }
}
