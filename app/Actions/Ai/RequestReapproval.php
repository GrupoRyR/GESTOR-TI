<?php

namespace App\Actions\Ai;

use App\Domain\Ai\ApprovalRounds;
use App\Domain\Ai\CostRates;
use App\Domain\Ai\ProjectBudget;
use App\Domain\Ai\ProjectCosts;
use App\Domain\Ai\TriagePeople;
use App\Enums\Ai\ApprovalKind;
use App\Models\AiEstimate;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\AiTimeEntry;
use App\Models\User;
use App\Notifications\AiProjectReapprovalRequested;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Pedir nueva aprobación" (`ai.projects.reapproval.store`, PROMPT 6.4.11
 * "un aumento de más del 20 % se vuelve a aprobar"):
 *
 * - IT (manage) asks while the budget says `needs_reapproval` and no round
 *   is open. The project must come from a request: the round reuses the B4
 *   approval rows of that request (`is_reapproval`), so the approvals page,
 *   its detail and the Telegram ✅/❌ buttons work unchanged. The request
 *   keeps its status; the project is not blocked meanwhile.
 * - The new estimate (not linked to the project until approved): hours
 *   logged per person plus the remaining hours on the lead, rates in force
 *   today, the other real costs as external costs, and the projected due
 *   date (never earlier than the current one). `total_cost` is null while
 *   somebody has no rate.
 * - Every project approver gets AiProjectReapprovalRequested (event
 *   `ai.project.reapproval`); the project's "Actividad" and the request's
 *   timeline record it.
 */
class RequestReapproval
{
    public function __construct(
        private readonly ProjectBudget $budget,
        private readonly ProjectCosts $costs,
        private readonly TriagePeople $people,
        private readonly CostRates $rates,
    ) {}

    /**
     * @return Collection<int, AiRequestApproval>
     */
    public function handle(AiProject $project, User $actor): Collection
    {
        Gate::forUser($actor)->authorize('manage', $project);

        $figures = $this->budget->figures($project);
        $request = $project->originRequest();

        if (! $figures['needs_reapproval']) {
            throw ValidationException::withMessages(['project' => 'El proyecto sigue dentro de lo aprobado: no hace falta una nueva aprobación.']);
        }

        if ($request === null) {
            throw ValidationException::withMessages(['project' => 'El proyecto no viene de una solicitud: no hay una aprobación que repetir.']);
        }

        if (ApprovalRounds::reapprovalPending($request)) {
            throw ValidationException::withMessages(['project' => 'Ya pediste la nueva aprobación; falta la decisión de los aprobadores.']);
        }

        $approvers = $this->people->approvers();

        if ($approvers->isEmpty()) {
            throw ValidationException::withMessages(['project' => 'No hay aprobadores de proyectos. Agrégalos en Administración › Costos y aprobaciones.']);
        }

        $rows = DB::transaction(function () use ($project, $actor, $request, $figures, $approvers): Collection {
            $estimate = $this->estimate($project, $request, $actor, $figures);
            $snapshot = $estimate->toSnapshot();
            $requestedAt = Carbon::now();

            $rows = $approvers->map(fn (User $approver) => $request->approvals()->create([
                'kind' => ApprovalKind::Project,
                'user_id' => $approver->id,
                'estimate' => $snapshot,
                'require_all' => false,
                'is_reapproval' => true,
                'requested_by_id' => $actor->id,
                'requested_at' => $requestedAt,
            ])->setRelation('approver', $approver));

            $project->forceFill(['reapproval_requested_at' => $requestedAt])->save();

            $due = CarbonImmutable::parse($estimate->due_date->toDateString())->locale('es')->translatedFormat('j M');
            $project->recordActivity('budget', 'pidió una nueva aprobación: '.ProjectCosts::hours((float) $estimate->hours_total).", entrega {$due}", $actor);
            $request->recordEvent(
                'approval',
                'amber',
                "{$actor->name} pidió una nueva aprobación de {$project->key}",
                $actor,
                null,
                'aprobadores: '.SendToApproval::join($approvers->pluck('name')->all()),
            );

            return $rows;
        });

        foreach ($rows as $row) {
            $row->approver?->notify(AiProjectReapprovalRequested::forProject($project, $request, $row));
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $figures
     */
    private function estimate(AiProject $project, AiRequest $request, User $actor, array $figures): AiEstimate
    {
        /** @var array<int, float> $lines user id => hours */
        $lines = $project->timeEntries()->get(['user_id', 'hours'])
            ->groupBy('user_id')
            ->map(fn (Collection $entries) => round($entries->sum(fn (AiTimeEntry $entry) => (float) $entry->hours), 2))
            ->all();

        $remaining = (float) $figures['remaining_hours'];

        if ($remaining > 0) {
            $owner = $project->lead_id ?? $actor->id;
            $lines[$owner] = ($lines[$owner] ?? 0) + $remaining;
        }

        $rates = $this->rates->ratesFor(array_keys($lines));
        $known = $lines !== [] && count(array_intersect_key($lines, $rates)) === count($lines);
        $external = max(0, (int) $figures['actual_cost'] - $this->costs->teamCost($project));
        $team = 0.0;

        foreach ($lines as $userId => $hours) {
            $team += $hours * ($rates[$userId] ?? 0);
        }

        $due = max((string) $figures['projected_due_date'], (string) $figures['due_date']);

        $estimate = $request->estimates()->create([
            'hours_total' => round(array_sum($lines), 1),
            'external_costs' => $external,
            'total_cost' => $known ? (int) round($team + $external) : null,
            'due_date' => $due,
            'created_by_id' => $actor->id,
        ]);

        foreach ($lines as $userId => $hours) {
            $estimate->lines()->create(['user_id' => $userId, 'hours' => $hours, 'hourly_rate' => $rates[$userId] ?? null]);
        }

        return $estimate;
    }
}
