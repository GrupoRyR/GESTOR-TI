<?php

namespace App\Notifications;

use App\Models\AiRequest;
use App\Support\Mail\RequestEmails;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;

/**
 * "Llegó una solicitud nueva" to the IT team of the request's company
 * (event `ai.request.created`, CreateRequest). Mail: the `new-request`
 * template.
 */
class AiRequestCreated extends GestorNotification
{
    public function __construct(
        public readonly string $requestKey,
        public readonly string $requestTitle,
        public readonly string $areaName,
    ) {}

    public static function for(AiRequest $request): self
    {
        return (new self($request->key, $request->title, (string) $request->area?->name))->forCompany($request->company_id);
    }

    public function eventType(): string
    {
        return 'ai.request.created';
    }

    public function title(): string
    {
        return "{$this->requestKey} · Nueva solicitud";
    }

    public function body(): string
    {
        return "Llegó una solicitud nueva de {$this->areaName}: «{$this->requestTitle}».";
    }

    public function actionUrl(): ?string
    {
        return Route::has('ai.requests.show') ? route('ai.requests.show', $this->requestKey) : null;
    }

    public function actionText(): string
    {
        return 'Ver solicitud';
    }

    public function toMail(object $notifiable): MailMessage|Mailable
    {
        $request = self::findRequest($this->requestKey);

        return $request === null
            ? parent::toMail($notifiable)
            : $this->templateMail($notifiable, RequestEmails::newRequest($request));
    }
}
