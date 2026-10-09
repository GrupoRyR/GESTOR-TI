<?php

namespace App\Notifications;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use Illuminate\Support\Facades\Route;

/**
 * "Tu solicitud cambió de estado" to the requester. Carries only public
 * data (key, title, status, the public note of the move): never internal
 * notes, costs or estimates.
 */
class AiRequestStatusChanged extends GestorNotification
{
    public function __construct(
        public readonly string $requestKey,
        public readonly string $requestTitle,
        public readonly RequestStatus $status,
        public readonly ?string $note = null,
    ) {}

    public static function for(AiRequest $request, ?string $note = null): self
    {
        return (new self($request->key, $request->title, $request->status, $note))->forCompany($request->company_id);
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
}
