<?php

namespace Tests\Feature\Ai\Triage;

use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RejectReason;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Notifications\AiRequestReminder;
use App\Notifications\AiRequestStatusChanged;
use Illuminate\Support\Facades\Notification;

class TriageActionsTest extends TriageTestCase
{
    public function test_requesters_cannot_use_any_it_action(): void
    {
        $request = $this->request();
        $other = $this->request();

        $calls = [
            ['put', route('ai.requests.evaluation.update', $request), ['size' => 'M', 'urgency' => 'high', 'risk' => 'low', 'priority_final' => null]],
            ['post', route('ai.requests.notes.store', $request), ['body' => 'x']],
            ['post', route('ai.requests.reject', $request), ['reason' => 'other', 'message' => 'x']],
            ['post', route('ai.requests.ask', $request), ['question' => 'x']],
            ['post', route('ai.requests.merge', $request), ['into' => $other->key]],
            ['post', route('ai.requests.approval.store', $request), []],
            ['post', route('ai.requests.backlog', $request), []],
            ['post', route('ai.requests.remind', $request), []],
        ];

        foreach ($calls as [$method, $url, $data]) {
            $this->actingAs($this->paola)->{$method}($url, $data)->assertForbidden();
        }

        $this->assertSame(RequestStatus::Received, $request->fresh()->status);
    }

    public function test_evaluation_saves_priority_with_override_and_counts_as_first_response(): void
    {
        $request = $this->request(['hours_per_week' => 6, 'people_count' => 8]);

        $this->actingAs($this->jhon)
            ->put(route('ai.requests.evaluation.update', $request), [
                'size' => 'M', 'urgency' => 'high', 'risk' => 'high', 'priority_suggested' => 1, 'priority_final' => null,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(RequestStatus::Evaluating, $request->status);
        $this->assertNotNull($request->first_responded_at);
        $this->assertSame($request->priority_score, $request->final_priority);
        $this->assertNotSame(1, $request->priority_score);
        $this->assertFalse($request->priority_overridden);

        $this->actingAs($this->jhon)
            ->put(route('ai.requests.evaluation.update', $request), ['size' => 'M', 'urgency' => 'high', 'risk' => 'high', 'priority_final' => 90])
            ->assertSessionHasNoErrors();

        $this->assertSame(90, $request->fresh()->final_priority);
        $this->assertTrue($request->fresh()->priority_overridden);

        $this->actingAs($this->jhon)
            ->put(route('ai.requests.evaluation.update', $request), ['size' => 'XXL', 'risk' => 'high', 'priority_final' => 101])
            ->assertSessionHasErrors(['size', 'priority_final']);
    }

    public function test_internal_notes_are_only_for_it(): void
    {
        $request = $this->request();

        $this->actingAs($this->jhon)->post(route('ai.requests.notes.store', $request), ['body' => 'Hablar con cartera'])->assertSessionHasNoErrors();

        $note = $request->comments()->first();
        $this->assertSame(CommentVisibility::Internal, $note->visibility);
        // Internal notes are not a first response.
        $this->assertNull($request->fresh()->first_responded_at);
    }

    public function test_reject_needs_a_reason_and_a_message(): void
    {
        Notification::fake();
        $request = $this->request();

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.reject', $request), ['reason' => '', 'message' => ''])
            ->assertSessionHasErrors(['reason', 'message']);

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.reject', $request), ['reason' => 'not_it', 'message' => 'Esto lo maneja Contabilidad.'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(RequestStatus::Rejected, $request->status);
        $this->assertSame(RejectReason::NotIt, $request->rejection_reason);
        $this->assertSame('Esto lo maneja Contabilidad.', $request->rejection_message);
        $this->assertSame('Esto lo maneja Contabilidad.', $request->statusChanges()->reorder()->latest('id')->first()->note);
        Notification::assertSentTo($this->paola, AiRequestStatusChanged::class);
    }

    public function test_ask_moves_to_needs_info_with_the_question_in_the_conversation(): void
    {
        Notification::fake();
        $request = $this->request();

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.ask', $request), ['question' => '¿Qué cupo hay que mostrar?'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(RequestStatus::NeedsInfo, $request->status);
        $this->assertNotNull($request->first_responded_at);
        $this->assertSame('¿Qué cupo hay que mostrar?', $request->comments()->where('visibility', 'public')->value('body'));
        Notification::assertSentTo($this->paola, AiRequestStatusChanged::class);

        // Asking again while waiting only adds the question and reminds.
        $this->actingAs($this->jhon)
            ->post(route('ai.requests.ask', $request), ['question' => '¿Y para quién?'])
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($this->paola, AiRequestReminder::class);
        $this->assertSame(2, $request->comments()->count());

        // A request already in development cannot be asked.
        $built = $this->request(['status' => RequestStatus::InDevelopment, 'first_responded_at' => now()]);
        $this->actingAs($this->jhon)->post(route('ai.requests.ask', $built), ['question' => 'x'])->assertSessionHas('error');
    }

    public function test_merge_moves_people_to_the_request_that_stays(): void
    {
        Notification::fake();
        $stays = $this->request(['title' => 'La que se queda', 'requester_id' => $this->juan->id]);
        $goes = $this->request(['title' => 'La duplicada']);
        $goes->votes()->create(['user_id' => $this->derivadosUser->id]);
        $goes->update(['votes_count' => 1]);

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.merge', $goes), ['into' => $stays->key])
            ->assertSessionHasNoErrors();

        $goes->refresh();
        $stays->refresh();
        $this->assertSame(RequestStatus::Rejected, $goes->status);
        $this->assertSame(RejectReason::Duplicate, $goes->rejection_reason);
        $this->assertSame($stays->id, $goes->merged_into_id);
        // Paola (requester of the duplicate) and her +1 voter now vote for the one that stays.
        $this->assertSame(2, $stays->votes_count);
        $this->assertTrue($stays->followers()->whereKey($this->paola->id)->exists());
        $this->assertSame('merge', $stays->events()->value('kind'));

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.merge', $stays), ['into' => $stays->key])
            ->assertSessionHasErrors('into');
    }

    public function test_backlog_and_reminders(): void
    {
        Notification::fake();
        $request = $this->evaluated();

        $this->actingAs($this->jhon)->post(route('ai.requests.backlog', $request))->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Backlog, $request->fresh()->status);

        $waiting = $this->request(['status' => RequestStatus::NeedsInfo, 'first_responded_at' => now()]);
        $this->actingAs($this->jhon)->post(route('ai.requests.remind', $waiting))->assertSessionHas('success');
        Notification::assertSentTo($this->paola, AiRequestReminder::class);
        $this->assertSame('notification', $waiting->events()->value('kind'));

        // Nothing waits on the requester: nothing to remind.
        $this->actingAs($this->jhon)->post(route('ai.requests.remind', $request))->assertSessionHas('error');
    }

    public function test_it_creates_a_request_on_behalf_of_a_colleague(): void
    {
        $this->actingAs($this->jhon)
            ->post(route('ai.requests.store'), [
                'requester_id' => $this->paola->id,
                'type' => 'idea',
                'problem' => 'Me llegó por WhatsApp: quieren ver la cartera por asesor en el celular.',
                'expected_result' => 'Verla en el celular',
                'urgency' => 'low',
                'area_id' => $this->ventas->id,
            ])
            ->assertSessionHasNoErrors();

        $request = AiRequest::query()->latest('id')->first();
        $this->assertSame($this->paola->id, $request->requester_id);
        $this->assertSame($this->jhon->id, $request->created_by_id);

        // A requester cannot file for somebody else.
        $this->actingAs($this->juan)
            ->post(route('ai.requests.store'), [
                'requester_id' => $this->paola->id,
                'type' => 'idea',
                'problem' => 'Me llegó por WhatsApp: quieren ver la cartera por asesor.',
                'expected_result' => 'Verla',
                'urgency' => 'low',
                'area_id' => $this->ventas->id,
            ])
            ->assertForbidden();
    }

    public function test_actions_respect_the_company_scope(): void
    {
        $other = $this->request(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'requester_id' => $this->derivadosUser->id]);

        $this->actingAs($this->jhon)
            ->withSession(['current_company_id' => $this->segurex->id])
            ->post(route('ai.requests.ask', $other), ['question' => 'x'])
            ->assertNotFound();
    }
}
