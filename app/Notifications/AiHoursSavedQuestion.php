<?php

namespace App\Notifications;

use App\Models\AiRequest;
use Illuminate\Support\Facades\Route;

/**
 * 30 days after the delivery (PROMPT 6.4.5): "¿cuántas horas te ahorra por
 * semana?", event `ai.rating.hours`, sent once by `ai:ratings-lifecycle`.
 */
class AiHoursSavedQuestion extends GestorNotification
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
        return 'ai.rating.hours';
    }

    public function title(): string
    {
        return '¿Cuántas horas te ahorra por semana?';
    }

    public function body(): string
    {
        return "Ya pasó un mes desde que entregamos «{$this->requestTitle}» ({$this->requestKey}). Una sola pregunta: ¿cuántas horas te ahorra por semana? Así comparamos el ahorro estimado con el real.";
    }

    public function actionUrl(): ?string
    {
        return Route::has('ai.ratings.hours.create') ? route('ai.ratings.hours.create', $this->requestKey) : null;
    }

    public function actionText(): string
    {
        return 'Responder';
    }
}
