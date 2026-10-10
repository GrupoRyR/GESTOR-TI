<?php

namespace App\Notifications;

use App\Models\AiRequirement;
use App\Support\Mail\ProjectEmails;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;

/**
 * Reminder of a pending requirement ("Recordar" and the daily
 * `ai:requirement-reminders`). Same event as AiRequirementRequested.
 * Mail: the `requirement` template with the number of this reminder
 * (built before `reminders_sent` is incremented).
 */
class AiRequirementReminder extends GestorNotification
{
    public function __construct(
        public readonly int $requirementId,
        public readonly string $requirementTitle,
        public readonly string $projectName,
        public readonly string $dueDate,
        public readonly bool $overdue,
        public readonly int $reminderNumber = 1,
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
            $requirement->reminders_sent + 1,
        ))->forCompany($requirement->company_id);
    }

    public function eventType(): string
    {
        return 'ai.requirement.requested';
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

        return $this->templateMail($notifiable, ProjectEmails::requirement($requirement, $this->reminderNumber));
    }
}
