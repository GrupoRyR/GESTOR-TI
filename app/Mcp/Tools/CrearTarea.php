<?php

namespace App\Mcp\Tools;

use App\Actions\Ai\AddProgressNote;
use App\Actions\Ai\CreateTask;
use App\Domain\Ai\Board;
use App\Mcp\Tools\Concerns\InteractsWithGestor;
use App\Mcp\Tools\Concerns\WritesGestor;
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
 * New board task (App\Actions\Ai\CreateTask) with the board's rules:
 * AiProjectPolicy::manage, columns from Board, assignees from BoardPeople;
 * the request key must be one of the project's requests. Title and
 * description that look like a credential are refused.
 */
#[Name('crear_tarea')]
#[Description('Crea una tarea en el tablero de un proyecto del Gestor IA (clave P-n). Va al final de la columna (por defecto "Por hacer"). Opcional: descripción, responsable (correo de alguien del equipo de TI o del proyecto), horas estimadas y la solicitud GIA-n del proyecto de la que sale. Devuelve la clave de la tarea (T-n) y su enlace. No acepta contraseñas ni llaves.')]
#[IsDestructive(false)]
class CrearTarea extends Tool
{
    use InteractsWithGestor;
    use WritesGestor;

    public function handle(Request $request, CreateTask $create): Response
    {
        $user = $this->actor($request);
        $data = $request->validate([
            'proyecto' => ['required', 'string', 'max:30'],
            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:20000'],
            'columna' => ['nullable', Rule::in(Board::keys())],
            'responsable' => ['nullable', 'string', 'max:255'],
            'horas_estimadas' => ['nullable', 'numeric', 'min:0.5', 'max:999'],
            'solicitud' => ['nullable', 'string', 'max:30'],
        ], [
            'proyecto.*' => 'Indica la clave del proyecto, por ejemplo P-3.',
            'titulo.required' => 'Escribe el nombre de la tarea.',
            'titulo.*' => 'El nombre de la tarea puede tener hasta 255 caracteres.',
            'descripcion.*' => 'La descripción puede tener hasta 20000 caracteres.',
            'columna.*' => 'Esa columna no existe. Usa: '.implode(', ', Board::keys()).'.',
            'responsable.*' => 'Indica el correo del responsable.',
            'horas_estimadas.*' => 'Las horas estimadas van de 0,5 a 999.',
            'solicitud.*' => 'Indica la clave de la solicitud, por ejemplo GIA-139.',
        ]);

        $project = $this->project($data['proyecto']);

        if (Gate::forUser($user)->denies('manage', $project)) {
            return Response::error("No tienes permiso para crear tareas en {$project->key}.");
        }

        $this->assertNoSecrets(
            ['titulo' => $data['titulo'], 'descripcion' => $data['descripcion'] ?? null],
            AddProgressNote::SECRET_MESSAGE,
        );

        $requestKey = null;

        if (filled($data['solicitud'] ?? null)) {
            $requestKey = strtoupper(trim($data['solicitud']));

            if (! $project->requests()->where('ai_requests.key', $requestKey)->exists()) {
                return Response::error("La solicitud {$requestKey} no está vinculada a {$project->key}.");
            }
        }

        $task = $create->handle(
            $project,
            $data['columna'] ?? Board::TODO,
            $data['titulo'],
            $user,
            filled($data['descripcion'] ?? null) ? $data['descripcion'] : null,
            $this->assignees($project, $data['responsable'] ?? null),
            isset($data['horas_estimadas']) ? (float) $data['horas_estimadas'] : null,
            $requestKey,
        );
        $task->load(['column', 'assignees']);

        $this->audit($task, $user, 'mcp_task_created', "{$project->key} {$task->key}: tarea creada desde Claude", [
            'project' => $project->key,
            'task' => $task->key,
            'request' => $requestKey,
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
            'titulo' => $schema->string()->max(255)->description('Nombre de la tarea.')->required(),
            'descripcion' => $schema->string()->description('Descripción en Markdown (opcional).'),
            'columna' => $schema->string()->enum(Board::keys())->description('Columna: backlog, todo (Por hacer, por defecto), in_progress, review o done.'),
            'responsable' => $schema->string()->description('Correo del responsable (equipo de TI o del proyecto).'),
            'horas_estimadas' => $schema->number()->description('Horas estimadas (0,5 a 999).'),
            'solicitud' => $schema->string()->description('Clave GIA-n de la solicitud del proyecto de la que sale la tarea.'),
        ];
    }
}
