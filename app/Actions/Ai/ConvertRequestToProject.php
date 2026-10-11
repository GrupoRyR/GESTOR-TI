<?php

namespace App\Actions\Ai;

use App\Domain\Ai\ApprovalRounds;
use App\Domain\Ai\InvalidTransition;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\RequestStatus;
use App\Models\AiEstimateLine;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Convertir en proyecto" (`ai.requests.convert`, PROMPT 6.4.3): an
 * approved request — or one approved and then parked in the backlog —
 * becomes a project "Planificada" with the name and start date TI chose.
 *
 * - Copies company, area, benefited areas, product and objective (the
 *   expected result, else the problem) from the request.
 * - Due date and approval date come from the approved round; the estimate
 *   of that round is linked to the project (`ai_estimates.ai_project_id`).
 * - Team: the request's assignee (else the actor) as "Líder del
 *   proyecto", plus the people of the estimate as "Desarrollo".
 * - The base documentation is ready from the templates (PROMPT 6.4.3
 *   "Plantilla de proyecto nuevo", App\Domain\Ai\DocumentTemplates).
 * - The request moves to "Planificada" (TransitionRequest, requester
 *   notified). A request belongs to one project only.
 *
 * Projects are not created by the approval itself (App\Events\RequestApproved):
 * TI decides when and with which name, as the design's dialog does.
 */
class ConvertRequestToProject
{
    public function __construct(
        private readonly TransitionRequest $transition,
        private readonly Documents $documents,
    ) {}

    public function handle(AiRequest $request, User $actor, string $name, CarbonImmutable $startDate): AiProject
    {
        Gate::forUser($actor)->authorize('transition', $request);

        if ($request->projects()->exists()) {
            throw new InvalidTransition("La solicitud {$request->key} ya es parte del proyecto {$request->projectKey()}.");
        }

        $round = ApprovalRounds::current($request);
        $approved = ApprovalRounds::decided($round);
        $isApproved = $approved !== null && $approved->decision === ApprovalDecision::Approved;

        if (! ($request->status === RequestStatus::Approved || ($request->status === RequestStatus::Backlog && $isApproved))) {
            throw new InvalidTransition("La solicitud {$request->key} necesita la aprobación del proyecto antes de convertirse en proyecto.");
        }

        return DB::transaction(function () use ($request, $actor, $name, $startDate, $approved): AiProject {
            $estimate = $request->estimates()->reorder()->latest('id')->first();

            $project = AiProject::create([
                'company_id' => $request->company_id,
                'area_id' => $request->area_id,
                'name' => mb_substr(trim($name), 0, 160),
                'objective' => $request->expected_result ?: $request->problem,
                'status' => RequestStatus::Planned,
                'lead_id' => $request->assignee_id ?? $actor->id,
                'product_id' => $request->product_id,
                'start_date' => $startDate->toDateString(),
                'due_date' => $estimate?->due_date?->toDateString(),
                'approved_at' => $approved?->decided_at ?? now(),
                'created_by_id' => $actor->id,
            ]);

            $project->requests()->attach($request->id);

            $benefited = $request->benefitedAreas()->wherePivot('is_primary', false)->get();
            foreach ($benefited as $area) {
                if ((int) $area->id !== (int) $request->area_id) {
                    $project->benefitedAreas()->attach($area->id, ['note' => $area->pivot?->getAttribute('note')]);
                }
            }

            $position = 0;
            $project->members()->create(['user_id' => $project->lead_id, 'role' => 'Líder del proyecto', 'position' => $position++]);

            if ($estimate !== null) {
                $estimate->forceFill(['ai_project_id' => $project->id])->save();

                $estimate->lines()->whereNotNull('user_id')->get()
                    ->each(function (AiEstimateLine $line) use ($project, &$position): void {
                        $project->members()->firstOrCreate(['user_id' => $line->user_id], ['role' => 'Desarrollo', 'position' => $position++]);
                    });
            }

            $this->documents->createProjectBase($project, $actor);

            $this->transition->apply($request, RequestStatus::Planned, $actor, "Ahora es el proyecto {$project->key}.");

            $project->recordActivity('task', "creó el proyecto desde {$request->key}", $actor);

            return $project;
        });
    }
}
