<?php

namespace App\Actions\Ai;

use App\Domain\Ai\ProjectCosts;
use App\Enums\Ai\ApprovalDecision;
use App\Models\AiEstimate;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Decision on a project re-approval (called by RecordApprovalDecision,
 * web or Telegram). The request status never changes.
 *
 * - Approved: the estimate of the round (snapshot `estimate_id`) becomes
 *   the project's estimate, its due date the project's due date and the
 *   approval date restarts the schedule; the budget alerts start over.
 * - Rejected: the project keeps the estimate it had; the comment goes to
 *   the "Actividad" feed. IT may ask again later.
 *
 * Either way the round closes (`reapproval_requested_at` = null).
 */
class DecideReapproval
{
    public function __construct(private readonly WatchProjectBudget $watch) {}

    public function handle(AiRequest $request, User $approver, AiRequestApproval $approval, ApprovalDecision $decision, ?string $comment): void
    {
        $project = AiProject::query()
            ->withoutGlobalScopes()
            ->whereHas('requests', fn ($query) => $query->whereKey($request->id))
            ->first();

        if ($project === null) {
            return;
        }

        $project->forceFill(['reapproval_requested_at' => null]);

        if ($decision === ApprovalDecision::Rejected) {
            $project->save();
            $project->recordActivity('budget', 'no aprobó el nuevo estimado'.($comment !== null ? ": «{$comment}»" : ''), $approver);
            $request->recordEvent('approval', 'red', "{$approver->name} no aprobó la nueva aprobación de {$project->key}", $approver, $comment);

            return;
        }

        $estimateId = (int) (((array) $approval->estimate)['estimate_id'] ?? 0);
        $estimate = AiEstimate::query()->find($estimateId);

        if ($estimate !== null) {
            $estimate->forceFill(['ai_project_id' => $project->id])->save();
            $project->due_date = $estimate->due_date;
        }

        $project->approved_at = now();
        $project->save();

        $summary = $estimate === null ? '' : ': '.ProjectCosts::hours((float) $estimate->hours_total).', entrega '
            .CarbonImmutable::parse($estimate->due_date->toDateString())->locale('es')->translatedFormat('j M');
        $project->recordActivity('budget', 'aprobó el nuevo estimado'.$summary, $approver);
        $request->recordEvent('approval', 'green', "{$approver->name} aprobó la nueva aprobación de {$project->key}", $approver, $comment);

        DB::afterCommit(function () use ($project): void {
            $this->watch->reset($project);
            $this->watch->handle($project);
        });
    }
}
