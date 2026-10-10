<?php

namespace App\Notifications;

use App\Models\AiRequest;
use App\Support\Mail\RequestEmails;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * Reminder to the requester when IT waits on them: an open question
 * ("Necesita información") or the validation of the acceptance criteria
 * ("En pruebas"). Uses the event of their own requests
 * (`ai.request.status_changed`), so their channel choice applies. Public
 * data only.
 *
 * Mail: an open question is the `it-question` template; the validation
 * reminder keeps the default layout (no approved template for it).
 */
class AiRequestReminder extends GestorNotification
{
    public const QUESTION = 'question';

    public const VALIDATION = 'validation';

    public readonly string $sentAt;

    public function __construct(
        public readonly string $requestKey,
        public readonly string $requestTitle,
        public readonly string $reason,
        public readonly ?string $question = null,
        public readonly ?string $askerName = null,
    ) {
        $this->sentAt = now()->toIso8601String();
    }

    public static function question(AiRequest $request, ?string $question, ?string $askerName = null): self
    {
        return (new self($request->key, $request->title, self::QUESTION, $question, $askerName))->forCompany($request->company_id);
    }

    public static function validation(AiRequest $request): self
    {
        return (new self($request->key, $request->title, self::VALIDATION))->forCompany($request->company_id);
    }

    public function eventType(): string
    {
        return 'ai.request.status_changed';
    }

    public function title(): string
    {
        return $this->reason === self::VALIDATION
            ? "{$this->requestKey} · falta tu validación"
            : "{$this->requestKey} · TI espera tu respuesta";
    }

    public function body(): string
    {
        if ($this->reason === self::VALIDATION) {
            return "Ya puedes probar «{$this->requestTitle}». Revisa los criterios y cuéntanos si quedó bien.";
        }

        $body = "Para seguir con «{$this->requestTitle}» necesitamos tu respuesta.";

        return $this->question !== null && $this->question !== '' ? $body."\n\n{$this->question}" : $body;
    }

    public function actionUrl(): ?string
    {
        $route = $this->reason === self::VALIDATION ? 'ai.requests.validation.edit' : 'ai.requests.show';

        return Route::has($route) ? route($route, $this->requestKey) : null;
    }

    public function actionText(): string
    {
        return $this->reason === self::VALIDATION ? 'Validar' : 'Responder';
    }

    public function toMail(object $notifiable): MailMessage|Mailable
    {
        $request = $this->reason === self::QUESTION && $this->question !== null && $this->question !== ''
            ? self::findRequest($this->requestKey)
            : null;

        return $request === null || $this->question === null
            ? parent::toMail($notifiable)
            : $this->templateMail($notifiable, RequestEmails::itQuestion($request, $this->question, $this->askerName ?? 'TI', Carbon::parse($this->sentAt)));
    }
}
