<?php

namespace App\Notifications;

use App\Enums\Ai\ApprovalKind;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Notifications\Messages\TelegramMessage;
use App\Telegram\Handlers\ApprovalCallbackHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * "Una solicitud espera tu aprobación" (area approver or project approver).
 *
 * Project approvals (`forApproval()`) carry the approver's own row id: the
 * Telegram message gets ✅ Aprobar / ❌ Rechazar buttons (signed callback
 * data, App\Telegram\Handlers\ApprovalCallbackHandler) plus "Ver detalle"
 * (`ai.approvals.show?desde=telegram`). The summary line names hours and
 * due date only: costs stay on the page (`ai.costs.view`).
 */
class AiApprovalRequested extends GestorNotification
{
    public function __construct(
        public readonly string $requestKey,
        public readonly string $requestTitle,
        public readonly ApprovalKind $kind,
        public readonly ?int $approvalId = null,
        public readonly ?string $summary = null,
    ) {}

    public static function for(AiRequest $request, ApprovalKind $kind): self
    {
        return (new self($request->key, $request->title, $kind))->forCompany($request->company_id);
    }

    public static function forApproval(AiRequest $request, AiRequestApproval $approval): self
    {
        $estimate = (array) ($approval->estimate ?? []);
        $summary = null;

        if (isset($estimate['hours_total'], $estimate['due_date'])) {
            $due = Carbon::parse((string) $estimate['due_date'])->locale('es')->translatedFormat('j M Y');
            $summary = "Estimado: {$estimate['hours_total']} h · entrega {$due}";
        }

        return (new self($request->key, $request->title, ApprovalKind::Project, $approval->id, $summary))->forCompany($request->company_id);
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
        return "«{$this->requestTitle}»".($this->summary !== null ? "\n{$this->summary}" : '');
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

    public function toTelegram(object $notifiable): TelegramMessage
    {
        if ($this->kind !== ApprovalKind::Project || $this->approvalId === null) {
            return parent::toTelegram($notifiable);
        }

        return TelegramMessage::make($this->title()."\n\n".$this->body())
            ->row([
                ['text' => '✅ Aprobar', 'callback_data' => ApprovalCallbackHandler::data($this->approvalId, ApprovalCallbackHandler::APPROVE)],
                ['text' => '❌ Rechazar', 'callback_data' => ApprovalCallbackHandler::data($this->approvalId, ApprovalCallbackHandler::REJECT)],
            ])
            ->urlButton('Ver detalle', route('ai.approvals.show', ['request' => $this->requestKey, 'desde' => 'telegram']));
    }
}
