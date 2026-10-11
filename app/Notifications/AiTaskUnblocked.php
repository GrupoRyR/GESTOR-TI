<?php

namespace App\Notifications;

use App\Models\AiProject;
use App\Models\AiRequirement;
use App\Models\AiTask;
use Illuminate\Support\Facades\Route;

/**
 * To the people assigned to a task when the requirement that blocked it
 * is answered (PROMPT 6.4.3 "bloqueos visibles… y avisa"). Informative
 * event `ai.task.unblocked` (Telegram and bell by default).
 */
class AiTaskUnblocked extends GestorNotification
{
    public function __construct(
        public readonly string $projectKey,
        public readonly string $taskKey,
        public readonly string $taskTitle,
        public readonly string $requirementTitle,
        public readonly string $answeredBy,
    ) {}

    public static function for(AiProject $project, AiTask $task, AiRequirement $requirement, string $answeredBy): self
    {
        return (new self($project->key, $task->key, $task->title, $requirement->title, $answeredBy))->forCompany($project->company_id);
    }

    public function eventType(): string
    {
        return 'ai.task.unblocked';
    }

    public function title(): string
    {
        return "{$this->projectKey} · {$this->taskKey} ya no está bloqueada";
    }

    public function body(): string
    {
        return "{$this->answeredBy} respondió «{$this->requirementTitle}». Puedes seguir con «{$this->taskTitle}».";
    }

    public function actionUrl(): ?string
    {
        return Route::has('ai.tasks.show') ? route('ai.tasks.show', ['project' => $this->projectKey, 'task' => $this->taskKey]) : null;
    }

    public function actionText(): string
    {
        return 'Abrir la tarea';
    }
}
