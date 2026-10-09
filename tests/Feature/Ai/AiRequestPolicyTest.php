<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AreaApprovalStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequestVote;
use App\Models\User;

class AiRequestPolicyTest extends AiTestCase
{
    /**
     * @return array<string, User>
     */
    private function people(): array
    {
        $areaViewer = $this->user($this->segurex, 'Usuario final', 'Visor de ventas', $this->ventas);
        $areaViewer->givePermissionTo('ai.area.view');
        $comprasViewer = $this->user($this->segurex, 'Usuario final', 'Visor de compras', $this->compras);
        $comprasViewer->givePermissionTo('ai.area.view');

        return [
            'requester' => $this->paola,
            'colleague' => $this->juan,
            'ia_leader' => $this->jhon,
            'project_approver' => $this->edward,
            'super_admin' => $this->superAdmin,
            'area_approver' => $this->ventasApprover,
            'area_viewer' => $areaViewer,
            'other_area_viewer' => $comprasViewer,
            'other_company' => $this->derivadosUser,
        ];
    }

    /**
     * @param  array<string, bool>  $expected
     */
    private function assertMatrix(string $ability, AiRequest $request, array $expected): void
    {
        $people = $this->people();
        $actual = [];

        foreach ($expected as $who => $_) {
            $actual[$who] = $people[$who]->can($ability, $request);
        }

        $this->assertSame($expected, $actual, $ability);
    }

    public function test_view_matrix(): void
    {
        // Same-company module users see the public requester view (roadmap, B3).
        $this->assertMatrix('view', $this->request(), [
            'requester' => true,
            'colleague' => true,
            'ia_leader' => true,
            'project_approver' => true,
            'super_admin' => true,
            'area_approver' => true,
            'area_viewer' => true,
            'other_area_viewer' => true,
            'other_company' => false,
        ]);
    }

    public function test_comment_needs_taking_part(): void
    {
        $request = $this->request();

        $this->assertMatrix('comment', $request, [
            'requester' => true,
            'colleague' => false,
            'ia_leader' => true,
            'project_approver' => true,
            'area_approver' => true,
            'area_viewer' => true,
            'other_area_viewer' => false,
            'other_company' => false,
        ]);

        $request->followers()->attach($this->juan->id);
        $this->assertTrue($this->juan->can('comment', $request));
    }

    public function test_a_voter_follows_and_can_comment(): void
    {
        $request = $this->request();
        AiRequestVote::factory()->create(['ai_request_id' => $request->id, 'user_id' => $this->juan->id]);

        $this->assertTrue($this->juan->can('view', $request));
        $this->assertTrue($this->juan->can('comment', $request));
    }

    public function test_rate_and_hours_saved_are_for_the_requester_in_production(): void
    {
        $request = $this->request(['status' => RequestStatus::InProduction]);

        $this->assertTrue($this->paola->can('rate', $request));
        $this->assertFalse($this->juan->can('rate', $request));
        $this->assertTrue($this->paola->can('reportHoursSaved', $request));

        $request->ratings()->create(['user_id' => $this->paola->id, 'solved_need' => 5, 'ease_of_use' => 5, 'rated_at' => now()]);
        $this->assertFalse($this->paola->can('rate', $request->refresh()));

        $request->forceFill(['status' => RequestStatus::InTesting])->save();
        $this->assertFalse($this->paola->can('reportHoursSaved', $request));
    }

    public function test_internal_notes_and_triage_are_only_for_ai_managers(): void
    {
        $request = $this->request();
        $expected = [
            'requester' => false,
            'colleague' => false,
            'ia_leader' => true,
            'project_approver' => false,
            'super_admin' => true,
            'area_approver' => false,
            'area_viewer' => false,
            'other_area_viewer' => false,
            'other_company' => false,
        ];

        $this->assertMatrix('viewInternal', $request, $expected);
        $this->assertMatrix('evaluate', $request, $expected);
        $this->assertMatrix('transition', $request, $expected);
    }

    public function test_create_and_create_on_behalf(): void
    {
        $this->assertTrue($this->paola->can('create', AiRequest::class));
        $this->assertFalse($this->paola->can('createOnBehalf', AiRequest::class));
        $this->assertTrue($this->jhon->can('createOnBehalf', AiRequest::class));
        $this->assertFalse(User::factory()->create()->can('create', AiRequest::class));
    }

    public function test_project_approval_matrix(): void
    {
        $this->assertMatrix('approveProject', $this->request(['status' => RequestStatus::PendingApproval]), [
            'requester' => false,
            'ia_leader' => false,
            'project_approver' => true,
            'super_admin' => false,
            'area_approver' => false,
        ]);
    }

    public function test_area_approval_matrix(): void
    {
        $this->assertMatrix('approveArea', $this->request(['area_approval_status' => AreaApprovalStatus::Pending]), [
            'requester' => false,
            'ia_leader' => false,
            'project_approver' => false,
            'super_admin' => false,
            'area_approver' => true,
        ]);
    }

    public function test_validate_is_for_the_requester_while_in_testing(): void
    {
        $request = $this->request(['status' => RequestStatus::InTesting]);

        $this->assertTrue($this->paola->can('validate', $request));
        $this->assertFalse($this->jhon->can('validate', $request));
    }
}
