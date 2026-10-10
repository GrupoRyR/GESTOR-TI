<?php

namespace App\Notifications;

use App\Models\AiRequirement;
use Illuminate\Support\Facades\Route;

/**
 * IT needs something from the person (PROMPT 6.4.3): sent when a
 * requirement is created for them or passed to them by a coworker. Uses
 * the event `ai.requirement.requested` (mail, Telegram and bell by default).
 * No costs, no task names.
 */
class AiRequirementRequested extends GestorNotification
{
    public function __construct(
        public readonly int $requirementId,
        public readonly string $requirementTitle,
        public readonly string $projectName,
        public readonly string $dueDate,
        public readonly ?string $requestedBy,
    ) {}

    public static function for(AiRequirement $requirement): self
    {
        $requirement->loadMissing(['project', 'requestedBy']);

        return (new self(
            $requirement->id,
            $requirement->title,
            (string) $requirement->project?->name,
            $requirement->due_date->format('d/m/Y'),
            $requirement->requestedBy?->name,
        ))->forCompany($requirement->company_id);
    }

    public function eventType(): string
    {
        return 'ai.requirement.requested';
    }

    public function title(): string
    {
        return "TI te pide: {$this->requirementTitle}";
    }

    public function body(): string
    {
        $who = $this->requestedBy !== null ? "{$this->requestedBy} (TI)" : 'TI';

        return "{$who} lo necesita para el proyecto «{$this->projectName}». Respóndelo antes del {$this->dueDate}.";
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
