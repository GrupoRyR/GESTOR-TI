<?php

namespace App\Notifications;

use App\Models\AiRequest;
use Illuminate\Support\Facades\Route;

/**
 * Reminder to the requester when IT waits on them: an open question
 * ("Necesita información") or the validation of the acceptance criteria
 * ("En pruebas"). Uses the event of their own requests
 * (`ai.request.status_changed`), so their channel choice applies. Public
 * data only.
 */
class AiRequestReminder extends GestorNotification
{
    public const QUESTION = 'question';

    public const VALIDATION = 'validation';

    public function __construct(
        public readonly string $requestKey,
        public readonly string $requestTitle,
        public readonly string $reason,
        public readonly ?string $question = null,
    ) {}

    public static function question(AiRequest $request, ?string $question): self
    {
        return (new self($request->key, $request->title, self::QUESTION, $question))->forCompany($request->company_id);
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
}
