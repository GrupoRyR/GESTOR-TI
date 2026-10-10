<?php

namespace App\Mcp\Tools;

use App\Actions\Ai\AddProgressNote;
use App\Actions\Ai\UpdateTask;
use App\Domain\Ai\Board;
use App\Mcp\Tools\Concerns\InteractsWithGestor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Changes a board task (App\Actions\Ai\UpdateTask): only the given fields;
 * a column change goes through MoveTask (dates, project lifecycle, the
 * request notifications of TransitionRequest). AiProjectPolicy::manage.
 */
#[Name('actualizar_tarea')]
#[Description('Actualiza una tarea de un proyecto del Gestor IA (proyecto P-n y tarea T-n): título, descripción, columna (la mueve al final de esa columna, como en el tablero) o responsable (correo; vacío lo quita). Solo cambia los campos que envíes. No acepta contraseñas ni llaves.')]
#[IsDestructive(false)]
class ActualizarTarea extends Tool
{
    use InteractsWithGestor;

    private const FIELDS = ['titulo', 'descripcion', 'columna', 'responsable'];

    public function handle(Request $request, UpdateTask $update): Response
    {
        $user = $this->actor($request);
        $data = $request->validate([
            'proyecto' => ['required', 'string', 'max:30'],
            'tarea' => ['required', 'string', 'max:20'],
            'titulo' => ['sometimes', 'required', 'string', 'max:255'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'columna' => ['sometimes', 'required', Rule::in(Board::keys())],
            'responsable' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [
            'proyecto.*' => 'Indica la clave del proyecto, por ejemplo P-3.',
            'tarea.*' => 'Indica la clave de la tarea, por ejemplo T2.',
            'titulo.*' => 'La tarea necesita un nombre (hasta 255 caracteres).',
            'descripcion.*' => 'La descripción puede tener hasta 20000 caracteres.',
            'columna.*' => 'Esa columna no existe. Usa: '.implode(', ', Board::keys()).'.',
            'responsable.*' => 'Indica el correo del responsable, o vacío para quitarlo.',
        ]);

        $fields = array_values(array_intersect(self::FIELDS, array_keys($data)));

        if ($fields === []) {
            return Response::error('Indica qué cambiar: titulo, descripcion, columna o responsable.');
        }

        $project = $this->project($data['proyecto']);

        if (Gate::forUser($user)->denies('manage', $project)) {
            return Response::error("No tienes permiso para cambiar las tareas de {$project->key}.");
        }

        $task = $this->task($project, $data['tarea']);
        $this->assertNoSecrets(
            ['titulo' => $data['titulo'] ?? null, 'descripcion' => $data['descripcion'] ?? null],
            AddProgressNote::SECRET_MESSAGE,
        );

        $changes = [];

        if (array_key_exists('titulo', $data)) {
            $changes['title'] = $data['titulo'];
        }

        if (array_key_exists('descripcion', $data)) {
            $changes['description'] = filled($data['descripcion']) ? $data['descripcion'] : null;
        }

        if (array_key_exists('responsable', $data)) {
            $changes['assignee_ids'] = $this->assignees($project, $data['responsable']);
        }

        if (array_key_exists('columna', $data)) {
            $changes['column'] = $data['columna'];
        }

        $task = $update->handle($task, $changes, $user)->load(['column', 'assignees']);

        $this->audit($task, $user, 'mcp_task_updated', "{$project->key} {$task->key}: tarea actualizada desde Claude", [
            'project' => $project->key,
            'task' => $task->key,
            'campos' => $fields,
        ]);

        return Response::json([
            'proyecto' => $project->key,
            'tarea' => $task->key,
            'titulo' => $task->title,
            'columna' => (string) $task->column?->name,
            'responsables' => $task->assignees->pluck('name')->values()->all(),
            'url' => route('ai.tasks.show', [$project->key, $task->key]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'proyecto' => $schema->string()->description('Clave del proyecto, por ejemplo P-3.')->required(),
            'tarea' => $schema->string()->description('Clave de la tarea dentro del proyecto, por ejemplo T2.')->required(),
            'titulo' => $schema->string()->max(255)->description('Nuevo nombre de la tarea.'),
            'descripcion' => $schema->string()->description('Nueva descripción en Markdown (vacía la borra).'),
            'columna' => $schema->string()->enum(Board::keys())->description('Nueva columna: backlog, todo, in_progress, review o done.'),
            'responsable' => $schema->string()->description('Correo del nuevo responsable (equipo de TI o del proyecto); vacío quita el responsable.'),
        ];
    }
}
