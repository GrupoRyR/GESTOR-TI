<?php

namespace App\Actions\Ai;

use App\Domain\Ai\FirstResponse;
use App\Enums\Ai\EffortSize;
use App\Enums\Ai\RequestStatus;
use App\Enums\Ai\RiskLevel;
use App\Enums\Ai\Urgency;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Guardar evaluación" (EvaluationPayload of the design): size, urgency,
 * risk and optionally a final priority that overrides the suggestion
 * (null = follow the suggestion). The server recomputes the suggestion; it
 * never trusts the client's. Counts as first response and moves a received
 * request to "En evaluación".
 */
class EvaluateRequest
{
    public function __construct(
        private readonly TransitionRequest $transition,
        private readonly FirstResponse $firstResponse,
    ) {}

    public function handle(
        AiRequest $request,
        User $actor,
        ?EffortSize $size,
        ?Urgency $urgency,
        RiskLevel $risk,
        ?int $finalPriority = null,
        ?int $impactMoneyPerMonth = null,
    ): AiRequest {
        Gate::forUser($actor)->authorize('evaluate', $request);

        if ($finalPriority !== null && ($finalPriority < 0 || $finalPriority > 100)) {
            throw ValidationException::withMessages(['priority_final' => 'La prioridad va de 0 a 100.']);
        }

        DB::transaction(function () use ($request, $actor, $size, $urgency, $risk, $finalPriority, $impactMoneyPerMonth): void {
            $request->fill([
                'size' => $size,
                'urgency' => $urgency,
                'risk' => $risk,
                'impact_money_per_month' => $impactMoneyPerMonth,
                'evaluated_by_id' => $actor->id,
                'evaluated_at' => now(),
            ]);

            $request->priority_overridden = $finalPriority !== null;
            $request->recomputePriority();

            if ($finalPriority !== null) {
                $request->final_priority = $finalPriority;
            }

            $this->firstResponse->mark($request);
            $request->save();

            if ($request->status === RequestStatus::Received) {
                $this->transition->apply($request, RequestStatus::Evaluating, $actor);
            }
        });

        return $request;
    }
}
