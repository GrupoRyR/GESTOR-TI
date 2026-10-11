<?php

namespace Tests\Feature\Ai\Costs;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\AiTimeEntry;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Agregar horas" (`Ai/TimeEntries/Create`, `ai.time-entries.store`) and
 * the edit/delete twins (`ai.time-entries.update|destroy`).
 */
class TimeEntriesTest extends CostsTestCase
{
    private AiProject $p;

    private AiTask $open;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p = $this->activeProject();
        $this->open = $this->task($this->p, 'todo', ['title' => 'Conexión con SAP']);
        $this->task($this->p, 'done', ['title' => 'Lista']);
    }

    public function test_create_returns_exactly_the_contract_props(): void
    {
        $this->assertSame(
            $this->withShared(['projects', 'defaults', 'telegram_keys', 'today', 'now']),
            $this->propKeys(route('ai.time-entries.create')),
        );
    }

    public function test_create_lists_open_projects_with_open_tasks_and_preselects(): void
    {
        $this->project(['name' => 'Cerrado', 'status' => RequestStatus::Closed]);
        $request = $this->originRequest($this->p);

        $this->actingAs($this->jhon)
            ->get(route('ai.time-entries.create', ['project' => $this->p->key, 'task' => $this->open->key]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/TimeEntries/Create', false)
                ->has('projects', 1)
                ->where('projects.0', [
                    'key' => $this->p->key,
                    'name' => $this->p->name,
                    'tasks' => [['key' => $this->open->key, 'title' => 'Conexión con SAP']],
                ])
                ->where('defaults', ['project_key' => $this->p->key, 'task_key' => $this->open->key])
                ->where('telegram_keys', [$this->p->key => $request->key])
                ->where('today', '2026-10-07')
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.time-entries.create', ['project' => 'P-999', 'task' => 'T9']))
            ->assertInertia(fn (Assert $page) => $page->where('defaults', ['project_key' => null, 'task_key' => null]));
    }

    public function test_store_logs_a_manual_entry(): void
    {
        $this->actingAs($this->jhon)
            ->post(route('ai.time-entries.store', $this->p), [
                'task_key' => $this->open->key,
                'date' => '2026-10-06',
                'hours' => 1.5,
                'note' => 'Reunión con cartera',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $entry = AiTimeEntry::query()->sole();
        $this->assertSame('manual', $entry->origin);
        $this->assertSame('1.50', $entry->hours);
        $this->assertSame('2026-10-06', $entry->date->toDateString());
        $this->assertSame($this->open->id, $entry->ai_task_id);
        $this->assertSame($this->jhon->id, $entry->user_id);
        $this->assertSame('Reunión con cartera', $entry->note);

        // Project time without a task.
        $this->actingAs($this->jhon)
            ->post(route('ai.time-entries.store', $this->p), ['task_key' => null, 'date' => '2026-10-07', 'hours' => 2, 'note' => 'Planeación'])
            ->assertSessionHasNoErrors();
        $this->assertNull(AiTimeEntry::query()->latest('id')->first()->ai_task_id);
    }

    public function test_store_validates_with_spanish_messages(): void
    {
        $other = $this->task($this->activeProject(), 'todo');

        $this->actingAs($this->jhon)
            ->post(route('ai.time-entries.store', $this->p), ['task_key' => $other->key.'x', 'date' => '2026-10-08', 'hours' => 17, 'note' => ''])
            ->assertSessionHasErrors(['task_key', 'date', 'hours', 'note']);

        $this->actingAs($this->jhon)
            ->post(route('ai.time-entries.store', $this->p), ['task_key' => null, 'date' => '2026-10-07', 'hours' => 0, 'note' => 'x'])
            ->assertSessionHasErrors(['hours']);

        $this->assertSame(0, AiTimeEntry::query()->count());
    }

    public function test_people_outside_the_project_cannot_log_hours(): void
    {
        $this->actingAs($this->paola)
            ->post(route('ai.time-entries.store', $this->p), ['task_key' => null, 'date' => '2026-10-07', 'hours' => 1, 'note' => 'x'])
            ->assertForbidden();

        $dev = $this->developer();
        $this->member($this->p, $dev);
        $this->actingAs($dev)
            ->post(route('ai.time-entries.store', $this->p), ['task_key' => null, 'date' => '2026-10-07', 'hours' => 1, 'note' => 'Pruebas'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, AiTimeEntry::query()->where('user_id', $dev->id)->count());
    }

    public function test_owners_edit_their_entries_and_it_edits_any(): void
    {
        $dev = $this->developer();
        $other = $this->user($this->segurex, 'Usuario final', 'Sara Dev');
        $this->member($this->p, $dev);
        $this->member($this->p, $other);
        $entry = $this->hours($this->p, $dev, 2);
        $params = ['project' => $this->p->key, 'entry' => $entry->id];
        $body = ['task_key' => $this->open->key, 'date' => '2026-10-07', 'hours' => 3, 'note' => 'Corregido'];

        $this->actingAs($other)->put(route('ai.time-entries.update', $params), $body)->assertForbidden();
        $this->actingAs($other)->delete(route('ai.time-entries.destroy', $params))->assertForbidden();

        $this->actingAs($dev)->put(route('ai.time-entries.update', $params), $body)->assertSessionHasNoErrors();
        $entry->refresh();
        $this->assertSame('3.00', $entry->hours);
        $this->assertSame('Corregido', $entry->note);
        $this->assertSame($this->open->id, $entry->ai_task_id);

        $this->actingAs($this->jhon)->delete(route('ai.time-entries.destroy', $params))->assertRedirect();
        $this->assertSame(0, AiTimeEntry::query()->count());
    }

    public function test_an_entry_of_another_project_is_not_found(): void
    {
        $entry = $this->hours($this->activeProject(), $this->jhon, 1);

        $this->actingAs($this->jhon)
            ->delete(route('ai.time-entries.destroy', ['project' => $this->p->key, 'entry' => $entry->id]))
            ->assertNotFound();
    }
}
