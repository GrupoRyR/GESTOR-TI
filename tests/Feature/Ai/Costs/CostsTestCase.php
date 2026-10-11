<?php

namespace Tests\Feature\Ai\Costs;

use App\Enums\Ai\RequestStatus;
use App\Models\AiEstimate;
use App\Models\AiInteraction;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Tests\Feature\Ai\Projects\ProjectsTestCase;

/**
 * Time and costs (B6). The clock is fixed on Wednesday 7 Oct 2026, 10:00
 * Bogotá (week 5–11 Oct), unless a test travels.
 */
abstract class CostsTestCase extends ProjectsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'America/Bogota'));
    }

    /**
     * A project "En desarrollo" coming from a request, with an approved
     * estimate (hours, cost and due date).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function activeProject(float $hours = 10, ?int $cost = 1_000_000, string $due = '2026-10-31', array $attributes = []): AiProject
    {
        $project = $this->project([
            'status' => RequestStatus::InDevelopment,
            'approved_at' => CarbonImmutable::parse('2026-09-30 09:00', 'America/Bogota'),
            'due_date' => $due,
            ...$attributes,
        ]);
        $request = $this->request(['status' => RequestStatus::InDevelopment, 'evaluated_by_id' => $this->jhon->id]);
        $project->requests()->attach($request->id);
        AiEstimate::create([
            'ai_request_id' => $request->id,
            'ai_project_id' => $project->id,
            'hours_total' => $hours,
            'external_costs' => 0,
            'total_cost' => $cost,
            'due_date' => $due,
        ]);

        return $project;
    }

    protected function originRequest(AiProject $project): AiRequest
    {
        /** @var AiRequest $request */
        $request = $project->requests()->firstOrFail();

        return $request;
    }

    protected function member(AiProject $project, User $user, string $role = 'Desarrollo'): void
    {
        $project->members()->create(['user_id' => $user->id, 'role' => $role]);
    }

    /**
     * A user without `ai.manage` who works on projects (team member).
     */
    protected function developer(): User
    {
        return $this->user($this->segurex, 'Usuario final', 'Camilo Dev');
    }

    protected function interaction(Model $source, float $usd, string $at): AiInteraction
    {
        $interaction = AiInteraction::create([
            'company_id' => $this->segurex->id,
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'feature' => 'test',
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'cost' => $usd,
            'currency' => 'USD',
            'status' => 'ok',
        ]);
        $interaction->forceFill(['created_at' => CarbonImmutable::parse($at, 'America/Bogota')])->save();

        return $interaction;
    }
}
