<?php

namespace Tests\Feature\Ai\Projects;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

class BoardActionsTest extends ProjectsTestCase
{
    /**
     * @return list<string> task keys of a column, in order
     */
    private function column(AiProject $project, string $key): array
    {
        return $project->column($key)->tasks()->orderBy('position')->pluck('key')->all();
    }

    public function test_new_projects_get_the_five_default_columns(): void
    {
        $project = $this->project();

        $this->assertSame(
            [['backlog', 'Backlog', false], ['todo', 'Por hacer', false], ['in_progress', 'En progreso', false], ['review', 'En revisión', false], ['done', 'Hecho', true]],
            $project->columns()->get()->map(fn ($column) => [$column->key, $column->name, $column->is_done])->all(),
        );
    }

    public function test_store_creates_tasks_with_keys_at_the_end_of_the_column(): void
    {
        $project = $this->project();

        $this->actingAs($this->jhon)
            ->post(route('ai.tasks.store', $project), ['title' => 'Diseñar filtros', 'column' => 'todo'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->jhon)->post(route('ai.tasks.store', $project), ['title' => 'Endpoint', 'column' => 'todo']);
        $this->actingAs($this->jhon)->post(route('ai.tasks.store', $project), ['title' => '', 'column' => 'nope'])
            ->assertSessionHasErrors(['title', 'column']);

        $this->assertSame(['T1', 'T2'], $this->column($project, 'todo'));
        $this->assertSame([1, 2], $project->tasks()->orderBy('position')->pluck('position')->all());

        // Keys are per project.
        $other = $this->project();
        $this->actingAs($this->jhon)->post(route('ai.tasks.store', $other), ['title' => 'Otra', 'column' => 'backlog']);
        $this->assertSame(['T1'], $this->column($other, 'backlog'));

        $this->actingAs($this->paola)->post(route('ai.tasks.store', $project), ['title' => 'X', 'column' => 'todo'])->assertForbidden();
    }

    public function test_move_reorders_both_columns_consistently(): void
    {
        $project = $this->project(['status' => RequestStatus::InDevelopment]);
        $t1 = $this->task($project, 'todo');
        $t2 = $this->task($project, 'todo');
        $t3 = $this->task($project, 'todo');
        $t4 = $this->task($project, 'review');

        // Inside the same column: T3 to the top.
        $this->actingAs($this->jhon)
            ->put(route('ai.tasks.move', [$project, $t3]), ['column' => 'todo', 'position' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame([$t3->key, $t1->key, $t2->key], $this->column($project, 'todo'));

        // Across columns: T1 to second place in review.
        $this->actingAs($this->jhon)->put(route('ai.tasks.move', [$project, $t1]), ['column' => 'review', 'position' => 2]);
        $this->assertSame([$t3->key, $t2->key], $this->column($project, 'todo'));
        $this->assertSame([$t4->key, $t1->key], $this->column($project, 'review'));
        $this->assertSame([1, 2], $project->column('todo')->tasks()->orderBy('position')->pluck('position')->all());

        // Positions past the end are clamped.
        $this->actingAs($this->jhon)->put(route('ai.tasks.move', [$project, $t2]), ['column' => 'review', 'position' => 40]);
        $this->assertSame([$t4->key, $t1->key, $t2->key], $this->column($project, 'review'));

        $this->actingAs($this->jhon)->put(route('ai.tasks.move', [$project, $t2]), ['column' => 'nope', 'position' => 1])
            ->assertSessionHasErrors('column');

        $this->assertDatabaseHas('ai_project_activities', ['ai_project_id' => $project->id, 'kind' => 'task', 'text' => "movió {$t1->key} {$t1->title} a En revisión"]);
    }

    public function test_first_task_in_progress_starts_the_project_and_its_requests(): void
    {
        Notification::fake();
        $project = $this->project();
        $request = $this->request(['status' => RequestStatus::Planned, 'first_responded_at' => now()]);
        $project->requests()->attach($request->id);
        $task = $this->task($project, 'todo');

        $this->actingAs($this->jhon)->put(route('ai.tasks.move', [$project, $task]), ['column' => 'in_progress', 'position' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::InDevelopment, $project->fresh()->status);
        $this->assertNotNull($project->fresh()->started_at);
        $this->assertNotNull($task->fresh()->started_at);
        $this->assertSame(RequestStatus::InDevelopment, $request->fresh()->status);

        // Every task done: the project and its requests go to testing.
        $this->actingAs($this->jhon)->put(route('ai.tasks.move', [$project, $task]), ['column' => 'done', 'position' => 1]);
        $this->assertSame(RequestStatus::InTesting, $project->fresh()->status);
        $this->assertSame(RequestStatus::InTesting, $request->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_wip_limit_counts_real_projects_and_blocks_a_new_start(): void
    {
        app(Settings::class)->set('ai.wip_limit', 2);
        $this->project(['status' => RequestStatus::InDevelopment]);
        $this->project(['status' => RequestStatus::InDevelopment, 'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);
        // Requests "En desarrollo" no longer count.
        $this->request(['status' => RequestStatus::InDevelopment, 'first_responded_at' => now()]);
        $project = $this->project();
        $task = $this->task($project, 'todo');

        $this->actingAs($this->jhon)
            ->get(route('ai.backlog.index'))
            ->assertInertia(fn (Assert $page) => $page->where('wip', ['in_development' => 2, 'limit' => 2]));

        $this->actingAs($this->jhon)
            ->put(route('ai.tasks.move', [$project, $task]), ['column' => 'in_progress', 'position' => 1])
            ->assertSessionHasErrors('column');

        $this->assertSame(RequestStatus::Planned, $project->fresh()->status);
        $this->assertSame('todo', $task->fresh()->column->key);
    }

    public function test_update_changes_only_the_sent_fields(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'todo', ['title' => 'Viejo', 'description' => 'Igual']);

        $this->actingAs($this->jhon)
            ->put(route('ai.tasks.update', [$project, $task]), [
                'title' => 'Nuevo',
                'assignee_ids' => [$this->jhon->id, $this->superAdmin->id],
                'labels' => ['SAP', ' Backend ', 'SAP'],
                'due_at' => '2026-11-02',
            ])
            ->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame('Nuevo', $task->title);
        $this->assertSame('Igual', $task->description);
        $this->assertSame(['SAP', 'Backend'], $task->labels);
        $this->assertSame('2026-11-02', $task->due_date->toDateString());
        $this->assertEqualsCanonicalizing([$this->jhon->id, $this->superAdmin->id], $task->assignees()->pluck('users.id')->all());

        $this->actingAs($this->jhon)->put(route('ai.tasks.update', [$project, $task]), ['due_at' => null, 'column' => 'review']);
        $this->assertNull($task->fresh()->due_date);
        $this->assertSame('review', $task->fresh()->column->key);

        // Requesters cannot be assigned.
        $this->actingAs($this->jhon)->put(route('ai.tasks.update', [$project, $task]), ['assignee_ids' => [$this->paola->id]])
            ->assertSessionHasErrors('assignee_ids.0');
    }

    public function test_tasks_of_another_project_are_not_reachable(): void
    {
        $project = $this->project();
        $other = $this->project();
        $task = $this->task($other);
        $this->task($project);

        $this->actingAs($this->jhon)->put(route('ai.tasks.update', [$project->key, 'T9']), ['title' => 'X'])->assertNotFound();
        $this->assertSame('T1', $task->key);
        $this->actingAs($this->jhon)->put(route('ai.tasks.update', [$other, $task]), ['title' => 'Bien'])->assertSessionHasNoErrors();
        $this->assertSame('Bien', $task->fresh()->title);
    }

    public function test_subtasks_comments_and_attachments(): void
    {
        Storage::fake('local');
        $project = $this->project();
        $task = $this->task($project);

        $this->actingAs($this->jhon)->post(route('ai.subtasks.store', [$project, $task]), ['title' => 'Conectar'])->assertSessionHasNoErrors();
        $this->actingAs($this->jhon)->post(route('ai.subtasks.store', [$project, $task]), ['title' => 'Filtrar']);
        $subtask = $task->subtasks()->first();

        $this->actingAs($this->jhon)->patch(route('ai.subtasks.toggle', [$project, $task, $subtask->id]), ['done' => true])->assertSessionHasNoErrors();
        $this->assertTrue($subtask->fresh()->done);
        $this->assertSame($this->jhon->id, $subtask->fresh()->done_by_id);

        $otherTask = $this->task($project);
        $this->actingAs($this->jhon)->patch(route('ai.subtasks.toggle', [$project, $otherTask, $subtask->id]), ['done' => false])->assertNotFound();

        $this->actingAs($this->jhon)->post(route('ai.tasks.comments.store', [$project, $task]), ['body' => 'Revisé la vista'])->assertSessionHasNoErrors();
        $this->assertSame('Revisé la vista', $task->comments()->value('body'));
        $this->assertDatabaseHas('ai_project_activities', ['ai_project_id' => $project->id, 'kind' => 'comment', 'text' => "comentó en {$task->key} {$task->title}"]);

        $file = UploadedFile::fake()->create('consulta.pdf', 120, 'application/pdf');
        $this->actingAs($this->jhon)->post(route('ai.tasks.attachments.store', [$project, $task]), ['file' => $file])->assertSessionHasNoErrors();
        $this->actingAs($this->jhon)->post(route('ai.tasks.attachments.store', [$project, $task]), ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')])
            ->assertSessionHasErrors('file');

        $attachment = $task->attachments()->first();
        Storage::disk('local')->assertExists($attachment->path);

        $url = null;
        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', [$project, 'tarea' => $task->key]))
            ->assertInertia(function (Assert $page) use (&$url) {
                $page->where('selected_task.attachments.0.name', 'consulta.pdf');
                $url = $page->toArray()['props']['selected_task']['attachments'][0]['url'];
            });

        $this->actingAs($this->jhon)->get($url)->assertOk();
        $this->actingAs($this->paola)->get($url)->assertForbidden();
        $this->actingAs($this->jhon)->get(route('ai.tasks.attachments.show', [$project, $task, $attachment->id]))->assertForbidden();
    }

    public function test_task_keys_survive_deletion_gaps(): void
    {
        $project = $this->project();
        $first = $this->task($project);
        $second = $this->task($project);
        $second->delete();
        $third = $this->task($project);

        $this->assertSame(['T1', 'T3'], [$first->key, $third->key]);
        $this->assertInstanceOf(AiTask::class, $third);
    }
}
