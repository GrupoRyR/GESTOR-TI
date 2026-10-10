<?php

namespace Tests\Feature\Ai\Suggestions;

use App\Domain\Suggestions\SuggestionGenerator;
use App\Jobs\GenerateSuggestions;
use App\Models\AiInteraction;
use App\Models\AiRequest;
use App\Models\AiSuggestion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * `ai.requests.suggestions.store` and `ai.suggestions.{accept, discard,
 * regenerate, cancel}`: states, the cancel race and "never automatic".
 */
class SuggestionRoutesTest extends SuggestionsTestCase
{
    public function test_suggest_runs_one_call_and_stores_every_request_kind(): void
    {
        $request = $this->longRequest();
        $gia121 = $this->request(['title' => 'Alerta de pedidos bloqueados por cartera', 'people_count' => 4]);

        $this->suggest($request, $this->requestAnswer([
            ['key' => $gia121->key, 'level' => 'alto', 'reason_summary' => 'mismo problema y misma app', 'reasons' => ['Mismo problema.'], 'differences' => ['Pide correo.']],
            ['key' => 'GIA-9999', 'level' => 'alto', 'reason_summary' => 'inventada', 'reasons' => [], 'differences' => []],
            ['key' => $request->key, 'level' => 'medio', 'reason_summary' => 'ella misma', 'reasons' => [], 'differences' => []],
        ]));

        Http::assertSentCount(1);
        $this->assertSame('suggestion.request', AiInteraction::query()->sole()->feature);

        $summary = $this->kind($request, 'summary');
        $this->assertSame('ready', $summary->state);
        $this->assertSame('deepseek', $summary->provider);
        $this->assertSame('deepseek-chat', $summary->model);
        $this->assertSame($this->jhon->id, $summary->requested_by_id);
        $this->assertSame(AiInteraction::query()->sole()->id, $summary->ai_interaction_id);
        $this->assertStringStartsWith('Paola revisa', $summary->payload['text']);

        $this->assertSame([
            ['id' => 1, 'text' => 'El asesor ve un aviso en Toma Pedidos apenas SAP bloquea su pedido.'],
            ['id' => 2, 'text' => 'El aviso llega también a su correo.'],
        ], $this->kind($request, 'criteria')->payload['items']);
        $this->assertSame(['size' => 'M', 'reason' => 'El bloqueo ya se lee del Visualizador de cartera.'], $this->kind($request, 'size')->payload);

        // Only a real, open request of the offered list becomes a duplicate.
        $duplicate = $this->kind($request, 'duplicate');
        $this->assertSame(1, AiSuggestion::query()->where('kind', 'duplicate')->count());
        $this->assertSame(['key' => $gia121->key, 'title' => 'Alerta de pedidos bloqueados por cartera', 'status' => 'received', 'people' => 4], $duplicate->payload['request']);
        $this->assertSame('alto', $duplicate->payload['level']);
    }

    public function test_never_applied_by_itself(): void
    {
        $request = $this->longRequest();
        $before = $request->fresh()->only(['title', 'problem', 'expected_result', 'size', 'summary_text', 'status', 'final_priority']);

        $this->suggest($request);
        $this->actingAs($this->jhon)->post(route('ai.suggestions.accept', $this->kind($request, 'size')))->assertRedirect();
        $this->actingAs($this->jhon)->post(route('ai.suggestions.accept', $this->kind($request, 'criteria')))->assertRedirect();

        $this->assertSame($before, $request->fresh()->only(array_keys($before)));
        $this->assertSame(0, $request->criteria()->count());
        $this->assertSame('accepted', $this->kind($request, 'size')->state);
    }

    public function test_accepting_the_summary_stores_the_edited_text_as_the_it_summary(): void
    {
        $request = $this->longRequest();
        $this->suggest($request);
        $summary = $this->kind($request, 'summary');

        $this->actingAs($this->jhon)
            ->post(route('ai.suggestions.accept', $summary), ['value' => '  Aviso automático de pedidos bloqueados.  '])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $summary->refresh();
        $this->assertSame('accepted', $summary->state);
        $this->assertSame($this->jhon->id, $summary->accepted_by_id);
        $this->assertNotNull($summary->accepted_at);
        $this->assertSame(['text' => 'Aviso automático de pedidos bloqueados.'], $summary->accepted_value);
        $this->assertSame('Aviso automático de pedidos bloqueados.', $request->fresh()->summary_text);
        $this->assertSame('ai_suggestion_accepted', Activity::query()->where('log_name', 'ai')->latest('id')->first()?->event);

        // Without a value, the suggested text is used.
        $other = $this->longRequest();
        $this->suggest($other);
        $this->actingAs($this->jhon)->post(route('ai.suggestions.accept', $this->kind($other, 'summary')))->assertRedirect();
        $this->assertStringStartsWith('Paola revisa', (string) $other->fresh()->summary_text);
    }

    public function test_accept_validation_and_states(): void
    {
        $request = $this->longRequest();
        $this->suggest($request);
        $summary = $this->kind($request, 'summary');

        $this->actingAs($this->jhon)->post(route('ai.suggestions.accept', $summary), ['value' => str_repeat('a', 5001)])->assertSessionHasErrors('value');
        $this->actingAs($this->jhon)->post(route('ai.suggestions.accept', $summary), ['value' => '   '])->assertSessionHasErrors('value');

        $this->actingAs($this->jhon)->post(route('ai.suggestions.discard', $summary))->assertSessionHasNoErrors();
        $this->actingAs($this->jhon)->post(route('ai.suggestions.accept', $summary))->assertSessionHasErrors('suggestion');
        $this->assertNull($request->fresh()->summary_text);
    }

    public function test_discard_keeps_the_reason_and_never_touches_the_other_request(): void
    {
        $request = $this->longRequest();
        $gia121 = $this->request(['title' => 'Alerta de pedidos bloqueados']);
        $this->suggest($request, $this->requestAnswer([['key' => $gia121->key, 'level' => 'medio', 'reason_summary' => 'misma app', 'reasons' => [], 'differences' => []]]));
        $duplicate = $this->kind($request, 'duplicate');

        $this->actingAs($this->jhon)
            ->post(route('ai.suggestions.discard', $duplicate), ['reason' => 'GIA-121 es de otra área.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $duplicate->refresh();
        $this->assertSame('discarded', $duplicate->state);
        $this->assertSame('GIA-121 es de otra área.', $duplicate->discard_reason);
        $this->assertSame($this->jhon->id, $duplicate->discarded_by_id);
        $this->assertSame($gia121->updated_at->toIso8601String(), $gia121->fresh()->updated_at->toIso8601String());
        $this->assertSame('ai_suggestion_discarded', Activity::query()->where('log_name', 'ai')->latest('id')->first()?->event);

        $this->actingAs($this->jhon)->post(route('ai.suggestions.discard', $this->kind($request, 'size')), ['reason' => str_repeat('x', 501)])->assertSessionHasErrors('reason');

        // Regenerating duplicates never brings a discarded request back.
        $this->provider(['duplicates' => [['key' => $gia121->key, 'level' => 'alto', 'reason_summary' => 'misma app', 'reasons' => [], 'differences' => []]]]);
        $this->actingAs($this->jhon)->post(route('ai.suggestions.regenerate', $duplicate))->assertSessionHasNoErrors();
        $this->assertSame(0, AiSuggestion::query()->where('kind', 'duplicate')->where('state', '!=', 'discarded')->whereNull('superseded_at')->count());
    }

    public function test_regenerate_runs_one_kind_and_supersedes_the_old_suggestion(): void
    {
        $request = $this->longRequest();
        $this->suggest($request);
        $old = $this->kind($request, 'size');

        $this->provider(['size' => ['size' => 'L', 'reason' => 'Hay que tocar SAP y el correo.']]);
        $this->actingAs($this->jhon)->post(route('ai.suggestions.regenerate', $old))->assertRedirect()->assertSessionHasNoErrors();

        $new = $this->kind($request, 'size');
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('ready', $new->state);
        $this->assertSame('L', $new->payload['size']);
        $this->assertNotNull($old->fresh()->superseded_at);
        $this->assertSame('suggestion.size', AiInteraction::query()->latest('id')->first()->feature);
        $this->assertStringNotContainsString('"duplicates"', $this->lastPrompt());

        // A suggestion still being generated cannot be generated again.
        Queue::fake();
        $this->actingAs($this->jhon)->post(route('ai.suggestions.regenerate', $new))->assertSessionHasNoErrors();
        $pending = $this->kind($request, 'size');
        $this->assertSame('generating', $pending->state);
        $this->actingAs($this->jhon)->post(route('ai.suggestions.regenerate', $pending))->assertSessionHasErrors('suggestion');
        Queue::assertPushed(GenerateSuggestions::class, 1);
    }

    public function test_cancel_wins_over_a_late_answer(): void
    {
        $request = $this->longRequest();
        Queue::fake();

        $this->actingAs($this->jhon)->post(route('ai.requests.suggestions.store', $request))->assertSessionHasNoErrors();
        $this->assertSame(['generating'], AiSuggestion::query()->pluck('state')->unique()->values()->all());
        $this->assertSame(3, AiSuggestion::query()->count());

        $summary = $this->kind($request, 'summary');
        $this->actingAs($this->jhon)->post(route('ai.suggestions.cancel', $summary))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $summary->fresh()->state);

        // The queued job answers after the cancel: the summary stays cancelled.
        $this->provider($this->requestAnswer());
        Queue::pushed(GenerateSuggestions::class)->sole()->handle(app(SuggestionGenerator::class));

        $this->assertSame('cancelled', $summary->fresh()->state);
        $this->assertNull($summary->fresh()->payload);
        $this->assertSame('ready', $this->kind($request, 'criteria')->state);

        // Everything cancelled: the provider is not called at all.
        Http::assertSentCount(1);
        $this->actingAs($this->jhon)->post(route('ai.suggestions.cancel', $this->kind($request, 'size')))->assertSessionHasErrors('suggestion');
        $this->actingAs($this->jhon)->post(route('ai.suggestions.regenerate', $this->kind($request, 'size')))->assertSessionHasNoErrors();
        $again = $this->kind($request, 'size');
        $this->actingAs($this->jhon)->post(route('ai.suggestions.cancel', $again))->assertSessionHasNoErrors();
        Queue::pushed(GenerateSuggestions::class)->last()->handle(app(SuggestionGenerator::class));
        Http::assertSentCount(1);
        $this->assertSame('cancelled', $again->fresh()->state);
    }

    public function test_provider_failures_and_unreadable_answers_become_error_states(): void
    {
        $request = $this->longRequest();
        $this->suggest($request, null);
        $this->provider(500, 500);
        $this->actingAs($this->jhon)->post(route('ai.suggestions.regenerate', $this->kind($request, 'summary')))->assertSessionHasNoErrors();

        $failed = $this->kind($request, 'summary');
        $this->assertSame('error', $failed->state);
        $this->assertSame('ai_error', $failed->error['code']);
        $this->assertSame('No se pudo generar la sugerencia.', $failed->error['message']);
        $this->assertStringContainsString('La solicitud no cambió.', (string) $failed->error['detail']);

        $this->provider('esto no es json');
        $this->actingAs($this->jhon)->post(route('ai.suggestions.regenerate', $failed))->assertSessionHasNoErrors();
        $this->assertSame('bad_answer', $this->kind($request, 'summary')->error['code']);
    }

    public function test_a_request_with_too_little_text_is_not_sent(): void
    {
        $request = $this->request(['title' => 'Comparar precios', 'problem' => 'Comparar precios de proveedores.', 'current_process' => null, 'expected_result' => 'Una tabla.']);
        Http::fake();

        $this->actingAs($this->jhon)->post(route('ai.requests.suggestions.store', $request))->assertSessionHasNoErrors();

        Http::assertNothingSent();
        $summary = $this->kind($request, 'summary');
        $this->assertSame('error', $summary->state);
        $this->assertSame('too_short', $summary->error['code']);
        $this->assertSame('No hay suficiente texto para resumir.', $summary->error['message']);
        $this->assertSame(1, AiSuggestion::query()->count());
    }

    public function test_suggest_is_refused_when_the_ai_is_off_or_everything_was_suggested(): void
    {
        $request = $this->longRequest();
        config(['services.deepseek.api_key' => null]);
        $this->actingAs($this->jhon)->post(route('ai.requests.suggestions.store', $request))->assertSessionHasErrors('suggestion');
        $this->assertSame(0, AiSuggestion::query()->count());

        config(['services.deepseek.api_key' => 'test-deepseek-key']);
        $this->suggest($request);
        $this->actingAs($this->jhon)->post(route('ai.requests.suggestions.store', $request))->assertSessionHasErrors('suggestion');
    }

    public function test_routes_need_ai_manage_and_the_company_of_the_request(): void
    {
        $request = $this->longRequest();
        $this->suggest($request);
        $summary = $this->kind($request, 'summary');

        $this->actingAs($this->paola)->post(route('ai.requests.suggestions.store', $request))->assertForbidden();
        foreach (['accept', 'discard', 'regenerate', 'cancel'] as $action) {
            $this->actingAs($this->paola)->post(route("ai.suggestions.{$action}", $summary))->assertForbidden();
        }

        $other = $this->request(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'requester_id' => $this->derivadosUser->id]);
        $otherSuggestion = AiSuggestion::factory()->for($other, 'subject')->create(['kind' => 'summary', 'state' => 'ready', 'payload' => ['text' => 'x']]);

        $this->actingAs($this->jhon)->withSession(['current_company_id' => $this->segurex->id])
            ->post(route('ai.requests.suggestions.store', $other))->assertNotFound();
        $this->actingAs($this->jhon)->withSession(['current_company_id' => $this->segurex->id])
            ->post(route('ai.suggestions.accept', $otherSuggestion))->assertNotFound();
        $this->assertSame('ready', $otherSuggestion->fresh()->state);
    }

    public function test_a_suggestion_turns_stale_when_its_input_changes(): void
    {
        $request = $this->longRequest();
        $this->suggest($request);

        $this->travel(5)->minutes();
        AiRequest::query()->whereKey($request->id)->update(['expected_result' => 'Solo un correo diario con los pedidos bloqueados.', 'updated_at' => now()]);

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.show', $request))
            ->assertInertia(fn ($page) => $page
                ->where('suggestions.summary.state', 'stale')
                ->where('suggestions.summary.stale.reason', 'Cambió el resultado esperado')
                ->where('suggestions.size.state', 'stale')
                ->where('suggestions.summary.payload.text', $this->kind($request, 'summary')->payload['text'])
            );

        // A stale suggestion can still be used.
        $this->actingAs($this->jhon)->post(route('ai.suggestions.accept', $this->kind($request, 'summary')))->assertSessionHasNoErrors();
    }
}
