<?php

namespace Tests\Feature\Ai\Triage;

use App\Actions\Ai\TransitionRequest;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use App\Enums\Ai\RequestStatus;
use App\Models\AiEstimate;
use App\Models\AiRequestApproval;
use App\Notifications\AiApprovalRequested;
use App\Notifications\Messages\TelegramMessage;
use App\Support\Settings;
use Illuminate\Support\Facades\Notification;

class SendToApprovalTest extends TriageTestCase
{
    public function test_it_creates_the_estimate_pending_rows_and_notifies_the_approvers(): void
    {
        Notification::fake();
        $this->jhonApproves();
        $this->rate($this->jhon, 160_000);
        $this->rate($this->superAdmin, 150_000);
        $request = $this->evaluated();
        $due = now()->addMonth()->toDateString();

        $this->sendToApproval($request, [
            'external_costs' => 500_000,
            'due_date' => $due,
            'approver_ids' => [$this->jhon->id, $this->edward->id],
        ]);

        $request->refresh();
        $this->assertSame(RequestStatus::PendingApproval, $request->status);

        $estimate = AiEstimate::query()->where('ai_request_id', $request->id)->sole();
        $this->assertEquals(40, $estimate->hours_total);
        $this->assertSame(500_000, $estimate->external_costs);
        // 28 × 160.000 + 12 × 150.000 + 500.000.
        $this->assertSame(6_780_000, $estimate->total_cost);
        $this->assertSame($due, $estimate->due_date->toDateString());
        $this->assertCount(2, $estimate->lines);
        $this->assertSame(160_000, $estimate->lines->firstWhere('user_id', $this->jhon->id)->hourly_rate);

        $rows = $request->approvals()->where('kind', ApprovalKind::Project->value)->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn (AiRequestApproval $row) => $row->decision === ApprovalDecision::Pending));
        $snapshot = $rows->first()->estimate;
        $this->assertSame($estimate->id, $snapshot['estimate_id']);
        $this->assertSame(40, $snapshot['hours_total']);
        $this->assertSame(6_780_000, $snapshot['total_cost']);
        $this->assertSame($due, $snapshot['due_date']);
        $this->assertSame(28, $snapshot['hours_by_person'][0]['hours']);

        $this->assertSame('approval', $request->events()->reorder()->oldest('id')->value('kind'));

        Notification::assertSentTo($this->edward, AiApprovalRequested::class, function (AiApprovalRequested $notification) use ($request) {
            $telegram = $notification->toTelegram($this->edward);

            return $notification->requestKey === $request->key
                && $notification->approvalId !== null
                && $telegram instanceof TelegramMessage
                && $telegram->keyboard[0][0]['text'] === '✅ Aprobar'
                && str_starts_with($telegram->keyboard[0][0]['callback_data'], 'ai-approval:')
                && strlen($telegram->keyboard[0][0]['callback_data']) <= 64
                && $telegram->keyboard[0][1]['text'] === '❌ Rechazar';
        });
        Notification::assertSentTo($this->jhon, AiApprovalRequested::class);
        Notification::assertNotSentTo($this->superAdmin, AiApprovalRequested::class);
    }

    public function test_without_rates_the_cost_stays_unknown(): void
    {
        Notification::fake();
        $request = $this->evaluated();

        $this->sendToApproval($request);

        $this->assertNull(AiEstimate::query()->sole()->total_cost);
    }

    public function test_validation(): void
    {
        $request = $this->evaluated();

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.approval.store', $request), [
                'hours' => [['person_id' => $this->paola->id, 'hours' => 5]],
                'external_costs' => -1,
                'due_date' => now()->subDay()->toDateString(),
                'approver_ids' => [$this->superAdmin->id],
                'require_all' => false,
            ])
            ->assertSessionHasErrors(['hours', 'external_costs', 'due_date', 'approver_ids']);

        $this->assertSame(RequestStatus::Evaluating, $request->fresh()->status);
    }

    public function test_a_request_must_be_evaluated_first(): void
    {
        $request = $this->request();

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.approval.store', $request), [
                'hours' => [['person_id' => $this->jhon->id, 'hours' => 5]],
                'external_costs' => 0,
                'due_date' => now()->addWeek()->toDateString(),
                'approver_ids' => [$this->edward->id],
                'require_all' => false,
            ])
            ->assertSessionHasErrors('size');
    }

    public function test_the_evaluator_alone_cannot_be_the_approver_when_the_setting_is_on(): void
    {
        Notification::fake();
        $this->jhonApproves();
        app(Settings::class)->set('ai.approval.require_other_if_evaluator', true);
        $request = $this->evaluated();

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.approval.store', $request), [
                'hours' => [['person_id' => $this->jhon->id, 'hours' => 5]],
                'external_costs' => 0,
                'due_date' => now()->addWeek()->toDateString(),
                'approver_ids' => [$this->jhon->id],
                'require_all' => false,
            ])
            ->assertSessionHasErrors('approver_ids');

        $this->sendToApproval($request, ['approver_ids' => [$this->jhon->id, $this->edward->id]]);

        // Jhon evaluated it: he cannot approve; Edward can.
        $this->actingAs($this->jhon)->post(route('ai.approvals.approve', $request), ['comment' => null])->assertForbidden();
        $this->actingAs($this->edward)->post(route('ai.approvals.approve', $request), ['comment' => null])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);
    }

    public function test_resending_replaces_the_previous_pending_round(): void
    {
        Notification::fake();
        $request = $this->evaluated();
        $this->sendToApproval($request);

        // TI withdraws it to evaluation and sends it again.
        $this->actingAs($this->jhon)->put(route('ai.requests.evaluation.update', $request), ['size' => 'L', 'urgency' => 'high', 'risk' => 'low', 'priority_final' => null]);
        app(TransitionRequest::class)->handle($request->fresh(), RequestStatus::Evaluating, $this->jhon);
        $this->sendToApproval($request->fresh(), ['due_date' => now()->addMonths(2)->toDateString()]);

        $this->assertSame(1, $request->approvals()->where('kind', 'project')->pending()->count());
        $this->assertSame(2, AiEstimate::query()->where('ai_request_id', $request->id)->count());
    }
}
