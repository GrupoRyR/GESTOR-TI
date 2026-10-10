<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\MoveTask;
use App\Domain\Ai\Board;
use App\Domain\Ai\BoardPeople;
use App\Http\Controllers\Controller;
use App\Models\AiProject;
use App\Models\AiSubtask;
use App\Models\AiTask;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Board actions (types/pages/ai-projects.ts), `ai.manage` (route) and
 * AiProjectPolicy::manage (company access). Every action answers back to
 * the board with the usual flash; validation errors keep their keys.
 *
 * - `ai.tasks.store` `{title, column}`: new task at the end of the column.
 * - `ai.tasks.move` `{column, position}` (1-based): App\Actions\Ai\MoveTask.
 * - `ai.tasks.update` TaskUpdatePayload, only the sent fields; a column
 *   change moves the task to the end of that column (MoveTask).
 *   Assignees must be on the board's people list (team + IT).
 * - Subtasks (`ai.subtasks.store|toggle`), comments, one file per upload
 *   (`ai.tasks.attachments.store`, private disk, request file rules).
 */
class TaskController extends Controller
{
    private const TEXT_MAX = 5000;

    public function __construct(private readonly BoardPeople $people) {}

    public function store(Request $http, AiProject $project): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $data = $http->validate([
            'title' => ['required', 'string', 'max:255'],
            'column' => ['required', Rule::in(Board::keys())],
        ], ['title.required' => 'Escribe el nombre de la tarea.']);

        $task = AiTask::create([
            'ai_project_id' => $project->id,
            'ai_project_column_id' => $project->column($data['column'])->id,
            'title' => trim($data['title']),
            'labels' => [],
            'created_by_id' => $this->user($http)->id,
        ]);

        $project->recordActivity('task', "creó {$task->key} {$task->title}", $this->user($http));

        return back()->with('success', "{$task->key} quedó en el tablero.");
    }

    public function move(Request $http, AiProject $project, AiTask $task, MoveTask $move): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $data = $http->validate([
            'column' => ['required', Rule::in(Board::keys())],
            'position' => ['required', 'integer', 'min:1'],
        ]);

        $move->handle($task, $data['column'], (int) $data['position'], $this->user($http));

        return back();
    }

    public function update(Request $http, AiProject $project, AiTask $task, MoveTask $move): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $people = $this->people->ids($project);
        $data = $http->validate([
            'column' => ['sometimes', Rule::in(Board::keys())],
            'assignee_ids' => ['sometimes', 'array'],
            'assignee_ids.*' => ['integer', Rule::in($people)],
            'due_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'labels' => ['sometimes', 'array', 'max:10'],
            'labels.*' => ['string', 'max:30'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ], [
            'assignee_ids.*.in' => 'Solo puedes asignar a personas del equipo de TI.',
            'title.required' => 'La tarea necesita un nombre.',
        ]);

        DB::transaction(function () use ($task, $data): void {
            if (array_key_exists('title', $data)) {
                $task->title = trim($data['title']);
            }

            if (array_key_exists('description', $data)) {
                $task->description = $data['description'];
            }

            if (array_key_exists('due_at', $data)) {
                $task->due_date = $data['due_at'];
            }

            if (array_key_exists('labels', $data)) {
                $task->labels = collect($data['labels'])
                    ->map(fn ($label) => trim((string) $label))
                    ->filter(fn (string $label) => $label !== '')
                    ->unique()
                    ->values()
                    ->all();
            }

            $task->save();

            if (array_key_exists('assignee_ids', $data)) {
                $task->assignees()->sync(array_values(array_unique(array_map('intval', $data['assignee_ids']))));
            }
        });

        if (isset($data['column']) && $data['column'] !== $task->column?->key) {
            $move->handle($task, $data['column'], null, $this->user($http));
        }

        return back();
    }

    public function comment(Request $http, AiProject $project, AiTask $task): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $data = $http->validate(['body' => ['required', 'string', 'max:'.self::TEXT_MAX]], ['body.required' => 'Escribe el comentario.']);

        $task->comments()->create(['user_id' => $this->user($http)->id, 'body' => trim($data['body'])]);
        $project->recordActivity('comment', "comentó en {$task->key} {$task->title}", $this->user($http));

        return back();
    }

    public function attach(Request $http, AiProject $project, AiTask $task): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $config = (array) config('gestor.ai.attachments');
        $http->validate([
            'file' => ['required', 'file', 'max:'.(int) $config['max_kb'], 'mimetypes:'.implode(',', (array) $config['mimetypes'])],
        ], [
            'file.required' => 'Elige un archivo.',
            'file.max' => 'El archivo puede pesar hasta '.intdiv((int) $config['max_kb'], 1024).' MB.',
            'file.mimetypes' => 'Solo imágenes, PDF, Excel o texto.',
        ]);

        $file = $http->file('file');
        $disk = (string) ($config['disk'] ?? 'local');

        $task->attachments()->create([
            'user_id' => $this->user($http)->id,
            'disk' => $disk,
            'path' => $file->store("ai/projects/{$project->id}/tasks/{$task->id}", $disk),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => (string) ($file->getMimeType() ?? $file->getClientMimeType()),
            'size' => (int) $file->getSize(),
        ]);

        return back()->with('success', 'Archivo adjunto.');
    }

    public function storeSubtask(Request $http, AiProject $project, AiTask $task): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $data = $http->validate(['title' => ['required', 'string', 'max:255']], ['title.required' => 'Escribe la subtarea.']);

        $task->subtasks()->create([
            'title' => trim($data['title']),
            'position' => (int) $task->subtasks()->max('position') + 1,
        ]);

        return back();
    }

    public function toggleSubtask(Request $http, AiProject $project, AiTask $task, AiSubtask $subtask): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $data = $http->validate(['done' => ['required', 'boolean']]);
        $done = (bool) $data['done'];

        $subtask->forceFill([
            'done' => $done,
            'done_at' => $done ? now() : null,
            'done_by_id' => $done ? $this->user($http)->id : null,
        ])->save();

        return back();
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
