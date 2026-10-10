<?php

namespace Tests\Feature\Ai\Projects;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

class ConvertToProjectTest extends ProjectsTestCase
{
    public function test_an_approved_request_becomes_a_planned_project(): void
    {
        Notification::fake();
        $request = $this->evaluated(['title' => 'Visualizador de cartera', 'expected_result' => 'Ver la cartera por asesor']);
        $request->benefitedAreas()->attach($this->compras->id, ['is_primary' => false, 'note' => 'compras la usa']);
        $this->sendToApproval($request, ['approver_ids' => [$this->edward->id], 'due_date' => '2026-12-15']);
        $this->actingAs($this->edward)->post(route('ai.approvals.approve', $request), ['comment' => null]);
        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.convert', $request), ['name' => 'Visualizador · filtros', 'start_date' => '2026-10-12'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('ai.projects.show', 'P-1'));

        $project = AiProject::query()->sole();
        $this->assertSame('P-1', $project->key);
        $this->assertSame('Visualizador · filtros', $project->name);
        $this->assertSame('Ver la cartera por asesor', $project->objective);
        $this->assertSame(RequestStatus::Planned, $project->status);
        $this->assertSame($this->segurex->id, $project->company_id);
        $this->assertSame($this->ventas->id, $project->area_id);
        $this->assertSame('2026-10-12', $project->start_date->toDateString());
        $this->assertSame('2026-12-15', $project->due_date->toDateString());
        $this->assertNotNull($project->approved_at);
        $this->assertSame([$request->id], $project->requests()->pluck('ai_requests.id')->all());
        $this->assertSame([$this->compras->id], $project->benefitedAreas()->pluck('areas.id')->all());
        $this->assertSame($project->id, $request->estimates()->latest('id')->first()->ai_project_id);
        $this->assertSame(5, $project->columns()->count());
        $this->assertEqualsCanonicalizing([$this->jhon->id, $this->superAdmin->id], $project->members()->pluck('user_id')->all());
        $this->assertSame(RequestStatus::Planned, $request->fresh()->status);

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('request.project_key', 'P-1'));

        // Once in a project it cannot be converted again.
        $this->actingAs($this->jhon)
            ->post(route('ai.requests.convert', $request), ['name' => 'Otra vez', 'start_date' => '2026-10-12'])
            ->assertSessionHas('error');
        $this->assertSame(1, AiProject::query()->count());
    }

    public function test_only_approved_work_converts(): void
    {
        $evaluating = $this->evaluated();

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.convert', $evaluating), ['name' => 'Algo', 'start_date' => '2026-10-12'])
            ->assertSessionHas('error');
        $this->assertSame(0, AiProject::query()->count());

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.convert', $evaluating), ['name' => '', 'start_date' => ''])
            ->assertSessionHasErrors(['name', 'start_date']);

        $this->actingAs($this->paola)
            ->post(route('ai.requests.convert', $evaluating), ['name' => 'Algo', 'start_date' => '2026-10-12'])
            ->assertForbidden();
    }

    public function test_a_parked_approved_request_converts_from_the_backlog(): void
    {
        Notification::fake();
        $request = $this->evaluated();
        $this->sendToApproval($request, ['approver_ids' => [$this->edward->id]]);
        $this->actingAs($this->edward)->post(route('ai.approvals.approve', $request), ['comment' => null]);
        $this->actingAs($this->jhon)->post(route('ai.requests.backlog', $request), ['note' => null]);
        $this->assertSame(RequestStatus::Backlog, $request->fresh()->status);

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.convert', $request), ['name' => 'Desde backlog', 'start_date' => '2026-10-12'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::Planned, $request->fresh()->status);

        // A backlog request never approved does not.
        $parked = $this->request(['status' => RequestStatus::Backlog, 'first_responded_at' => now()]);
        $this->actingAs($this->jhon)
            ->post(route('ai.requests.convert', $parked), ['name' => 'Sin aprobar', 'start_date' => '2026-10-12'])
            ->assertSessionHas('error');
    }
}
