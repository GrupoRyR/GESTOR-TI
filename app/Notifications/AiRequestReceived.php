<?php

namespace App\Notifications;

use App\Models\AiRequest;
use App\Support\Mail\RequestEmails;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;

/**
 * "Recibimos tu solicitud" to the requester right after it is filed
 * (CreateRequest). Uses the event of their own requests
 * (`ai.request.status_changed`), so their channel choice applies. Mail:
 * the `request-received` template. Public data only.
 */
class AiRequestReceived extends GestorNotification
{
    public function __construct(
        public readonly string $requestKey,
        public readonly string $requestTitle,
    ) {}

    public static function for(AiRequest $request): self
    {
        return (new self($request->key, $request->title))->forCompany($request->company_id);
    }

    public function eventType(): string
    {
        return 'ai.request.status_changed';
    }

    public function title(): string
    {
        return "{$this->requestKey} · Recibimos tu solicitud";
    }

    public function body(): string
    {
        return "Recibimos «{$this->requestTitle}». TI te da una primera respuesta en los próximos días hábiles.";
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

        return $request === null
            ? parent::toMail($notifiable)
            : $this->templateMail($notifiable, RequestEmails::requestReceived($request));
    }
}
