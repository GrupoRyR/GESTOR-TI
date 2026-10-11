<?php

namespace Tests\Feature\Ai\Triage;

use App\Enums\Ai\RequestStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Home `it` view with real data (types/pages/home.ts): attention lists and
 * the approvals waiting for the viewer.
 */
class HomeItTest extends TriageTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unanswered_requests_past_or_near_their_first_response(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'America/Bogota'));
        $late = $this->request(['title' => 'Bloqueo por cartera', 'first_response_due_at' => Carbon::parse('2026-10-06 09:00', 'America/Bogota')]);
        $near = $this->request(['first_response_due_at' => Carbon::parse('2026-10-07 17:00', 'America/Bogota')]);
        $this->request(['first_response_due_at' => Carbon::parse('2026-10-12 17:00', 'America/Bogota')]);
        $this->request(['status' => RequestStatus::Evaluating, 'first_responded_at' => now(), 'first_response_due_at' => now()->subDay()]);

        $this->actingAs($this->jhon)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'it')
                ->has('attention.unanswered_requests', 2)
                ->where('attention.unanswered_requests.0', [
                    'id' => 'request-'.$late->id,
                    'title' => "{$late->key} · vencida hace 1 día",
                    'detail' => 'Bloqueo por cartera',
                    'href_route' => 'ai.requests.show',
                    'href_params' => ['request' => $late->key],
                    'severity' => 'late',
                ])
                ->where('attention.unanswered_requests.1.title', "{$near->key} · vence hoy")
                ->where('attention.unanswered_requests.1.severity', 'attention')
                // Tasks, requirements (B5) and credentials (B8) are not built yet.
                ->where('attention.overdue_tasks', [])
                ->where('attention.waiting_requirements', [])
                ->where('attention.expiring_credentials', [])
                ->where('pending_approvals', [])
            );
    }

    public function test_pending_approvals_only_for_approvers(): void
    {
        Notification::fake();
        $this->jhonApproves();
        $this->rate($this->jhon, 100_000);
        $this->rate($this->superAdmin, 100_000);
        $request = $this->evaluated(['title' => 'Conciliación', 'evaluated_by_id' => $this->superAdmin->id]);
        $due = now()->addMonth()->toDateString();
        $this->sendToApproval($request, ['approver_ids' => [$this->jhon->id, $this->edward->id], 'due_date' => $due]);

        $this->actingAs($this->jhon)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pending_approvals', [[
                    'key' => $request->key,
                    'title' => 'Conciliación',
                    'estimate_hours' => 40,
                    'cost_cop' => 4_000_000,
                    'due_date' => $due,
                ]])
            );

        // Súper Admin manages IT but does not approve projects.
        $this->actingAs($this->superAdmin)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('view', 'it')->where('pending_approvals', []));

        // Once Jhon decided, it leaves his list.
        $this->actingAs($this->jhon)->post(route('ai.approvals.approve', $request), ['comment' => null])->assertSessionHasNoErrors();

        $this->actingAs($this->jhon)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('pending_approvals', []));
    }
}
