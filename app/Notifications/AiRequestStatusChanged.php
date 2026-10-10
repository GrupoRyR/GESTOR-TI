<?php

namespace App\Notifications;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Support\Mail\RequestEmails;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * "Tu solicitud cambió de estado" to the requester. Carries only public
 * data (key, title, status, the public note of the move): never internal
 * notes, costs or estimates.
 *
 * Mail: the `status-changed` template; a move to "Necesita información"
 * with its question (the note) is the `it-question` template instead.
 */
class AiRequestStatusChanged extends GestorNotification
{
    public readonly string $sentAt;

    public function __construct(
        public readonly string $requestKey,
        public readonly string $requestTitle,
        public readonly RequestStatus $status,
        public readonly ?string $note = null,
        public readonly ?string $actorName = null,
    ) {
        $this->sentAt = now()->toIso8601String();
    }

    public static function for(AiRequest $request, ?string $note = null, ?string $actorName = null): self
    {
        return (new self($request->key, $request->title, $request->status, $note, $actorName))->forCompany($request->company_id);
    }

    public function eventType(): string
    {
        return 'ai.request.status_changed';
    }

    public function title(): string
    {
        return "{$this->requestKey} · {$this->status->label()}";
    }

    public function body(): string
    {
        $body = "Tu solicitud «{$this->requestTitle}» ahora está: {$this->status->label()}.";

        return $this->note !== null && $this->note !== '' ? $body."\n\n".$this->note : $body;
    }

    public function actionUrl(): ?string
    {
        return Route::has('ai.requests.show') ? route('ai.requests.show', $this->requestKey) : null;
    }

    public function actionText(): string
    {
        return 'Ver mi solicitud';
    }

    public function toMail(object $notifiable): MailMessage|Mailable
    {
        $request = self::findRequest($this->requestKey);

        if ($request === null) {
            return parent::toMail($notifiable);
        }

        $mail = $this->status === RequestStatus::NeedsInfo && $this->note !== null && $this->note !== ''
            ? RequestEmails::itQuestion($request, $this->note, $this->actorName ?? 'TI', Carbon::parse($this->sentAt))
            : RequestEmails::statusChanged($request, $this->status);

        return $this->templateMail($notifiable, $mail);
    }
}
