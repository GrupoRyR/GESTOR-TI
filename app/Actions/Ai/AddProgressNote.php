<?php

namespace App\Actions\Ai;

use App\Domain\Ai\SecretGuard;
use App\Models\AiProject;
use App\Models\AiProjectActivity;
use App\Models\AiTask;
use App\Models\AiTaskComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A progress note ("nota de avance", MCP tool `agregar_nota_avance`) through
 * the mechanisms the app already shows:
 *
 * - On a task: a task comment (as the board's "Comentar", up to
 *   TASK_MAX) plus "comentó en T3 Título" in the activity feed.
 * - On the project: an entry of its "Actividad" feed (`comment`,
 *   "nota de avance: …", up to PROJECT_MAX so it fits the feed).
 *
 * Notes that look like a credential are refused (SecretGuard).
 * Authorization (AiProjectPolicy::manage) is the caller's.
 */
class AddProgressNote
{
    public const TASK_MAX = 5000;

    public const PROJECT_MAX = 200;

    public const SECRET_MESSAGE = 'La nota parece tener una contraseña o una llave. Las credenciales van en la bóveda, nunca en las notas ni en las tareas.';

    public function __construct(private readonly SecretGuard $secrets) {}

    public function handle(AiProject $project, ?AiTask $task, string $note, User $actor): AiTaskComment|AiProjectActivity
    {
        $note = trim($note);
        $max = $task === null ? self::PROJECT_MAX : self::TASK_MAX;

        if ($note === '') {
            throw ValidationException::withMessages(['nota' => 'Escribe la nota de avance.']);
        }

        if (mb_strlen($note) > $max) {
            throw ValidationException::withMessages(['nota' => $task === null
                ? 'Sin tarea, la nota va al feed de actividad del proyecto y puede tener hasta '.self::PROJECT_MAX.' caracteres. Para notas largas indica una tarea o usa adjuntar_documento.'
                : 'La nota puede tener hasta '.self::TASK_MAX.' caracteres.']);
        }

        if ($this->secrets->find($note) !== null) {
            throw ValidationException::withMessages(['nota' => self::SECRET_MESSAGE]);
        }

        if ($task === null) {
            return $project->recordActivity('comment', "nota de avance: {$note}", $actor);
        }

        return DB::transaction(function () use ($project, $task, $note, $actor): AiTaskComment {
            /** @var AiTaskComment $comment */
            $comment = $task->comments()->create(['user_id' => $actor->id, 'body' => $note]);
            $project->recordActivity('comment', "comentó en {$task->key} {$task->title}", $actor);

            return $comment;
        });
    }
}
