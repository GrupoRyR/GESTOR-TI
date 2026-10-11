<?php

namespace Tests\Feature\Ai\Costs;

use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use App\Models\AiRequestApproval;
use App\Notifications\AiProjectReapprovalRequested;
use App\Services\Telegram\TelegramClient;
use App\Telegram\CallbackRegistry;
use App\Telegram\Handlers\ApprovalCallbackHandler;
use App\Telegram\UpdateHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Pedir nueva aprobación" (`ai.projects.reapproval.store`): above the
 * threshold the approvers decide again, reusing the B4 approval rows
 * (`is_reapproval`), the web pages and the Telegram buttons.
 */
class ReapprovalTest extends CostsTestCase
{
    private AiProject $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p = $this->activeProject(10, null, '2026-10-31');
        $this->task($this->p, 'todo', ['estimate_hours' => 4]);
        $this->hours($this->p, $this->jhon, 13);
    }

    private function ask(): void
    {
        $this->actingAs($this->jhon)
            ->post(route('ai.projects.reapproval.store', $this->p))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_the_summary_flags_the_project_and_asking_creates_reapproval_rows(): void
    {
        Notification::fake();

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.show', $this->p))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.budget.needs_reapproval', true)
                ->where('reapproval_threshold', 0.2)
                ->where('reapproval_requested_at', null));

        $this->ask();

        $request = $this->originRequest($this->p);
        $row = AiRequestApproval::query()->where('ai_request_id', $request->id)->sole();
        $this->assertSame($this->edward->id, (int) $row->user_id);
        $this->assertTrue($row->is_reapproval);
        $this->assertSame(ApprovalDecision::Pending, $row->decision);
        $this->assertSame(17, $row->estimate['hours_total']);
        $this->assertSame(RequestStatus::InDevelopment, $request->fresh()->status);
        $this->assertNotNull($this->p->fresh()->reapproval_requested_at);

        Notification::assertSentTo($this->edward, AiProjectReapprovalRequested::class, fn (AiProjectReapprovalRequested $n) => $n->eventType() === 'ai.project.reapproval'
            && str_contains($n->title(), $this->p->key));

        // Asking twice is refused while the round is open.
        $this->actingAs($this->jhon)->post(route('ai.projects.reapproval.store', $this->p))->assertSessionHasErrors('project');

        $this->actingAs($this->edward)
            ->get(route('ai.approvals.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 1)->where('pending.0.key', $request->key));
    }

    public function test_within_budget_there_is_nothing_to_approve_again(): void
    {
        $calm = $this->activeProject(100);

        $this->actingAs($this->jhon)->post(route('ai.projects.reapproval.store', $calm))->assertSessionHasErrors('project');
        $this->actingAs($this->paola)->post(route('ai.projects.reapproval.store', $calm))->assertForbidden();
    }

    public function test_approving_adopts_the_new_estimate_without_touching_the_request_status(): void
    {
        Notification::fake();
        $this->ask();
        $request = $this->originRequest($this->p);

        $this->actingAs($this->edward)
            ->post(route('ai.approvals.approve', $request), ['comment' => 'Adelante'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::InDevelopment, $request->fresh()->status);
        $project = $this->p->fresh();
        $this->assertNull($project->reapproval_requested_at);

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.budget.hours.estimate', 17)
                ->where('project.budget.needs_reapproval', false));

        $this->actingAs($this->edward)
            ->get(route('ai.approvals.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pending', 0));
    }

    public function test_rejecting_keeps_the_old_estimate(): void
    {
        Notification::fake();
        $this->ask();
        $request = $this->originRequest($this->p);

        $this->actingAs($this->edward)
            ->post(route('ai.approvals.reject', $request), ['comment' => 'Recorten alcance'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::InDevelopment, $request->fresh()->status);
        $this->assertNull($this->p->fresh()->reapproval_requested_at);
        $this->assertSame(1, $this->p->activities()->where('kind', 'budget')->where('text', 'like', '%Recorten alcance%')->count());

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.show', $this->p))
            ->assertInertia(fn (Assert $page) => $page->where('project.budget.hours.estimate', 10));
    }

    public function test_telegram_buttons_decide_a_reapproval(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        Notification::fake();
        $this->linkTelegram($this->edward, '555');
        $this->ask();
        $row = AiRequestApproval::query()->sole();

        (new UpdateHandler([
            'update_id' => 5001,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => 555],
                'data' => ApprovalCallbackHandler::data($row->id, 'approve'),
                'message' => ['message_id' => 91, 'chat' => ['id' => 555, 'type' => 'private'], 'text' => 'P-1 necesita una nueva aprobación'],
            ],
        ]))->handle(app(TelegramClient::class), app(CallbackRegistry::class));

        $this->assertSame(ApprovalDecision::Approved, $row->fresh()->decision);
        $this->assertNull($this->p->fresh()->reapproval_requested_at);
    }
}
