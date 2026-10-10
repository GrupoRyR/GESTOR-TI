<?php

namespace App\Mcp\Tools;

use App\Actions\Ai\AddProgressNote;
use App\Mcp\Tools\Concerns\InteractsWithGestor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Progress note (App\Actions\Ai\AddProgressNote): a task comment when a
 * task is given, else an entry of the project's activity feed.
 * AiProjectPolicy::manage; credentials refused.
 */
#[Name('agregar_nota_avance')]
#[Description('Agrega una nota de avance a un proyecto del Gestor IA (P-n). Con tarea (T-n) queda como comentario de la tarea (hasta 5000 caracteres); sin tarea va al feed de actividad del proyecto (hasta 200 caracteres). Para notas largas del proyecto usa adjuntar_documento. No acepta contraseñas ni llaves.')]
#[IsDestructive(false)]
class AgregarNotaAvance extends Tool
{
    use InteractsWithGestor;

    public function handle(Request $request, AddProgressNote $notes): Response
    {
        $user = $this->actor($request);
        $data = $request->validate([
            'proyecto' => ['required', 'string', 'max:30'],
            'tarea' => ['nullable', 'string', 'max:20'],
            'nota' => ['required', 'string'],
        ], [
            'proyecto.*' => 'Indica la clave del proyecto, por ejemplo P-3.',
            'tarea.*' => 'Indica la clave de la tarea, por ejemplo T2.',
            'nota.*' => 'Escribe la nota de avance.',
        ]);

        $project = $this->project($data['proyecto']);

        if (Gate::forUser($user)->denies('manage', $project)) {
            return Response::error("No tienes permiso para agregar notas en {$project->key}.");
        }

        $task = filled($data['tarea'] ?? null) ? $this->task($project, $data['tarea']) : null;
        $notes->handle($project, $task, $data['nota'], $user);

        $this->audit($task ?? $project, $user, 'mcp_progress_note', "{$project->key}".($task !== null ? " {$task->key}" : '').': nota de avance desde Claude', [
            'project' => $project->key,
            'task' => $task?->key,
        ]);

        return Response::json([
            'proyecto' => $project->key,
            'tarea' => $task?->key,
            'guardada_en' => $task !== null ? 'comentarios de la tarea' : 'actividad del proyecto',
            'url' => $task !== null ? route('ai.tasks.show', [$project->key, $task->key]) : route('ai.projects.show', $project->key),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'proyecto' => $schema->string()->description('Clave del proyecto, por ejemplo P-3.')->required(),
            'tarea' => $schema->string()->description('Clave de la tarea (opcional), por ejemplo T2.'),
            'nota' => $schema->string()->description('Texto de la nota de avance.')->required(),
        ];
    }
}
