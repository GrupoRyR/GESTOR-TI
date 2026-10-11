<?php

namespace Tests\Feature\Ai\Triage;

use App\Enums\Ai\RequestStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AiRequest;
use App\Models\Area;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

class InboxTest extends TriageTestCase
{
    public function test_inbox_returns_exactly_the_contract_props(): void
    {
        $this->assertSame(
            $this->withShared([...self::ACTION_DATA, 'requests', 'filters', 'counts', 'areas', 'first_response_business_days', 'now', 'last_answered']),
            $this->propKeys(route('ai.inbox.index')),
        );
    }

    public function test_requesters_and_approvers_without_ai_manage_cannot_open_it(): void
    {
        $this->actingAs($this->paola)->get(route('ai.inbox.index'))->assertForbidden();
        $this->actingAs($this->edward)->get(route('ai.inbox.index'))->assertForbidden();
    }

    public function test_it_lists_unanswered_first_by_due_date_then_the_rest(): void
    {
        $later = $this->request(['title' => 'Later', 'first_response_due_at' => now()->addDays(2)]);
        $sooner = $this->request(['title' => 'Sooner', 'first_response_due_at' => now()->subDay()]);
        $waiting = $this->request(['title' => 'Waiting', 'status' => RequestStatus::NeedsInfo, 'first_responded_at' => now()]);
        $approval = $this->request(['title' => 'Approval', 'status' => RequestStatus::PendingApproval, 'first_responded_at' => now()]);
        $this->request(['title' => 'Building', 'status' => RequestStatus::InDevelopment, 'first_responded_at' => now()]);
        $this->request(['title' => 'Rejected', 'status' => RequestStatus::Rejected, 'first_responded_at' => now()]);

        $this->actingAs($this->jhon)
            ->get(route('ai.inbox.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Inbox/Index', false)
                ->where('filters', ['quick' => 'all', 'area_id' => null, 'company_id' => null])
                ->where('counts', ['unanswered' => 2, 'needs_info' => 1, 'pending_approval' => 1, 'all' => 4])
                ->where('requests.data.0.key', $sooner->key)
                ->where('requests.data.1.key', $later->key)
                ->where('requests.meta.total', 4)
                ->has('requests.data.0', fn (Assert $row) => $row
                    ->hasAll(['key', 'title', 'type', 'status', 'company_id', 'area', 'requester', 'hours_per_week', 'people', 'votes',
                        'size', 'priority_suggested', 'priority_final', 'urgency', 'first_response', 'product', 'project_key', 'rank',
                        'similar_to', 'created_at'])
                )
                ->where('first_response_business_days', 3)
                ->has('team', 2)
                ->where('team.0.person.name', 'Jhon Castro')
                ->where('approvers.0.name', 'Edward Ramanan')
                ->missing('merge_candidates')
            );

        $keys = collect([$waiting, $approval])->pluck('key')->all();
        $this->actingAs($this->jhon)
            ->get(route('ai.inbox.index', ['quick' => 'needs_info']))
            ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.key', $keys[0])->has('requests.data', 1));
    }

    public function test_quick_chips_and_area_filter(): void
    {
        $this->request(['area_id' => $this->compras->id]);
        $ventas = $this->request();

        $this->actingAs($this->jhon)
            ->get(route('ai.inbox.index', ['quick' => 'unanswered', 'area_id' => $this->ventas->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.quick', 'unanswered')
                ->where('filters.area_id', $this->ventas->id)
                ->has('requests.data', 1)
                ->where('requests.data.0.key', $ventas->key)
                ->where('counts.all', 1)
            );
    }

    public function test_last_answered_feeds_the_positive_empty_state(): void
    {
        $answered = $this->request(['status' => RequestStatus::Evaluating, 'first_responded_at' => now()->subHour()]);

        $this->actingAs($this->jhon)
            ->get(route('ai.inbox.index'))
            ->assertInertia(fn (Assert $page) => $page->where('last_answered.key', $answered->key)->has('last_answered', 3));
    }

    public function test_company_scope_three_companies(): void
    {
        $segurex = $this->request();
        $derivados = $this->request(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'requester_id' => $this->derivadosUser->id]);
        $thirdArea = Area::factory()->for($this->third)->create();
        $third = $this->request(['company_id' => $this->third->id, 'area_id' => $thirdArea->id]);

        // "Todas": every company, filter by company_id, areas of all companies.
        $this->actingAs($this->jhon)
            ->get(route('ai.inbox.index'))
            ->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 3)->has('areas', 4));

        $this->actingAs($this->jhon)
            ->get(route('ai.inbox.index', ['company_id' => $this->third->id]))
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.key', $third->key));

        // Selected company: only it (company_id is ignored outside "Todas").
        $this->actingAs($this->jhon)
            ->withSession([CurrentCompany::SESSION_KEY => $this->derivados->id])
            ->get(route('ai.inbox.index', ['company_id' => $this->segurex->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('requests.data', 1)
                ->where('requests.data.0.key', $derivados->key)
                ->where('filters.company_id', null)
                ->has('areas', 1)
            );

        $this->assertNotNull($segurex);
    }

    public function test_merge_candidates_only_on_their_partial_reload(): void
    {
        $this->request(['title' => 'Consultar cartera por asesor', 'problem' => 'Necesito ver la cartera por asesor en el visualizador']);
        $other = $this->request(['title' => 'Inventario', 'problem' => 'Bodega']);

        $this->actingAs($this->jhon)
            ->get(route('ai.inbox.index', ['q' => 'cartera asesor']), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Ai/Inbox/Index',
                'X-Inertia-Partial-Data' => 'merge_candidates',
            ])
            ->assertOk()
            ->assertJsonCount(1, 'props.merge_candidates')
            ->assertJsonPath('props.merge_candidates.0.title', 'Consultar cartera por asesor');

        $this->actingAs($this->jhon)
            ->get(route('ai.inbox.index', ['q' => $other->key]), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Ai/Inbox/Index',
                'X-Inertia-Partial-Data' => 'merge_candidates',
            ])
            ->assertJsonPath('props.merge_candidates.0.key', $other->key);
    }

    public function test_bulk_ask_moves_each_request_to_needs_info(): void
    {
        Notification::fake();
        $a = $this->request();
        $b = $this->request(['status' => RequestStatus::Evaluating, 'first_responded_at' => now()]);
        $done = $this->request(['status' => RequestStatus::InDevelopment, 'first_responded_at' => now()]);

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.bulk.ask'), ['keys' => [$a->key, $b->key, $done->key], 'question' => '¿Cuántas personas lo usan?'])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('error');

        $this->assertSame(RequestStatus::NeedsInfo, $a->fresh()->status);
        $this->assertSame(RequestStatus::NeedsInfo, $b->fresh()->status);
        $this->assertSame(RequestStatus::InDevelopment, $done->fresh()->status);
        $this->assertNotNull($a->fresh()->first_responded_at);

        $this->actingAs($this->paola)
            ->post(route('ai.requests.bulk.ask'), ['keys' => [$a->key], 'question' => 'x'])
            ->assertForbidden();
    }

    public function test_bulk_assign_sets_the_assignee(): void
    {
        $a = $this->request();
        $b = $this->request();

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.bulk.assign'), ['keys' => [$a->key, $b->key], 'user_id' => $this->superAdmin->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($this->superAdmin->id, $a->fresh()->assignee_id);
        $this->assertSame($this->superAdmin->id, $b->fresh()->assignee_id);

        // Only IT people (team) can be assigned.
        $this->actingAs($this->jhon)
            ->post(route('ai.requests.bulk.assign'), ['keys' => [$a->key], 'user_id' => $this->paola->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_home_see_all_link_goes_to_the_inbox(): void
    {
        $this->actingAs($this->jhon)
            ->get(route('ai.requests.index', ['attention' => 'unanswered']))
            ->assertRedirect(route('ai.inbox.index', ['quick' => 'unanswered']));

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.index', ['status' => 'pending_approval']))
            ->assertRedirect(route('ai.inbox.index', ['quick' => 'pending_approval']));

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.index', ['attention' => 'overdue_tasks']))
            ->assertRedirect(route('ai.week.index'));

        $this->actingAs($this->paola)->get(route('ai.requests.index'))->assertForbidden();
        $this->assertInstanceOf(AiRequest::class, $this->request());
    }
}
