<?php

namespace App\Notifications;

use App\Models\AiRequirement;
use App\Support\Mail\ProjectEmails;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;

/**
 * IT needs something from the person (PROMPT 6.4.3): sent when a
 * requirement is created for them or passed to them by a coworker. Uses
 * the event `ai.requirement.requested` (mail, Telegram and bell by default).
 * No costs. Mail: the approved `requirement` template.
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

    /**
     * The approved `requirement` email, built from the requirement read
     * fresh when the queued mail leaves (the default layout if it is gone).
     */
    public function toMail(object $notifiable): MailMessage|Mailable
    {
        $requirement = AiRequirement::withoutGlobalScopes()->find($this->requirementId);

        if ($requirement === null) {
            return parent::toMail($notifiable);
        }

        return $this->templateMail($notifiable, ProjectEmails::requirement($requirement));
    }
}
