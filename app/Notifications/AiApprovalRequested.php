<?php

namespace App\Notifications;

use App\Enums\Ai\ApprovalKind;
use App\Models\AiRequest;
use Illuminate\Support\Facades\Route;

/**
 * "Una solicitud espera tu aprobación" (area approver or project approver).
 * The Telegram ✅/❌ buttons for project approvals arrive with B4
 * (CallbackRegistry); this carries the link to the approval page.
 */
class AiApprovalRequested extends GestorNotification
{
    public function __construct(
        public readonly string $requestKey,
        public readonly string $requestTitle,
        public readonly ApprovalKind $kind,
    ) {}

    public static function for(AiRequest $request, ApprovalKind $kind): self
    {
        return (new self($request->key, $request->title, $kind))->forCompany($request->company_id);
    }

    public function eventType(): string
    {
        return 'ai.approval.requested';
    }

    public function title(): string
    {
        return $this->kind === ApprovalKind::Area
            ? "{$this->requestKey} espera el visto bueno de tu área"
            : "{$this->requestKey} espera tu aprobación";
    }

    public function body(): string
    {
        return "«{$this->requestTitle}»";
    }

    public function actionUrl(): ?string
    {
        if ($this->kind === ApprovalKind::Project && Route::has('ai.approvals.show')) {
            return route('ai.approvals.show', $this->requestKey);
        }

        return Route::has('ai.requests.show') ? route('ai.requests.show', $this->requestKey) : null;
    }

    public function actionText(): string
    {
        return 'Revisar';
    }
}
