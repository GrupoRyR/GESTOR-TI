<?php

namespace Tests\Feature\Ai\Projects;

use App\Enums\Ai\RequestStatus;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

class WeekAndHomeProjectsTest extends ProjectsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 7 Oct 2026, 09:40 Bogotá (week ends Sunday 11 Oct).
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:40', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_week_shows_due_and_blocked_tasks_and_waiting_requirements(): void
    {
        $project = $this->project(['status' => RequestStatus::InDevelopment]);
        $due = $this->task($project, 'review', ['title' => 'Endpoint de zonas', 'due_date' => '2026-10-08'], [$this->superAdmin]);
        $this->task($project, 'todo', ['title' => 'Más adelante', 'due_date' => '2026-10-20']);
        $this->task($project, 'done', ['title' => 'Hecha', 'due_date' => '2026-10-06']);
        $blocked = $this->task($project, 'in_progress', ['title' => 'Conectar API'], [$this->jhon]);
        $blocking = $this->requirement($project, ['title' => 'API de WooCommerce', 'area_name' => 'Mercadeo', 'blocks_task_id' => $blocked->id, 'created_at' => '2026-10-01 10:00:00']);
        $waiting = $this->requirement($project, ['title' => 'Lista de zonas', 'created_at' => '2026-10-05 10:00:00']);
        $this->requirement($project, ['title' => 'Respondido', 'status' => 'answered', 'answer' => 'Ok', 'answered_at' => now()]);

        $this->actingAs($this->jhon)
            ->get(route('ai.week.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 3)
                ->has('items.0', fn (Assert $item) => $item
                    ->where('id', 'task-'.$due->id)
                    ->where('column', 'due')
                    ->where('company_id', $this->segurex->id)
                    ->where('ref_label', "{$project->key} · {$due->key}")
                    ->where('title', 'Endpoint de zonas')
                    ->where('person', 'Leonardo Herrera')
                    ->where('due_at', '2026-10-08')
                    ->where('since', null)
                    ->where('reason', null)
                    ->where('target', ['type' => 'task', 'project_key' => $project->key, 'task_key' => $due->key])
                )
                ->has('items.1', fn (Assert $item) => $item
                    ->where('id', 'blocked-'.$blocked->id)
                    ->where('column', 'blocked')
                    ->where('ref_label', "{$project->key} · {$blocked->key}")
                    ->where('title', 'Conectar API')
                    ->where('person', 'Jhon Castro')
                    ->where('due_at', null)
                    ->where('since', $blocking->created_at->toIso8601String())
                    ->where('reason', 'Espera el requerimiento “API de WooCommerce” a Mercadeo')
                    ->where('target', ['type' => 'requirement', 'id' => $blocking->id, 'area_name' => 'Mercadeo'])
                    ->etc()
                )
                ->has('items.2', fn (Assert $item) => $item
                    ->where('id', 'requirement-'.$waiting->id)
                    ->where('column', 'waiting')
                    ->where('ref_label', $project->key)
                    ->where('title', 'Lista de zonas')
                    ->where('person', 'Paola Ríos')
                    ->where('since', $waiting->created_at->toIso8601String())
                    ->where('target', ['type' => 'requirement', 'id' => $waiting->id, 'area_name' => 'Ventas'])
                    ->etc()
                )
            );
    }

    public function test_area_board_counts_and_lists_projects(): void
    {
        $project = $this->project(['status' => RequestStatus::InDevelopment]);
        $request = $this->request(['status' => RequestStatus::InDevelopment, 'first_responded_at' => now()]);
        $project->requests()->attach($request->id);
        $this->requirement($project, ['title' => 'Lista de zonas', 'area_name' => 'Cartera', 'due_date' => '2026-10-01']);
        $this->project(['status' => RequestStatus::Closed]);

        $this->actingAs($this->jhon)
            ->get(route('ai.areas.index', ['company_id' => $this->segurex->id, 'area_id' => $this->ventas->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('areas', fn ($areas) => collect($areas)->firstWhere('area.id', $this->ventas->id)['projects_in_progress'] === 1)
                ->has('selected_area.projects', 1)
                ->where('selected_area.projects.0', [
                    'key' => $project->key,
                    'name' => $project->name,
                    'request_key' => $request->key,
                    'status' => 'in_development',
                    'hours' => ['estimate' => 0, 'actual' => 0],
                    'cost' => ['estimate' => 0, 'actual' => 0],
                    'note' => 'Espera «Lista de zonas» de Cartera, vencido',
                    'note_level' => 'late',
                ])
            );

        $this->actingAs($this->itWithoutCosts())
            ->get(route('ai.areas.index', ['company_id' => $this->segurex->id, 'area_id' => $this->ventas->id]))
            ->assertInertia(fn (Assert $page) => $page->where('selected_area.projects.0.cost', null));
    }

    public function test_home_attention_lists_overdue_tasks_and_waiting_requirements(): void
    {
        $project = $this->project(['status' => RequestStatus::InDevelopment]);
        $late = $this->task($project, 'in_progress', ['title' => 'Conectar API', 'due_date' => '2026-10-04']);
        $this->task($project, 'todo', ['title' => 'A tiempo', 'due_date' => '2026-10-20']);
        $this->task($project, 'done', ['title' => 'Hecha', 'due_date' => '2026-10-01']);
        $overdue = $this->requirement($project, ['title' => 'API de WooCommerce', 'area_name' => 'Mercadeo', 'due_date' => '2026-10-01', 'created_at' => '2026-10-01 10:00:00']);
        $pending = $this->requirement($project, ['title' => 'Lista de zonas', 'due_date' => '2026-10-15', 'created_at' => '2026-10-05 10:00:00']);
        $other = $this->project(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);
        $this->task($other, 'todo', ['title' => 'De Derivados', 'due_date' => '2026-10-01']);

        $this->actingAs($this->jhon)
            ->withSession(['current_company_id' => $this->segurex->id])
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('attention.overdue_tasks', 1)
                ->where('attention.overdue_tasks.0', [
                    'id' => 'task-'.$late->id,
                    'title' => "{$project->key} · {$late->key} · vencida hace 3 días",
                    'detail' => 'Conectar API',
                    'href_route' => 'ai.tasks.show',
                    'href_params' => ['project' => $project->key, 'task' => $late->key],
                    'severity' => 'late',
                ])
                ->has('attention.waiting_requirements', 2)
                ->where('attention.waiting_requirements.0', [
                    'id' => 'requirement-'.$overdue->id,
                    'title' => 'API de WooCommerce · Mercadeo',
                    'detail' => 'Paola Ríos · pedido hace 6 días',
                    'href_route' => 'ai.requirements.show',
                    'href_params' => ['requirement' => $overdue->id],
                    'severity' => 'late',
                ])
                ->where('attention.waiting_requirements.1.id', 'requirement-'.$pending->id)
                ->where('attention.waiting_requirements.1.severity', 'attention')
            );
    }
}
