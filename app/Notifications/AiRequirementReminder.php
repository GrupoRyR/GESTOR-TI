<?php

namespace App\Notifications;

use App\Models\AiRequirement;
use Illuminate\Support\Facades\Route;

/**
 * Reminder of a pending requirement ("Recordar" and the daily
 * `ai:requirement-reminders`). Same event as AiRequirementRequested.
 */
class AiRequirementReminder extends GestorNotification
{
    public function __construct(
        public readonly int $requirementId,
        public readonly string $requirementTitle,
        public readonly string $projectName,
        public readonly string $dueDate,
        public readonly bool $overdue,
    ) {}

    public static function for(AiRequirement $requirement): self
    {
        $requirement->loadMissing('project');

        return (new self(
            $requirement->id,
            $requirement->title,
            (string) $requirement->project?->name,
            $requirement->due_date->format('d/m/Y'),
            $requirement->displayStatus() === AiRequirement::OVERDUE,
        ))->forCompany($requirement->company_id);
    }

    public function eventType(): string
    {
        return 'ai.request.status_changed';
    }

    public function title(): string
    {
        return $this->overdue
            ? "Recordatorio: «{$this->requirementTitle}» ya venció"
            : "Recordatorio: TI espera «{$this->requirementTitle}»";
    }

    public function body(): string
    {
        return "Es para el proyecto «{$this->projectName}» y vence el {$this->dueDate}. Si no te corresponde, puedes pasárselo a otra persona.";
    }

    public function actionUrl(): ?string
    {
        return Route::has('ai.requirements.respond') ? route('ai.requirements.respond', $this->requirementId) : null;
    }

    public function actionText(): string
    {
        return 'Responder';
    }
}
