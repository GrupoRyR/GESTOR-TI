<?php

namespace App\Notifications;

use App\Models\AiRequirement;
use Illuminate\Support\Facades\Route;

/**
 * To the IT person who asked: the requirement was answered. Informative
 * event `ai.requirement.answered` (bell by default).
 */
class AiRequirementAnswered extends GestorNotification
{
    public function __construct(
        public readonly string $projectKey,
        public readonly string $requirementTitle,
        public readonly string $answeredBy,
    ) {}

    public static function for(AiRequirement $requirement, string $answeredBy): self
    {
        $requirement->loadMissing('project');

        return (new self((string) $requirement->project?->key, $requirement->title, $answeredBy))->forCompany($requirement->company_id);
    }

    public function eventType(): string
    {
        return 'ai.requirement.answered';
    }

    public function title(): string
    {
        return "{$this->projectKey} · respondieron «{$this->requirementTitle}»";
    }

    public function body(): string
    {
        return "{$this->answeredBy} ya respondió el requerimiento.";
    }

    public function actionUrl(): ?string
    {
        return Route::has('ai.projects.requirements') && $this->projectKey !== ''
            ? route('ai.projects.requirements', $this->projectKey)
            : null;
    }

    public function actionText(): string
    {
        return 'Ver requerimientos';
    }
}
