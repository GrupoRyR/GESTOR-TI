<?php

namespace Tests\Feature\Ai\Triage;

use App\Enums\Ai\RequestStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

class WeekTest extends TriageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 7 Oct 2026, 9:00 Bogotá.
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_week_returns_exactly_the_contract_props(): void
    {
        $this->assertSame(
            $this->withShared(['today', 'now', 'week_ends_on', 'items', 'running_timer', 'review']),
            $this->propKeys(route('ai.week.index')),
        );

        $this->actingAs($this->paola)->get(route('ai.week.index'))->assertForbidden();
    }

    public function test_due_and_waiting_items(): void
    {
        Notification::fake();
        $due = $this->request(['first_response_due_at' => Carbon::parse('2026-10-08 17:00', 'America/Bogota'), 'assignee_id' => $this->superAdmin->id]);
        $this->request(['first_response_due_at' => Carbon::parse('2026-10-20 17:00', 'America/Bogota')]);
        $question = $this->request(['status' => RequestStatus::Evaluating, 'first_responded_at' => now(), 'requester_id' => $this->juan->id, 'title' => 'Cupo de crédito']);
        $this->actingAs($this->jhon)->post(route('ai.requests.ask', $question), ['question' => '¿Qué cupo mostrar?']);
        $approval = $this->evaluated();
        $this->sendToApproval($approval, ['approver_ids' => [$this->edward->id]]);

        $this->actingAs($this->jhon)
            ->get(route('ai.week.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Week/Index', false)
                ->where('today', '2026-10-07')
                ->where('week_ends_on', '2026-10-11')
                ->where('running_timer', null)
                ->where('review', null)
                ->has('items', 3)
                ->has('items.0', fn (Assert $item) => $item
                    ->where('id', 'fr-'.$due->id)
                    ->where('column', 'due')
                    ->where('company_id', $this->segurex->id)
                    ->where('ref_label', $due->key)
                    ->where('title', 'Primera respuesta a Paola Ríos')
                    ->where('person', 'Leonardo Herrera')
                    ->where('due_at', $due->first_response_due_at->toIso8601String())
                    ->where('since', null)
                    ->where('reason', null)
                    ->where('target', ['type' => 'request', 'key' => $due->key])
                )
                ->where('items.1.column', 'waiting')
                ->where('items.1.title', 'Cupo de crédito: ¿Qué cupo mostrar?')
                ->where('items.1.person', 'Juan Pérez')
                ->where('items.1.target', ['type' => 'request', 'key' => $question->key])
                ->where('items.2.column', 'waiting')
                ->where('items.2.person', 'Edward Ramanan')
                ->where('items.2.target', ['type' => 'approval', 'key' => $approval->key])
            );
    }
}
