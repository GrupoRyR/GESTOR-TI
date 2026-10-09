<?php

namespace Tests\Feature\Ai\Triage;

use App\Enums\Ai\ApprovalChannel;
use App\Enums\Ai\RequestStatus;
use App\Events\RequestApproved;
use App\Models\AiRequest;
use App\Models\Role;
use App\Notifications\AiApprovalRequested;
use App\Support\Settings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

class ApprovalsTest extends TriageTestCase
{
    private AiRequest $pending;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->rate($this->jhon, 160_000);
        $this->rate($this->superAdmin, 160_000);
        $this->ventas->forceFill(['hour_value' => 45_000])->save();
        $this->pending = $this->evaluated(['title' => 'Conciliación bancaria']);
        $this->sendToApproval($this->pending, ['approver_ids' => [$this->edward->id]]);
    }

    public function test_index_and_show_return_exactly_the_contract_props(): void
    {
        $this->assertSame($this->withShared(['pending', 'selected', 'wip', 'now']), $this->propKeys(route('ai.approvals.index'), $this->edward));
        $this->assertSame(
            $this->withShared(['approval', 'from_telegram', 'wip', 'decision', 'now']),
            $this->propKeys(route('ai.approvals.show', $this->pending), $this->edward),
        );
    }

    public function test_index_lists_pending_approvals_with_the_selected_detail(): void
    {
        $this->actingAs($this->edward)
            ->get(route('ai.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Approvals/Index', false)
                ->has('pending', 1)
                ->where('pending.0.key', $this->pending->key)
                ->where('pending.0.hours_total', 40)
                // 40 h × 160.000.
                ->where('pending.0.total_cost', 6_400_000)
                ->where('selected.request.key', $this->pending->key)
                ->where('selected.request.problem', $this->pending->problem)
                ->where('selected.estimate.hours_total', 40)
                ->has('selected.estimate.hours_by_person', 2)
                ->where('selected.approval.decision', 'pending')
                ->where('selected.approval.requested_by.name', 'Jhon Castro')
                ->where('selected.evaluation.by.name', 'Jhon Castro')
                ->where('selected.evaluation.size', 'M')
                ->where('selected.hours_saved_per_week', 48)
                // 6.400.000 / (48 h × 52/12 × 45.000) = 0,68 → 0,7 months.
                ->where('selected.payback_months', 0.7)
                ->where('selected.history.0.kind', 'notification')
                ->where('wip', ['in_development' => 0, 'limit' => 3])
            );
    }

    public function test_costs_are_null_without_costs_view(): void
    {
        $role = Role::create(['name' => 'Aprobador sin costos', 'guard_name' => 'web']);
        $role->givePermissionTo(['ai.projects.approve', 'ai.requests.create', 'companies.view_all']);
        $approver = $this->user($this->segurex, 'Aprobador sin costos', 'Otro aprobador');

        $this->actingAs($approver)
            ->get(route('ai.approvals.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pending.0.total_cost', null)
                ->where('selected.estimate.total_cost', null)
                ->where('selected.estimate.external_costs', null)
                ->where('selected.payback_months', null)
            );
    }

    public function test_people_without_the_approver_role_cannot_see_or_decide(): void
    {
        // Súper Admin holds every permission except project approval.
        $this->actingAs($this->superAdmin)
            ->get(route('ai.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pending', [])->where('selected', null));
        $this->actingAs($this->superAdmin)->get(route('ai.approvals.show', $this->pending))->assertForbidden();
        $this->actingAs($this->superAdmin)->post(route('ai.approvals.approve', $this->pending), ['comment' => null])->assertForbidden();
        $this->actingAs($this->paola)->post(route('ai.approvals.reject', $this->pending), ['comment' => 'No'])->assertForbidden();

        $this->assertSame(RequestStatus::PendingApproval, $this->pending->fresh()->status);
    }

    public function test_approve_from_the_web_records_the_decision_and_fires_the_project_hook(): void
    {
        Event::fake([RequestApproved::class]);

        $this->actingAs($this->edward)
            ->from(route('ai.approvals.show', $this->pending))
            ->post(route('ai.approvals.approve', $this->pending), ['comment' => 'Adelante'])
            ->assertRedirect(route('ai.approvals.show', $this->pending));

        $this->assertSame(RequestStatus::Approved, $this->pending->fresh()->status);
        $row = $this->pending->approvals()->where('user_id', $this->edward->id)->first();
        $this->assertSame(ApprovalChannel::Web, $row->channel);
        $this->assertNotNull($row->decided_at);
        $this->assertSame('Adelante', $row->comment);
        Event::assertDispatched(RequestApproved::class, fn (RequestApproved $event) => $event->request->is($this->pending));

        $this->actingAs($this->edward)
            ->get(route('ai.approvals.show', $this->pending))
            ->assertInertia(fn (Assert $page) => $page
                ->where('decision.result', 'approved')
                ->where('decision.comment', 'Adelante')
                ->where('approval.approval.decision', 'approved')
            );
    }

    public function test_reject_needs_a_comment(): void
    {
        $this->actingAs($this->edward)->post(route('ai.approvals.reject', $this->pending), ['comment' => ''])->assertSessionHasErrors('comment');

        $this->actingAs($this->edward)->post(route('ai.approvals.reject', $this->pending), ['comment' => 'Primero cerremos P-07'])->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::Rejected, $this->pending->fresh()->status);
    }

    public function test_require_all_waits_for_every_selected_approver(): void
    {
        $this->jhonApproves();
        $request = $this->evaluated(['evaluated_by_id' => $this->superAdmin->id]);
        $this->sendToApproval($request, ['approver_ids' => [$this->jhon->id, $this->edward->id], 'require_all' => true]);

        $this->actingAs($this->edward)->post(route('ai.approvals.approve', $request), ['comment' => null]);
        $this->assertSame(RequestStatus::PendingApproval, $request->fresh()->status);

        $this->actingAs($this->jhon)->post(route('ai.approvals.approve', $request), ['comment' => null]);
        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
    }

    public function test_show_from_telegram_and_wip(): void
    {
        app(Settings::class)->set('ai.wip_limit', 4);
        $this->request(['status' => RequestStatus::InDevelopment, 'first_responded_at' => now()]);

        $this->actingAs($this->edward)
            ->get(route('ai.approvals.show', [$this->pending, 'desde' => 'telegram']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Approvals/Show', false)
                ->where('from_telegram', true)
                ->where('decision', null)
                ->where('wip', ['in_development' => 1, 'limit' => 4])
                ->where('approval.request.key', $this->pending->key)
            );
    }

    public function test_remind_notifies_the_pending_approvers(): void
    {
        $this->actingAs($this->jhon)->post(route('ai.approvals.remind', $this->pending))->assertSessionHas('success');

        Notification::assertSentToTimes($this->edward, AiApprovalRequested::class, 2);
        $this->actingAs($this->edward)->post(route('ai.approvals.remind', $this->pending))->assertForbidden();
    }
}
