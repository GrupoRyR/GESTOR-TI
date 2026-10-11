<?php

namespace Tests\Feature\Ai\Triage;

use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

class ItShowTest extends TriageTestCase
{
    public function test_it_view_returns_exactly_the_contract_props(): void
    {
        $request = $this->request();

        $this->assertSame(
            $this->withShared([...self::ACTION_DATA, 'view', 'request', 'evaluation', 'duplicates', 'area_hour_value', 'first_response', 'first_response_business_days', 'brief', 'brief_state', 'suggestions', 'ai_availability', 'ai_usage', 'merge_into', 'now']),
            $this->propKeys(route('ai.requests.show', $request)),
        );
    }

    public function test_it_detail_includes_internal_notes_timeline_and_evaluation(): void
    {
        $request = $this->evaluated();
        $request->comments()->create(['user_id' => $this->jhon->id, 'body' => 'Revisar con cartera', 'visibility' => CommentVisibility::Internal]);
        $request->comments()->create(['user_id' => $this->paola->id, 'body' => 'Gracias', 'visibility' => CommentVisibility::Public]);
        $request->votes()->create(['user_id' => $this->juan->id]);

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.show', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Requests/Show', false)
                ->where('view', 'it')
                ->where('request.key', $request->key)
                ->has('request.internal_notes', 1)
                ->where('request.internal_notes.0.body', 'Revisar con cartera')
                ->has('request.comments', 1)
                ->where('request.estimate', null)
                ->where('request.approval', null)
                ->where('evaluation.size', 'M')
                ->where('evaluation.urgency', 'high')
                ->where('evaluation.impact.hours_per_week', 6)
                ->where('evaluation.impact.people', 8)
                ->where('evaluation.impact.risk', 'low')
                ->where('evaluation.impact.money', null)
                ->where('evaluation.priority_final', $request->final_priority)
                ->where('area_hour_value', null)
                ->where('first_response_business_days', 3)
                ->has('team', 2)
            );
    }

    public function test_money_impact_and_area_hour_value_need_costs_view(): void
    {
        $this->ventas->forceFill(['hour_value' => 45_000])->save();
        $request = $this->evaluated(['hours_per_week' => 2, 'people_count' => 3]);

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('area_hour_value', 45_000)
                // 2 h × 3 people × 52/12 weeks × 45.000 = 1.170.000.
                ->where('evaluation.impact.money', '$ 1.170.000 al mes')
            );

        $itWithoutCosts = $this->itWithoutCosts();

        $this->actingAs($itWithoutCosts)
            ->get(route('ai.requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('area_hour_value', null)
                ->where('evaluation.impact.money', null)
                ->where('team.0.hourly_rate', null)
            );
    }

    public function test_duplicates_are_suggested(): void
    {
        $request = $this->request(['title' => 'Cartera por asesor en el visualizador', 'problem' => 'Filtrar la cartera por asesor']);
        $twin = $this->request(['title' => 'Cartera por asesor', 'problem' => 'Ver la cartera de cada asesor', 'requester_id' => $this->juan->id]);
        $this->request(['title' => 'Inventario de bodega', 'problem' => 'Conteo']);

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->has('duplicates', 1)->where('duplicates.0.key', $twin->key));
    }

    public function test_rejected_requests_offer_no_duplicates(): void
    {
        $request = $this->request(['status' => RequestStatus::Rejected, 'first_responded_at' => now(), 'title' => 'Cartera por asesor']);
        $this->request(['title' => 'Cartera por asesor']);

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.show', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('duplicates', [])->where('request.similar_to', null));
    }

    public function test_requesters_keep_the_requester_view_and_never_see_internal_notes(): void
    {
        $request = $this->request();
        $request->comments()->create(['user_id' => $this->jhon->id, 'body' => 'Interna', 'visibility' => CommentVisibility::Internal]);

        $this->actingAs($this->paola)
            ->get(route('ai.requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('view', 'requester')->missing('request.internal_notes')->missing('evaluation'));
    }

    public function test_timeline_shows_triage_events_newest_first(): void
    {
        $request = $this->request();

        $this->actingAs($this->jhon)->put(route('ai.requests.evaluation.update', $request), [
            'size' => 'L', 'urgency' => 'medium', 'risk' => 'medium', 'priority_suggested' => 50, 'priority_final' => null,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('request.timeline.0.kind', 'estimate')
                ->where('request.timeline.0.title', 'Jhon Castro la evaluó')
                ->where('request.status', RequestStatus::Evaluating->value)
            );
    }

    private function itWithoutCosts(): User
    {
        $role = Role::create(['name' => 'TI sin costos', 'guard_name' => 'web']);
        $role->givePermissionTo(['ai.manage', 'ai.requests.create', 'companies.view_all']);

        return $this->user($this->segurex, 'TI sin costos', 'Técnico TI');
    }
}
