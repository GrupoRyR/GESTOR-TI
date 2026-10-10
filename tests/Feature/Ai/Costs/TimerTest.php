<?php

namespace Tests\Feature\Ai\Costs;

use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\AiTimeEntry;
use App\Models\AiTimer;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `ai.timer.start {project, task}` / `ai.timer.stop`: one running timer per
 * person; stopping (or starting another) writes a timer entry.
 */
class TimerTest extends CostsTestCase
{
    private AiProject $p;

    private AiTask $t1;

    private AiTask $t2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p = $this->activeProject();
        $this->t1 = $this->task($this->p, 'in_progress', ['title' => 'Endpoint de zonas', 'due_date' => '2026-10-09'], [$this->jhon]);
        $this->t2 = $this->task($this->p, 'review', ['title' => 'Pantalla de zonas']);
        $this->t1->subtasks()->create(['title' => 'Uno', 'done' => true, 'position' => 1]);
        $this->t1->subtasks()->create(['title' => 'Dos', 'done' => false, 'position' => 2]);
    }

    public function test_start_runs_a_timer_shown_on_the_board_the_task_and_the_week(): void
    {
        $this->actingAs($this->jhon)
            ->post(route('ai.timer.start', ['project' => $this->p->key, 'task' => $this->t1->key]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $timer = AiTimer::query()->where('user_id', $this->jhon->id)->sole();
        $this->assertSame($this->t1->id, $timer->ai_task_id);
        $started = $timer->started_at->toIso8601String();

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', $this->p))
            ->assertInertia(fn (Assert $page) => $page
                ->where('running_timer', [
                    'project_key' => $this->p->key,
                    'task_key' => $this->t1->key,
                    'task_title' => 'Endpoint de zonas',
                    'started_at' => $started,
                    'subtasks_done' => 1,
                    'subtasks_total' => 2,
                    'due_at' => '2026-10-09',
                ])
                ->where('tasks.0.timer', ['running' => true, 'started_at' => $started, 'person_id' => $this->jhon->id])
                ->where('tasks.1.timer', null)
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.tasks.show', ['project' => $this->p->key, 'task' => $this->t1->key]))
            ->assertInertia(fn (Assert $page) => $page->where('task.timer.running', true));

        $this->actingAs($this->jhon)
            ->get(route('ai.week.index'))
            ->assertInertia(fn (Assert $page) => $page->where('running_timer.task_key', $this->t1->key));

        // Another person sees no timer of theirs.
        $this->actingAs($this->superAdmin)
            ->get(route('ai.projects.board', $this->p))
            ->assertInertia(fn (Assert $page) => $page->where('running_timer', null)->where('tasks.0.timer', null));
    }

    public function test_stop_writes_a_timer_entry_rounded_to_the_minute(): void
    {
        $this->actingAs($this->jhon)->post(route('ai.timer.start', ['project' => $this->p->key, 'task' => $this->t1->key]));
        $this->travel(72 * 60 + 40)->seconds();

        $this->actingAs($this->jhon)->post(route('ai.timer.stop'))->assertRedirect()->assertSessionHas('success');

        $entry = AiTimeEntry::query()->sole();
        $this->assertSame('timer', $entry->origin);
        $this->assertSame('1.22', $entry->hours);
        $this->assertSame('2026-10-07', $entry->date->toDateString());
        $this->assertSame($this->t1->id, $entry->ai_task_id);
        $this->assertSame('Endpoint de zonas', $entry->note);
        $this->assertNotNull($entry->started_at);
        $this->assertNotNull($entry->ended_at);
        $this->assertSame(0, AiTimer::query()->count());
    }

    public function test_starting_another_task_stops_the_previous_timer(): void
    {
        $this->actingAs($this->jhon)->post(route('ai.timer.start', ['project' => $this->p->key, 'task' => $this->t1->key]));
        $this->travel(30)->minutes();
        $this->actingAs($this->jhon)->post(route('ai.timer.start', ['project' => $this->p->key, 'task' => $this->t2->key]));

        $this->assertSame(1, AiTimer::query()->count());
        $this->assertSame($this->t2->id, AiTimer::query()->sole()->ai_task_id);
        $this->assertSame('0.50', AiTimeEntry::query()->sole()->hours);

        // Starting the same task again keeps the running timer.
        $this->travel(5)->minutes();
        $this->actingAs($this->jhon)->post(route('ai.timer.start', ['project' => $this->p->key, 'task' => $this->t2->key]));
        $this->assertSame(1, AiTimeEntry::query()->count());
    }

    public function test_stop_without_a_timer_and_short_runs_are_explained(): void
    {
        $this->actingAs($this->jhon)->post(route('ai.timer.stop'))->assertSessionHas('error');

        $this->actingAs($this->jhon)->post(route('ai.timer.start', ['project' => $this->p->key, 'task' => $this->t1->key]));
        $this->travel(40)->seconds();
        $this->actingAs($this->jhon)->post(route('ai.timer.stop'))->assertSessionHas('error');

        $this->assertSame(0, AiTimeEntry::query()->count());
        $this->assertSame(0, AiTimer::query()->count());
    }

    public function test_only_people_of_the_project_run_timers(): void
    {
        $this->actingAs($this->paola)
            ->post(route('ai.timer.start', ['project' => $this->p->key, 'task' => $this->t1->key]))
            ->assertForbidden();

        $dev = $this->developer();
        $this->member($this->p, $dev);

        $this->actingAs($dev)
            ->post(route('ai.timer.start', ['project' => $this->p->key, 'task' => $this->t1->key]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, AiTimer::query()->where('user_id', $dev->id)->count());
    }

    public function test_a_forgotten_timer_logs_at_most_sixteen_hours(): void
    {
        $this->actingAs($this->jhon)->post(route('ai.timer.start', ['project' => $this->p->key, 'task' => $this->t1->key]));
        $this->travel(30)->hours();

        $this->actingAs($this->jhon)->post(route('ai.timer.stop'));

        $this->assertSame('16.00', AiTimeEntry::query()->sole()->hours);
    }
}
