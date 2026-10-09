<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\RecordApprovalDecision;
use App\Enums\Ai\ApprovalChannel;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use App\Enums\Ai\AreaApprovalStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ApprovalDecisionTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function decide(AiRequest $request, User $actor, ApprovalKind $kind, ApprovalDecision $decision, ?string $comment = null, ApprovalChannel $channel = ApprovalChannel::Web): AiRequestApproval
    {
        return app(RecordApprovalDecision::class)->handle($request, $actor, $kind, $decision, $comment, $channel);
    }

    /**
     * @param  list<User>  $approvers
     */
    private function pendingProject(array $approvers, bool $requireAll = false): AiRequest
    {
        $request = $this->request([
            'status' => RequestStatus::PendingApproval,
            'evaluated_by_id' => $this->jhon->id,
            'first_responded_at' => now(),
        ]);

        foreach ($approvers as $approver) {
            AiRequestApproval::factory()->project()->for($request, 'request')->create([
                'user_id' => $approver->id,
                'require_all' => $requireAll,
                'requested_by_id' => $this->jhon->id,
            ]);
        }

        return $request;
    }

    public function test_a_project_approver_approves_and_the_request_becomes_approved(): void
    {
        $request = $this->pendingProject([$this->edward]);

        $approval = $this->decide($request, $this->edward, ApprovalKind::Project, ApprovalDecision::Approved, 'Adelante', ApprovalChannel::Telegram);

        $this->assertSame(ApprovalDecision::Approved, $approval->decision);
        $this->assertSame(ApprovalChannel::Telegram, $approval->channel);
        $this->assertSame('Adelante', $approval->comment);
        $this->assertNotNull($approval->decided_at);
        $this->assertSame(['hours_total' => 40, 'external_costs' => 0, 'due_date' => '2026-10-31'], array_intersect_key($approval->estimate, array_flip(['hours_total', 'external_costs', 'due_date'])));
        $this->assertSame(RequestStatus::Approved, $request->refresh()->status);
        $this->assertSame($this->edward->id, $request->statusChanges()->latest('id')->first()->user_id);
    }

    public function test_super_admin_without_the_approver_role_cannot_approve_a_project(): void
    {
        $request = $this->pendingProject([$this->edward]);

        $this->expectException(AuthorizationException::class);
        $this->decide($request, $this->superAdmin, ApprovalKind::Project, ApprovalDecision::Approved);
    }

    public function test_the_ia_leader_without_the_approver_role_cannot_approve_a_project(): void
    {
        $request = $this->pendingProject([$this->edward]);

        $this->expectException(AuthorizationException::class);
        $this->decide($request, $this->jhon, ApprovalKind::Project, ApprovalDecision::Approved);
    }

    public function test_rejecting_a_project_needs_a_comment_and_rejects_the_request(): void
    {
        $request = $this->pendingProject([$this->edward]);

        try {
            $this->decide($request, $this->edward, ApprovalKind::Project, ApprovalDecision::Rejected);
            $this->fail('Expected a validation error');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('comment', $exception->errors());
        }

        $this->decide($request, $this->edward, ApprovalKind::Project, ApprovalDecision::Rejected, 'No hay presupuesto este trimestre');

        $request->refresh();
        $this->assertSame(RequestStatus::Rejected, $request->status);
        $this->assertSame('No hay presupuesto este trimestre', $request->rejection_message);
    }

    public function test_a_request_not_pending_approval_cannot_be_decided(): void
    {
        $request = $this->request(['status' => RequestStatus::Evaluating]);

        $this->expectException(AuthorizationException::class);
        $this->decide($request, $this->edward, ApprovalKind::Project, ApprovalDecision::Approved);
    }

    public function test_the_evaluator_may_need_the_other_approver_when_configured(): void
    {
        $this->jhon->assignRole('Aprobador de proyectos');
        $request = $this->pendingProject([$this->jhon, $this->edward]);

        // Off by default: the evaluator may approve.
        $this->assertTrue($this->jhon->can('approveProject', $request));

        app(Settings::class)->set('ai.approval.require_other_if_evaluator', true);
        $this->assertFalse($this->jhon->fresh()->can('approveProject', $request));
        $this->assertTrue($this->edward->can('approveProject', $request));
    }

    public function test_require_all_waits_for_every_selected_approver(): void
    {
        $this->jhon->assignRole('Aprobador de proyectos');
        $request = $this->pendingProject([$this->jhon, $this->edward], requireAll: true);

        $this->decide($request, $this->edward, ApprovalKind::Project, ApprovalDecision::Approved);
        $this->assertSame(RequestStatus::PendingApproval, $request->refresh()->status);

        $this->decide($request, $this->jhon, ApprovalKind::Project, ApprovalDecision::Approved);
        $this->assertSame(RequestStatus::Approved, $request->refresh()->status);
    }

    public function test_an_approver_cannot_decide_twice(): void
    {
        $this->jhon->assignRole('Aprobador de proyectos');
        $request = $this->pendingProject([$this->jhon, $this->edward], requireAll: true);
        $this->decide($request, $this->edward, ApprovalKind::Project, ApprovalDecision::Approved);

        $this->expectException(ValidationException::class);
        $this->decide($request, $this->edward, ApprovalKind::Project, ApprovalDecision::Rejected, 'cambio de opinión');
    }

    public function test_the_area_approver_approves_requests_of_their_own_area(): void
    {
        $request = $this->request(['area_approval_status' => AreaApprovalStatus::Pending]);
        AiRequestApproval::factory()->for($request, 'request')->create(['user_id' => $this->ventasApprover->id]);

        $this->decide($request, $this->ventasApprover, ApprovalKind::Area, ApprovalDecision::Approved);

        $request->refresh();
        $this->assertSame(AreaApprovalStatus::Approved, $request->area_approval_status);
        $this->assertSame(RequestStatus::Received, $request->status, 'now it reaches TI');
    }

    public function test_the_area_approver_cannot_approve_another_area(): void
    {
        $request = $this->request(['area_id' => $this->compras->id, 'area_approval_status' => AreaApprovalStatus::Pending]);

        $this->expectException(AuthorizationException::class);
        $this->decide($request, $this->ventasApprover, ApprovalKind::Area, ApprovalDecision::Approved);
    }

    public function test_jhon_is_not_the_area_approver(): void
    {
        $request = $this->request(['area_approval_status' => AreaApprovalStatus::Pending]);

        $this->expectException(AuthorizationException::class);
        $this->decide($request, $this->jhon, ApprovalKind::Area, ApprovalDecision::Approved);
    }

    public function test_an_area_rejection_rejects_the_request(): void
    {
        $request = $this->request(['area_approval_status' => AreaApprovalStatus::Pending]);

        $this->decide($request, $this->ventasApprover, ApprovalKind::Area, ApprovalDecision::Rejected, 'No es prioridad del área');

        $request->refresh();
        $this->assertSame(AreaApprovalStatus::Rejected, $request->area_approval_status);
        $this->assertSame(RequestStatus::Rejected, $request->status);
        $this->assertNotNull($request->closed_at);
    }
}
