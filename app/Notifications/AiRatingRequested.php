<?php

namespace App\Notifications;

use App\Models\AiRequest;
use App\Models\User;
use App\Support\Mail\RequestEmails;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;

/**
 * "Califica lo que te entregamos" (PROMPT 6.4.5), event `ai.rating.requested`:
 * when a request reaches "En producción" (instead of the plain status
 * notice, with the same public note) and the reminder 3 days later
 * (`ai:ratings-lifecycle`). Public data only.
 *
 * Mail: the `delivered` template, with the five signed one-click rating
 * links, for the notice and the reminder.
 */
class AiRatingRequested extends GestorNotification
{
    public function __construct(
        public readonly string $requestKey,
        public readonly string $requestTitle,
        public readonly bool $reminder,
        public readonly ?string $note = null,
    ) {}

    public static function for(AiRequest $request, bool $reminder = false, ?string $note = null): self
    {
        return (new self($request->key, $request->title, $reminder, $note))->forCompany($request->company_id);
    }

    public function eventType(): string
    {
        return 'ai.rating.requested';
    }

    public function title(): string
    {
        return $this->reminder
            ? "{$this->requestKey} · ¿Ya calificaste la entrega?"
            : "{$this->requestKey} · Ya está en producción";
    }

    public function body(): string
    {
        $body = $this->reminder
            ? "Hace 3 días entregamos «{$this->requestTitle}». Cuéntanos cómo te fue: toma menos de un minuto. Si no la calificas en 7 días, la cerramos sin calificación."
            : "Tu solicitud «{$this->requestTitle}» ya está en producción. Califica de 1 a 5 si resolvió tu necesidad y qué tan fácil es de usar.";

        return $this->note !== null && $this->note !== '' ? $body."\n\n".$this->note : $body;
    }

    public function actionUrl(): ?string
    {
        return Route::has('ai.ratings.create') ? route('ai.ratings.create', $this->requestKey) : null;
    }

    public function actionText(): string
    {
        return 'Calificar';
    }

    public function toMail(object $notifiable): MailMessage|Mailable
    {
        $request = $notifiable instanceof User ? self::findRequest($this->requestKey) : null;

        return $request === null || ! $notifiable instanceof User
            ? parent::toMail($notifiable)
            : $this->templateMail($notifiable, RequestEmails::delivered($request, $notifiable));
    }
}
