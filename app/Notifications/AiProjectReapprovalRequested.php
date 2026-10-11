<?php

namespace App\Notifications;

use App\Enums\Ai\ApprovalKind;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use Illuminate\Support\Carbon;

/**
 * "P-07 necesita una nueva aprobación" (App\Actions\Ai\RequestReapproval),
 * event `ai.project.reapproval`. Same approval row, page and Telegram
 * ✅ Aprobar / ❌ Rechazar buttons as AiApprovalRequested; the summary
 * names hours and due date only (costs stay on the page).
 */
class AiProjectReapprovalRequested extends AiApprovalRequested
{
    public ?string $projectKey = null;

    public static function forProject(AiProject $project, AiRequest $request, AiRequestApproval $approval): self
    {
        $estimate = (array) ($approval->estimate ?? []);
        $summary = null;

        if (isset($estimate['hours_total'], $estimate['due_date'])) {
            $due = Carbon::parse((string) $estimate['due_date'])->locale('es')->translatedFormat('j M Y');
            $summary = "Nuevo estimado: {$estimate['hours_total']} h · entrega {$due}";
        }

        $notification = new self($request->key, $project->name, ApprovalKind::Project, $approval->id, $summary);
        $notification->projectKey = $project->key;

        return $notification->forCompany($project->company_id);
    }

    public function eventType(): string
    {
        return 'ai.project.reapproval';
    }

    public function title(): string
    {
        return ($this->projectKey ?? $this->requestKey).' necesita una nueva aprobación';
    }

    public function body(): string
    {
        return "«{$this->requestTitle}» pasó lo aprobado ({$this->requestKey}).".($this->summary !== null ? "\n{$this->summary}" : '');
    }
}
