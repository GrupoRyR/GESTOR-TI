<?php

namespace Tests\Feature\Mcp;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use App\Models\AiTask;
use Spatie\Activitylog\Models\Activity;

/**
 * `crear_tarea` and `actualizar_tarea`: the board's rules (AiProjectPolicy
 * manage, BoardPeople, MoveTask), SecretGuard and the `api` audit trail.
 */
class TaskToolsTest extends McpToolsTestCase
{
    public function test_crear_tarea_creates_the_task_like_the_board(): void
    {
        $result = $this->toolResult($this->tool('crear_tarea', [
            'proyecto' => strtolower($this->project->key),
            'titulo' => '  Exportar a PDF ',
            'descripcion' => 'El reporte por asesor se descarga en PDF.',
            'columna' => 'review',
            'responsable' => $this->jhon->email,
            'horas_estimadas' => 6.5,
            'solicitud' => 'GIA-139',
        ]));

        /** @var AiTask $task */
        $task = AiTask::query()->where('ai_project_id', $this->project->id)->latest('id')->firstOrFail();
        $this->assertSame([
            'proyecto' => $this->project->key,
            'tarea' => $task->key,
            'titulo' => 'Exportar a PDF',
            'columna' => 'En revisión',
            'responsables' => ['Jhon Castro'],
            'url' => route('ai.tasks.show', [$this->project->key, $task->key]),
        ], $result);
        $this->assertSame('El reporte por asesor se descarga en PDF.', $task->description);
        $this->assertSame('6.5', $task->estimate_hours);
        $this->assertSame($this->jhon->id, $task->created_by_id);
        $this->assertSame([$this->jhon->id], $task->assignees()->pluck('users.id')->all());
        $this->assertDatabaseHas('ai_project_activities', [
            'ai_project_id' => $this->project->id, 'kind' => 'task', 'user_id' => $this->jhon->id,
            'text' => "creó {$task->key} Exportar a PDF (GIA-139)",
        ]);

        /** @var Activity $audit */
        $audit = Activity::query()->where('event', 'mcp_task_created')->sole();
        $this->assertSame('ai', $audit->log_name);
        $this->assertSame($this->jhon->id, $audit->causer_id);
        $this->assertTrue($audit->subject->is($task));
        $this->assertSame('api', $audit->properties['origin']);
        $this->assertSame('crear_tarea', $audit->properties['tool']);
    }

    public function test_crear_tarea_defaults_to_por_hacer(): void
    {
        $result = $this->toolResult($this->tool('crear_tarea', ['proyecto' => $this->project->key, 'titulo' => 'Pruebas']));

        $this->assertSame('Por hacer', $result['columna']);
        $this->assertSame([], $result['responsables']);
    }

    public function test_crear_tarea_validates_in_spanish(): void
    {
        $this->assertStringContainsString('nombre de la tarea', $this->toolError($this->tool('crear_tarea', ['proyecto' => $this->project->key])));
        $this->assertStringContainsString('columna', $this->toolError($this->tool('crear_tarea', ['proyecto' => $this->project->key, 'titulo' => 'X', 'columna' => 'luna'])));
        $this->assertSame('No encontré el proyecto P-999.', $this->toolError($this->tool('crear_tarea', ['proyecto' => 'P-999', 'titulo' => 'X'])));
        $this->assertStringContainsString('equipo', $this->toolError($this->tool('crear_tarea', [
            'proyecto' => $this->project->key, 'titulo' => 'X', 'responsable' => $this->paola->email,
        ])));
        $this->assertStringContainsString('GIA-200', $this->toolError($this->tool('crear_tarea', [
            'proyecto' => $this->project->key, 'titulo' => 'X', 'solicitud' => 'GIA-200',
        ])));
        $this->assertSame(2, AiTask::query()->count());
    }

    public function test_crear_tarea_refuses_credentials(): void
    {
        $error = $this->toolError($this->tool('crear_tarea', [
            'proyecto' => $this->project->key, 'titulo' => 'Conectar SAP', 'descripcion' => 'usuario sap / password: Cartera2026!',
        ]));

        $this->assertStringContainsString('bóveda', $error);
        $this->assertSame(2, AiTask::query()->count());
    }

    public function test_writes_need_the_manage_policy_and_the_company(): void
    {
        $other = AiProject::factory()->create(['company_id' => $this->derivados->id, 'name' => 'Cartera Derivados']);
        $restricted = $this->connectorUser('TI Segurex', ['ai.manage']);
        $viewer = $this->connectorUser('Conector lector', ['companies.view_all', 'ai.requests.create']);

        $this->assertSame("No encontré el proyecto {$other->key}.", $this->toolError($this->tool('crear_tarea', ['proyecto' => $other->key, 'titulo' => 'X'], $restricted)));
        $this->assertStringContainsString('permiso', $this->toolError($this->tool('crear_tarea', ['proyecto' => $this->project->key, 'titulo' => 'X'], $viewer)));
        $this->assertStringContainsString('permiso', $this->toolError($this->tool('actualizar_tarea', [
            'proyecto' => $this->project->key, 'tarea' => $this->t2->key, 'titulo' => 'Y',
        ], $viewer)));
        $this->assertSame(2, AiTask::query()->count());
        $this->assertSame('Endpoint de zonas', $this->t2->fresh()->title);
    }

    public function test_actualizar_tarea_changes_fields_and_moves_through_move_task(): void
    {
        $result = $this->toolResult($this->tool('actualizar_tarea', [
            'proyecto' => $this->project->key,
            'tarea' => strtolower($this->t2->key),
            'titulo' => 'Endpoint de zonas y asesores',
            'descripcion' => 'Incluye el filtro por asesor.',
            'columna' => 'done',
            'responsable' => $this->jhon->email,
        ]));

        $task = $this->t2->fresh();
        $this->assertSame('Endpoint de zonas y asesores', $task->title);
        $this->assertSame('Incluye el filtro por asesor.', $task->description);
        $this->assertSame('done', $task->column->key);
        $this->assertNotNull($task->completed_at);
        $this->assertSame([$this->jhon->id], $task->assignees()->pluck('users.id')->all());
        $this->assertSame('Hecho', $result['columna']);
        $this->assertDatabaseHas('ai_project_activities', [
            'ai_project_id' => $this->project->id, 'text' => "movió {$task->key} Endpoint de zonas y asesores a Hecho",
        ]);

        $audit = Activity::query()->where('event', 'mcp_task_updated')->sole();
        $this->assertSame('api', $audit->properties['origin']);
        $this->assertEqualsCanonicalizing(['titulo', 'descripcion', 'columna', 'responsable'], $audit->properties['campos']);
    }

    public function test_moving_the_last_open_task_to_done_moves_the_project_to_testing(): void
    {
        $this->tool('actualizar_tarea', ['proyecto' => $this->project->key, 'tarea' => $this->t1->key, 'columna' => 'done']);
        $this->tool('actualizar_tarea', ['proyecto' => $this->project->key, 'tarea' => $this->t2->key, 'columna' => 'done']);

        $this->assertSame(RequestStatus::InTesting, $this->project->fresh()->status);
        $this->assertSame(RequestStatus::InTesting, $this->gia139->fresh()->status);
    }

    public function test_actualizar_tarea_can_clear_the_assignee(): void
    {
        $this->toolResult($this->tool('actualizar_tarea', ['proyecto' => $this->project->key, 'tarea' => $this->t1->key, 'responsable' => '']));

        $this->assertSame([], $this->t1->assignees()->pluck('users.id')->all());
    }

    public function test_actualizar_tarea_validates_and_refuses_credentials(): void
    {
        $this->assertStringContainsString('qué cambiar', $this->toolError($this->tool('actualizar_tarea', ['proyecto' => $this->project->key, 'tarea' => $this->t2->key])));
        $this->assertSame("No encontré la tarea T99 en {$this->project->key}.", $this->toolError($this->tool('actualizar_tarea', [
            'proyecto' => $this->project->key, 'tarea' => 'T99', 'titulo' => 'X',
        ])));
        $this->assertStringContainsString('bóveda', $this->toolError($this->tool('actualizar_tarea', [
            'proyecto' => $this->project->key, 'tarea' => $this->t2->key, 'descripcion' => 'token ghp_abcdefghijklmnopqrstuvwxyz0123456789',
        ])));
        $this->assertNull($this->t2->fresh()->description);
        $this->assertSame(0, Activity::query()->where('event', 'mcp_task_updated')->count());
    }
}
