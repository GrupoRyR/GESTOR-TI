<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\EvaluateRequest;
use App\Actions\Ai\VoteRequest;
use App\Enums\Ai\EffortSize;
use App\Enums\Ai\RequestStatus;
use App\Enums\Ai\RiskLevel;
use App\Enums\Ai\Urgency;
use App\Models\AiRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

class VoteAndEvaluateTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function evaluated(): AiRequest
    {
        $request = $this->request(['hours_per_week' => 6, 'people_count' => 8, 'urgency' => Urgency::High]);

        return app(EvaluateRequest::class)->handle($request, $this->jhon, EffortSize::M, Urgency::High, RiskLevel::Low);
    }

    public function test_evaluating_computes_the_suggested_priority_and_moves_to_evaluating(): void
    {
        $request = $this->evaluated();

        $this->assertSame(EffortSize::M, $request->size);
        $this->assertSame(RiskLevel::Low, $request->risk);
        // 6 h × 8 people, no votes, M, high: impact 48 / 2 × 1.2 = 28.8 → 74.
        $this->assertSame(74, $request->priority_score);
        $this->assertSame(74, $request->final_priority);
        $this->assertFalse($request->priority_overridden);
        $this->assertSame($this->jhon->id, $request->evaluated_by_id);
        $this->assertNotNull($request->evaluated_at);
        $this->assertNotNull($request->first_responded_at);
        $this->assertSame(RequestStatus::Evaluating, $request->status);
    }

    public function test_jhon_can_override_the_final_priority(): void
    {
        $request = $this->request(['hours_per_week' => 6, 'people_count' => 8]);

        app(EvaluateRequest::class)->handle($request, $this->jhon, EffortSize::M, Urgency::High, RiskLevel::None, finalPriority: 90);

        $this->assertSame(74, $request->priority_score);
        $this->assertSame(90, $request->final_priority);
        $this->assertTrue($request->priority_overridden);
    }

    public function test_only_ai_managers_evaluate(): void
    {
        $this->expectException(AuthorizationException::class);

        app(EvaluateRequest::class)->handle($this->request(), $this->paola, EffortSize::S, Urgency::Low, RiskLevel::None);
    }

    public function test_a_vote_is_idempotent_and_raises_the_priority(): void
    {
        $request = $this->evaluated();

        app(VoteRequest::class)->vote($request, $this->juan);
        app(VoteRequest::class)->vote($request, $this->juan);
        app(VoteRequest::class)->vote($request, $this->ventasApprover);
        app(VoteRequest::class)->vote($request, $this->jhon);

        $request->refresh();
        $this->assertSame(3, $request->votes_count);
        $this->assertSame(3, $request->votes()->count());
        // GIA-148: 6 h × 8 + 3 votes, M, high → 77.
        $this->assertSame(77, $request->priority_score);
        $this->assertSame(77, $request->final_priority, 'the final follows the suggestion while not overridden');
    }

    public function test_votes_never_change_an_overridden_priority(): void
    {
        $request = $this->request(['hours_per_week' => 6, 'people_count' => 8]);
        app(EvaluateRequest::class)->handle($request, $this->jhon, EffortSize::M, Urgency::High, RiskLevel::None, finalPriority: 50);

        app(VoteRequest::class)->vote($request, $this->juan);

        $request->refresh();
        $this->assertSame(75, $request->priority_score);
        $this->assertSame(50, $request->final_priority);
    }

    public function test_a_vote_can_be_removed(): void
    {
        $request = $this->evaluated();
        app(VoteRequest::class)->vote($request, $this->juan);

        app(VoteRequest::class)->unvote($request, $this->juan);
        app(VoteRequest::class)->unvote($request, $this->juan);

        $request->refresh();
        $this->assertSame(0, $request->votes_count);
        $this->assertSame(74, $request->priority_score);
    }

    public function test_votes_before_evaluation_are_counted_without_a_score(): void
    {
        $request = $this->request();

        app(VoteRequest::class)->vote($request, $this->juan);

        $this->assertSame(1, $request->refresh()->votes_count);
        $this->assertNull($request->priority_score);
    }

    public function test_the_requester_cannot_vote_their_own_request(): void
    {
        $this->expectException(AuthorizationException::class);

        app(VoteRequest::class)->vote($this->request(), $this->paola);
    }

    public function test_closed_or_rejected_requests_take_no_votes(): void
    {
        $this->expectException(AuthorizationException::class);

        app(VoteRequest::class)->vote($this->request(['status' => RequestStatus::Rejected]), $this->juan);
    }
}
