<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\TransitionRequest;
use App\Domain\Ai\InvalidTransition;
use App\Domain\Ai\RequestStatusMachine;
use App\Enums\Ai\AreaApprovalStatus;
use App\Enums\Ai\RejectReason;
use App\Enums\Ai\RequestStatus;
use App\Models\Alert;
use App\Notifications\AiRequestStatusChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class TransitionRequestTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function transition(): TransitionRequest
    {
        return app(TransitionRequest::class);
    }

    public function test_the_machine_follows_the_documented_lifecycle(): void
    {
        $machine = new RequestStatusMachine;

        $path = [
            RequestStatus::Received, RequestStatus::Evaluating, RequestStatus::NeedsInfo, RequestStatus::Evaluating,
            RequestStatus::PendingApproval, RequestStatus::Approved, RequestStatus::Planned, RequestStatus::InDevelopment,
            RequestStatus::InTesting, RequestStatus::InProduction, RequestStatus::Closed,
        ];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue($machine->allows($path[$i], $path[$i + 1]), $path[$i]->value.' → '.$path[$i + 1]->value);
        }

        $this->assertTrue($machine->allows(RequestStatus::PendingApproval, RequestStatus::Rejected));
        $this->assertTrue($machine->allows(RequestStatus::PendingApproval, RequestStatus::Backlog));
        $this->assertFalse($machine->allows(RequestStatus::Received, RequestStatus::InDevelopment));
        $this->assertFalse($machine->allows(RequestStatus::Evaluating, RequestStatus::Approved), 'only an approver approves');
        $this->assertSame([], $machine->next(RequestStatus::Closed));
    }

    public function test_every_status_mirrors_the_design_keys(): void
    {
        $this->assertSame([
            'received', 'evaluating', 'needs_info', 'pending_approval', 'approved', 'backlog', 'rejected',
            'planned', 'in_development', 'in_testing', 'in_production', 'closed',
        ], array_column(RequestStatus::cases(), 'value'));
        $this->assertSame('Pendiente de aprobación', RequestStatus::PendingApproval->label());
    }

    public function test_an_allowed_transition_records_history_and_notifies_the_requester(): void
    {
        $request = $this->request();

        $this->transition()->handle($request, RequestStatus::Evaluating, $this->jhon, 'Lo estamos mirando');

        $request->refresh();
        $this->assertSame(RequestStatus::Evaluating, $request->status);
        $this->assertNotNull($request->first_responded_at, 'IT moving it counts as first response');

        $change = $request->statusChanges()->latest('id')->first();
        $this->assertSame(RequestStatus::Received, $change->from_status);
        $this->assertSame(RequestStatus::Evaluating, $change->to_status);
        $this->assertSame($this->jhon->id, $change->user_id);
        $this->assertSame('Lo estamos mirando', $change->note);

        Notification::assertSentTo($this->paola, AiRequestStatusChanged::class, function (AiRequestStatusChanged $notification) use ($request) {
            return $notification->eventType() === 'ai.request.status_changed'
                && str_contains($notification->title(), $request->key)
                && str_contains($notification->title(), 'En evaluación');
        });

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'ai',
            'subject_id' => $request->id,
            'event' => 'ai_request_status_changed',
        ]);
    }

    public function test_a_forbidden_transition_is_refused_without_side_effects(): void
    {
        $request = $this->request();

        try {
            $this->transition()->handle($request, RequestStatus::InProduction, $this->jhon);
            $this->fail('Expected an invalid transition');
        } catch (InvalidTransition) {
        }

        $this->assertSame(RequestStatus::Received, $request->refresh()->status);
        $this->assertSame(0, $request->statusChanges()->count());
        Notification::assertNothingSent();
    }

    public function test_only_ai_managers_transition(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->transition()->handle($this->request(), RequestStatus::Evaluating, $this->paola);
    }

    public function test_rejecting_requires_a_reason_and_closes_the_request(): void
    {
        $request = $this->request();

        try {
            $this->transition()->handle($request, RequestStatus::Rejected, $this->jhon);
            $this->fail('Expected a validation error');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $this->transition()->handle($request, RequestStatus::Rejected, $this->jhon, 'Ya existe en el Portafolio', RejectReason::Exists);

        $request->refresh();
        $this->assertSame(RequestStatus::Rejected, $request->status);
        $this->assertSame(RejectReason::Exists, $request->rejection_reason);
        $this->assertSame('Ya existe en el Portafolio', $request->rejection_message);
        $this->assertNotNull($request->closed_at);
    }

    public function test_closing_sets_closed_at(): void
    {
        $request = $this->request(['status' => RequestStatus::InProduction, 'first_responded_at' => now()]);

        $this->transition()->handle($request, RequestStatus::Closed, $this->jhon);

        $this->assertNotNull($request->refresh()->closed_at);
    }

    public function test_the_requester_is_not_notified_of_their_own_change(): void
    {
        $request = $this->request(['requester_id' => $this->jhon->id, 'created_by_id' => $this->jhon->id, 'area_id' => null]);

        $this->transition()->handle($request, RequestStatus::Evaluating, $this->jhon);

        Notification::assertNothingSent();
    }

    public function test_the_first_response_resolves_the_overdue_alert(): void
    {
        $request = $this->request(['first_response_due_at' => now()->subDay()]);
        $this->artisan('ai:first-response-alerts')->assertSuccessful();
        $this->assertSame(1, Alert::query()->whereNull('resolved_at')->count());

        $this->transition()->handle($request, RequestStatus::Evaluating, $this->jhon);

        $this->assertSame(0, Alert::query()->whereNull('resolved_at')->count());
    }

    public function test_a_pending_area_approval_blocks_reaching_it(): void
    {
        $request = $this->request(['area_approval_status' => AreaApprovalStatus::Pending]);

        $this->expectException(InvalidTransition::class);
        $this->transition()->handle($request, RequestStatus::Evaluating, $this->jhon);
    }

    public function test_a_request_of_another_company_needs_company_access(): void
    {
        $leader = $this->user($this->derivados, 'Usuario final', 'Otro');
        $leader->givePermissionTo('ai.manage');
        $request = $this->request();

        $this->expectException(AuthorizationException::class);
        $this->transition()->handle($request, RequestStatus::Evaluating, $leader);
    }
}
