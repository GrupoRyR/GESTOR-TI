<?php

namespace App\Support\Ai\Presenters;

use App\Domain\Ai\ApprovalRounds;
use App\Domain\Ai\Money;
use App\Domain\Ai\Roadmap;
use App\Domain\Ai\Timeline;
use App\Enums\Ai\EffortSize;
use App\Models\AiRequest;
use App\Models\User;

/**
 * "Esperan tu aprobación": `ApprovalSummary` rows and `ApprovalDetail`.
 * The estimate is the snapshot of the current round (what was sent to
 * approval). Costs and payback only for `ai.costs.view`.
 */
final class ApprovalPresenter
{
    public function __construct(private readonly Roadmap $roadmap) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(AiRequest $request, User $viewer): array
    {
        $round = ApprovalRounds::current($request);
        $estimate = TriagePresenter::estimateFromSnapshot((array) ($round->first()?->estimate ?? []), $viewer->can('ai.costs.view'));

        return [
            'key' => $request->key,
            'title' => $request->title,
            'company_id' => (int) $request->company_id,
            'requested_at' => ($round->first()?->requested_at ?? $request->updated_at ?? now())->toIso8601String(),
            'hours_total' => $estimate['hours_total'],
            'total_cost' => $estimate['total_cost'],
            'due_date' => $estimate['due_date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(AiRequest $request, User $viewer): array
    {
        $request->loadMissing([...RequestPresenter::RELATIONS, 'approvals.approver.employee', 'approvals.requestedBy.employee', 'evaluatedBy.employee']);
        $round = ApprovalRounds::current($request);
        $withCosts = $viewer->can('ai.costs.view');
        $snapshot = (array) ($round->first()?->estimate ?? []);
        $estimate = TriagePresenter::estimateFromSnapshot($snapshot, $withCosts);
        $evaluator = $request->evaluatedBy ?? $round->first()?->requestedBy ?? $request->requester;
        $teamHours = $request->teamHours();

        return [
            'request' => [
                ...RequestPresenter::summary($request, $this->roadmap->rank($request)),
                'problem' => $request->problem,
            ],
            'estimate' => $estimate,
            'approval' => RequesterDetailPresenter::approval($request),
            'evaluation' => [
                'by' => RequestPresenter::person($evaluator),
                'at' => ($request->evaluated_at ?? $request->created_at)->toIso8601String(),
                'urgency' => $request->urgency?->value,
                'size' => ($request->size ?? EffortSize::M)->value,
                'priority' => (int) ($request->final_priority ?? $request->priority_score ?? 0),
            ],
            'hours_saved_per_week' => $teamHours === null ? null : RequestPresenter::number(round($teamHours, 2)),
            'payback_months' => $withCosts ? Money::paybackMonths($request, isset($snapshot['total_cost']) ? (int) $snapshot['total_cost'] : null) : null,
            'history' => Timeline::forIt($request),
        ];
    }
}
