<?php

namespace Tests\Feature\Ai\Projects;

use App\Enums\Ai\RequestStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AiEstimate;
use App\Models\AiProjectActivity;
use App\Support\Settings;
use Inertia\Testing\AssertableInertia as Assert;

class ProjectPagesTest extends ProjectsTestCase
{
    public function test_index_returns_exactly_the_contract_props(): void
    {
        $this->project();

        $this->assertSame($this->withShared(['projects', 'filters', 'wip', 'now']), $this->propKeys(route('ai.projects.index')));

        $this->actingAs($this->paola)->get(route('ai.projects.index'))->assertForbidden();
        $this->actingAs($this->edward)->get(route('ai.projects.index'))->assertForbidden();
    }

    public function test_index_lists_active_projects_first_with_their_shape(): void
    {
        $closed = $this->project(['name' => 'Cerrado', 'status' => RequestStatus::Closed]);
        $planned = $this->project(['name' => 'Planificado']);
        $active = $this->project(['name' => 'Visualizador de cartera', 'status' => RequestStatus::InDevelopment]);
        $request = $this->request(['status' => RequestStatus::Planned]);
        $active->requests()->attach($request->id);
        $active->members()->create(['user_id' => $this->jhon->id, 'role' => 'Líder del proyecto']);
        $this->task($active, 'in_progress');
        $blocked = $this->task($active, 'todo');
        $this->requirement($active, ['blocks_task_id' => $blocked->id]);
        $this->rate($this->jhon, 100_000);
        AiEstimate::create(['ai_project_id' => $active->id, 'hours_total' => 10, 'external_costs' => 0, 'total_cost' => 1_000_000, 'due_date' => '2026-12-01']);
        $this->hours($active, $this->jhon, 4);

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Projects/Index', false)
                ->where('filters', ['status' => null, 'company_id' => null])
                ->where('wip', ['in_development' => 1, 'limit' => 3])
                ->has('projects', 3)
                ->where('projects.0.key', $active->key)
                ->where('projects.1.key', $planned->key)
                ->where('projects.2.key', $closed->key)
                ->has('projects.0', fn (Assert $item) => $item
                    ->where('key', $active->key)
                    ->where('name', 'Visualizador de cartera')
                    ->where('status', 'in_development')
                    ->where('company_id', $this->segurex->id)
                    ->where('area', ['id' => $this->ventas->id, 'name' => 'Ventas'])
                    ->where('request_keys', [$request->key])
                    ->where('team.0.role', 'Líder del proyecto')
                    ->where('team.0.person.name', 'Jhon Castro')
                    ->where('budget.hours', ['estimate' => 10, 'actual' => 4])
                    ->where('budget.cost', ['estimate' => 1_000_000, 'actual' => 400_000])
                    ->where('budget.due_date', '2026-12-01')
                    ->has('budget.projected_due_date')
                    ->has('budget.approved_at')
                    ->where('budget.needs_reapproval', false)
                    ->where('tasks_by_column', ['backlog' => 0, 'todo' => 1, 'in_progress' => 1, 'review' => 0, 'done' => 0])
                    ->where('blocked_tasks', 1)
                    ->where('open_requirements', 1)
                )
            );
    }

    public function test_money_is_null_without_costs_view(): void
    {
        $this->project();
        $viewer = $this->itWithoutCosts();

        $this->actingAs($viewer)
            ->get(route('ai.projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projects.0.budget.cost', null));
    }

    public function test_index_filters_and_company_scope_with_three_companies(): void
    {
        $this->project(['status' => RequestStatus::InDevelopment]);
        $this->project(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);
        $this->project(['company_id' => $this->third->id, 'area_id' => null]);

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.index', ['status' => 'in_development']))
            ->assertInertia(fn (Assert $page) => $page->has('projects', 1)->where('filters.status', 'in_development'));

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.index', ['company_id' => $this->derivados->id]))
            ->assertInertia(fn (Assert $page) => $page->has('projects', 1)->where('projects.0.company_id', $this->derivados->id));

        $this->actingAs($this->jhon)
            ->withSession(['current_company_id' => $this->third->id])
            ->get(route('ai.projects.index'))
            ->assertInertia(fn (Assert $page) => $page->has('projects', 1)->where('projects.0.company_id', $this->third->id));

        $other = $this->project(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);
        $this->actingAs($this->jhon)
            ->withSession(['current_company_id' => $this->segurex->id])
            ->get(route('ai.projects.show', $other))
            ->assertNotFound();
    }

    public function test_show_returns_exactly_the_contract_props(): void
    {
        $project = $this->project();

        $this->assertSame(
            $this->withShared(['project', 'origin_request', 'open_requirements', 'activity', 'approvers', 'reapproval_threshold', 'reapproval_requested_at', 'next_step', 'today', 'now']),
            $this->propKeys(route('ai.projects.show', $project)),
        );
    }

    public function test_show_has_the_project_its_origin_and_activity(): void
    {
        $this->jhonApproves();
        $project = $this->project(['objective' => 'Filtrar la cartera', 'approved_at' => now()->subDays(3)]);
        $request = $this->request(['status' => RequestStatus::Planned]);
        $project->requests()->attach($request->id);
        $project->benefitedAreas()->attach($this->compras->id, ['note' => 'ven su cartera']);
        $request->criteria()->create(['position' => 1, 'text' => 'Filtra por asesor']);
        $this->requirement($project, ['title' => 'Lista de zonas']);
        $this->requirement($project, ['title' => 'Ya respondido', 'status' => 'answered', 'answer' => 'Listo', 'answered_at' => now()]);
        foreach (range(1, 8) as $i) {
            AiProjectActivity::create(['ai_project_id' => $project->id, 'kind' => 'task', 'user_id' => $this->jhon->id, 'text' => "movió T{$i}"]);
        }
        app(Settings::class)->set('ai.reapproval_threshold', 0.25);

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Projects/Show', false)
                ->where('project.key', $project->key)
                ->where('project.objective', 'Filtrar la cartera')
                ->where('project.benefited_areas', [['area' => ['id' => $this->compras->id, 'name' => 'Compras'], 'note' => 'ven su cartera']])
                ->where('project.criteria.0.text', 'Filtra por asesor')
                ->where('project.wip', ['in_development' => 0, 'limit' => 3])
                ->where('project.repository', null)
                ->where('project.product_slug', null)
                ->where('origin_request.key', $request->key)
                ->where('origin_request.project_key', $project->key)
                ->has('origin_request.approved_by')
                ->has('origin_request.approved_at')
                ->has('open_requirements', 1)
                ->where('open_requirements.0.title', 'Lista de zonas')
                ->where('open_requirements.0.status', 'pending')
                ->has('activity', 6)
                ->where('activity.0.text', 'movió T8')
                ->where('activity.0.actor.name', 'Jhon Castro')
                ->where('approvers.0.name', 'Edward Ramanan')
                ->where('reapproval_threshold', 0.25)
                ->where('reapproval_requested_at', null)
                ->has('today')
            );
    }

    public function test_view_policy_members_area_viewers_and_others(): void
    {
        $project = $this->project();

        $this->actingAs($this->paola)->get(route('ai.projects.show', $project))->assertForbidden();
        $this->actingAs($this->paola)->get(route('ai.projects.board', $project))->assertForbidden();
        // Aprobador de área of Ventas (`ai.area.view`): read only.
        $this->actingAs($this->ventasApprover)->get(route('ai.projects.show', $project))->assertOk();
        $this->actingAs($this->ventasApprover)->post(route('ai.tasks.store', $project), ['title' => 'X', 'column' => 'todo'])->assertForbidden();

        $comprasProject = $this->project(['area_id' => $this->compras->id]);
        $this->actingAs($this->ventasApprover)->get(route('ai.projects.show', $comprasProject))->assertForbidden();

        // Assigned on a task: may open it.
        $task = $this->task($comprasProject, 'todo', [], [$this->juan]);
        $this->actingAs($this->juan)->get(route('ai.tasks.show', [$comprasProject, $task]))->assertOk();
    }

    public function test_board_returns_exactly_the_contract_props_and_filters(): void
    {
        $project = $this->project();

        $this->assertSame(
            $this->withShared(['project', 'tasks', 'total_tasks', 'blocked_total', 'column_limits', 'filters', 'people', 'labels', 'selected_task', 'running_timer', 'now']),
            $this->propKeys(route('ai.projects.board', $project)),
        );

        $mine = $this->task($project, 'in_progress', ['title' => 'Consulta SAP', 'labels' => ['SAP', 'Backend'], 'due_date' => '2026-10-18'], [$this->jhon]);
        $blocked = $this->task($project, 'todo', ['labels' => ['Frontend']], [$this->superAdmin]);
        $requirement = $this->requirement($project, ['title' => 'Acceso SAP', 'area_name' => 'Contabilidad', 'blocks_task_id' => $blocked->id]);
        $this->task($project, 'done');

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Projects/Board', false)
                ->where('project', [
                    'key' => $project->key,
                    'name' => $project->name,
                    'status' => 'planned',
                    'company_id' => $this->segurex->id,
                    'area' => ['id' => $this->ventas->id, 'name' => 'Ventas'],
                    'request_keys' => [],
                    'open_requirements' => 1,
                ])
                ->has('tasks', 3)
                ->where('total_tasks', 3)
                ->where('blocked_total', 1)
                ->where('column_limits', ['in_progress' => 3])
                ->where('filters', ['assignee_id' => null, 'label' => null, 'blocked' => false])
                ->where('labels', ['Backend', 'Frontend', 'SAP'])
                ->where('selected_task', null)
                ->where('running_timer', null)
                // Board order: column, then position (Por hacer before En progreso).
                ->where('tasks.0.key', $blocked->key)
                ->has('tasks.1', fn (Assert $task) => $task
                    ->where('key', $mine->key)
                    ->where('project_key', $project->key)
                    ->where('title', 'Consulta SAP')
                    ->where('column', 'in_progress')
                    ->where('labels', ['SAP', 'Backend'])
                    ->where('assignees.0.name', 'Jhon Castro')
                    ->where('due_at', '2026-10-18')
                    ->where('subtasks_done', 0)
                    ->where('subtasks_total', 0)
                    ->where('blocked_by', null)
                    ->where('github', null)
                    ->where('timer', null)
                    ->where('position', 1)
                )
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', [$project, 'blocked' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks', 1)
                ->where('total_tasks', 3)
                ->where('filters.blocked', true)
                ->where('tasks.0.blocked_by', ['id' => $requirement->id, 'title' => 'Acceso SAP', 'area_name' => 'Contabilidad'])
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', [$project, 'assignee_id' => $this->jhon->id, 'label' => 'SAP']))
            ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.key', $mine->key));
    }

    public function test_board_selected_task_partial_and_task_page(): void
    {
        $project = $this->project();
        $task = $this->task($project, 'in_progress', ['title' => 'Consulta SAP', 'description' => 'Traer la cartera', 'started_at' => '2026-10-05 10:00:00'], [$this->jhon]);
        $task->subtasks()->create(['title' => 'Conectar', 'done' => true, 'position' => 1]);
        $task->subtasks()->create(['title' => 'Filtrar', 'done' => false, 'position' => 2]);
        $task->comments()->create(['user_id' => $this->jhon->id, 'body' => 'Voy con esto']);
        $this->hours($project, $this->jhon, 2.5, $task);
        $this->hours($project, $this->jhon, 1, $task, now()->subDay()->toDateString());

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', [$project, 'tarea' => $task->key]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_task.key', $task->key)
                ->where('selected_task.description', 'Traer la cartera')
                ->where('selected_task.subtasks_done', 1)
                ->where('selected_task.subtasks_total', 2)
                ->where('selected_task.subtasks.0', fn ($subtask) => $subtask['title'] === 'Conectar' && $subtask['done'] === true)
                ->where('selected_task.criteria', [])
                ->where('selected_task.comments.0.body', 'Voy con esto')
                ->where('selected_task.comments.0.is_it', true)
                ->where('selected_task.time_by_person.0.hours', 3.5)
                ->where('selected_task.attachments', [])
                ->where('selected_task.started_at', '2026-10-05')
            );

        $this->actingAs($this->jhon)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Ai/Projects/Board',
                'X-Inertia-Partial-Data' => 'selected_task',
            ])
            ->get(route('ai.projects.board', [$project, 'tarea' => 'T99']))
            ->assertOk()
            ->assertJsonPath('props.selected_task', null);
        $this->flushHeaders();

        $this->assertSame(
            $this->withShared(['project', 'task', 'today_project_hours', 'now']),
            $this->propKeys(route('ai.tasks.show', [$project, $task])),
        );

        $this->actingAs($this->jhon)
            ->get(route('ai.tasks.show', [$project, $task]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Tasks/Show', false)
                ->where('project', ['key' => $project->key, 'name' => $project->name, 'company_id' => $this->segurex->id])
                ->where('task.key', $task->key)
                ->where('today_project_hours', 2.5)
            );
    }
}
