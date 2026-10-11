<?php

namespace Tests\Feature\Assistant\Chat;

use App\Domain\Assistant\AssistantEngine;
use App\Models\AiInteraction;
use App\Models\Alert;
use App\Models\AssistantCitation;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\KnowledgeDraft;
use App\Models\UnansweredQuestion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * App\Domain\Assistant\AssistantEngine through the chat endpoint
 * (PROMPT 6.8.2, 6.8.3): tools, sources, interactions, errors, cap.
 */
class AssistantEngineTest extends ChatTestCase
{
    public function test_an_answer_cites_the_cards_the_tools_returned_and_logs_each_provider_call(): void
    {
        $card = $this->card(['type' => 'report', 'title' => 'Informe de ventas diario', 'description' => 'Sale a las 6 a. m. desde Toma Pedidos SAP.']);
        $this->provider(
            $this->tool('buscar_conocimiento', ['texto' => 'informe de ventas diario']),
            $this->text("Sale a las **6:00 a. m.** desde Toma Pedidos SAP [{$card->key}]."),
        );

        $this->ask($this->jhon, '¿A qué hora sale el informe de ventas diario?')
            ->assertRedirect(route('assistant.index', ['conversation' => $this->conversationOf($this->jhon)->id]));

        $conversation = $this->conversationOf($this->jhon);
        $this->assertSame('A qué hora sale el informe de ventas diario', $conversation->title);
        [$question, $answer] = $this->messagesOf($conversation);
        $this->assertSame(['user', 'text', '¿A qué hora sale el informe de ventas diario?'], [$question->role, $question->kind, $question->body]);
        $this->assertSame(['assistant', 'answer'], [$answer->role, $answer->kind]);
        $this->assertSame('Sale a las **6:00 a. m.** desde Toma Pedidos SAP.', $answer->body);
        $this->assertSame([$card->key], $answer->data('sources'));

        // One row per provider call, with cached tokens and the configured prices.
        $rows = AiInteraction::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['deepseek', 'deepseek-chat', 'assistant', 'ok'], [$rows[1]->provider, $rows[1]->model, $rows[1]->feature, $rows[1]->status]);
        $this->assertSame([1000, 900, 20], [$rows[0]->input_tokens, $rows[0]->cached_input_tokens, $rows[0]->output_tokens]);
        $this->assertSame([1200, 1000, 80], [$rows[1]->input_tokens, $rows[1]->cached_input_tokens, $rows[1]->output_tokens]);
        $this->assertEqualsWithDelta(0.0000616, (float) $rows[0]->cost, 0.000001);
        $this->assertEqualsWithDelta(0.0001176, (float) $rows[1]->cost, 0.000001);
        $this->assertSame($this->jhon->id, $rows[1]->user_id);
        $this->assertNotNull($rows[1]->duration_ms);
        $this->assertSame($rows[1]->id, $answer->ai_interaction_id);

        $this->assertSame(1, AssistantCitation::query()->where('knowledge_item_id', $card->id)->where('message_id', $answer->id)->count());

        // Stable prefix first (cache-friendly): instructions, then the tool catalog.
        $this->assertProviderCalls(2);
        [$first, $second] = $this->sentBodies();
        $this->assertSame('deepseek-chat', $first['model']);
        $this->assertSame('system', $first['messages'][0]['role']);
        $this->assertSame(AssistantEngine::instructions(), $first['messages'][0]['content']);
        $this->assertSame($first['messages'][0], $second['messages'][0]);
        $this->assertSame(
            ['buscar_conocimiento', 'ver_ficha', 'buscar_productos', 'ver_producto', 'buscar_solicitudes_gestor_ia', 'entregar_credencial'],
            array_map(fn (array $tool) => $tool['function']['name'], $first['tools']),
        );
        $this->assertSame($first['tools'], $second['tools']);
        $this->assertStringContainsString('Informe de ventas diario', json_encode($second['messages'], JSON_UNESCAPED_UNICODE));
    }

    public function test_the_tool_loop_stops_after_five_rounds(): void
    {
        $this->provider(...array_map(fn (int $n) => $this->tool('buscar_conocimiento', ['texto' => "vuelta {$n}"], "call_{$n}"), range(1, 8)));

        $this->ask($this->jhon, '¿Qué es un pedido bloqueado?')->assertRedirect();

        $this->assertProviderCalls(5);
        $this->assertSame(5, AiInteraction::query()->count());
        $last = AssistantMessage::query()->latest('id')->first();
        $this->assertSame('no_answer', $last->kind);
    }

    public function test_tool_arguments_are_validated_against_the_schema_before_running(): void
    {
        $this->card(['title' => 'Conectarse a la VPN']);
        $this->provider(
            $this->tool('buscar_conocimiento', ['texto' => 123, 'otra' => 'x']),
            $this->tool('ver_ficha', ['clave' => 'no es una clave'], 'call_2'),
            $this->text('No lo tengo documentado.'),
        );

        $this->ask($this->jhon, '¿Cómo me conecto a la VPN?');

        [, $second, $third] = $this->sentBodies();
        $toolResult = collect($second['messages'])->firstWhere('role', 'tool');
        $this->assertStringContainsString('Argumentos inválidos', $toolResult['content']);
        $this->assertStringContainsString('texto', $toolResult['content']);
        $this->assertStringNotContainsString('Conectarse a la VPN', $toolResult['content']);
        $this->assertStringContainsString('Argumentos inválidos', collect($third['messages'])->where('role', 'tool')->last()['content']);
    }

    public function test_tools_only_see_what_the_asking_person_may_see_and_never_send_costs(): void
    {
        $this->card(['title' => 'Flujo de facturación solo TI', 'visibility' => 'it']);
        $this->card(['title' => 'Flujo de facturación de Derivados'], [$this->derivados], $this->derivadosVentas);
        $product = $this->product(['name' => 'Visualizador de cartera', 'slug' => 'visualizador-cartera', 'monthly_cost' => '987.65', 'description' => 'Cartera en línea']);
        $reader = $this->reader();

        $this->provider(
            $this->tool('buscar_conocimiento', ['texto' => 'flujo de facturación']),
            $this->tool('ver_producto', ['slug' => $product->slug], 'call_2'),
            $this->text('No lo tengo documentado.'),
        );

        $this->ask($reader, '¿Cómo es el flujo de facturación?');

        $raw = $this->sentRaw();
        $this->assertStringNotContainsString('solo TI', $raw);
        $this->assertStringNotContainsString('Derivados', $raw);
        $this->assertStringContainsString('Visualizador de cartera', $raw);
        $this->assertStringNotContainsString('987', $raw);
        $this->assertStringNotContainsString('monthly_cost', $raw);
        $this->assertStringNotContainsString('cost', strtolower(json_encode(collect($this->sentBodies()[2]['messages'])->where('role', 'tool')->pluck('content')->all())));
    }

    public function test_not_documented_answers_register_the_question(): void
    {
        $this->provider(
            $this->tool('buscar_conocimiento', ['texto' => 'respaldo servidor sap']),
            $this->text('No lo tengo documentado. Ninguna ficha habla de respaldos del servidor de SAP.'),
            $this->tool('buscar_conocimiento', ['texto' => 'respaldo servidor sap'], 'call_9'),
            $this->text('No lo tengo documentado.'),
        );

        $this->ask($this->jhon, '¿Cada cuánto se respalda el servidor de SAP?');
        $conversation = $this->conversationOf($this->jhon);
        $answer = AssistantMessage::query()->latest('id')->first();

        $this->assertSame('no_answer', $answer->kind);
        $this->assertSame('**No lo tengo documentado.** Registré la pregunta para que TI la documente.', $answer->body);
        $this->assertSame('Ninguna ficha habla de respaldos del servidor de SAP.', $answer->data('explanation'));
        $question = UnansweredQuestion::query()->sole();
        $this->assertSame(1, $question->times_asked);
        $this->assertSame($conversation->id, $question->asks()->sole()->conversation_id);
        $this->assertSame($answer->id, $question->asks()->sole()->message_id);
        $this->assertSame(1, $answer->data('times_asked'));

        $this->ask($this->jhon, '¿Cada cuánto se respalda el servidor de SAP?', $conversation);
        $this->assertSame(2, $question->fresh()->times_asked);
        $this->assertSame(2, AssistantMessage::query()->latest('id')->first()->data('times_asked'));
    }

    public function test_provider_failures_are_retried_once_then_answer_unavailable(): void
    {
        Log::spy();
        $this->provider(500, 503);

        $this->ask($this->jhon, '¿Quién recibe el informe de ventas?')->assertRedirect();

        $this->assertProviderCalls(2);
        $error = AssistantMessage::query()->latest('id')->first();
        $this->assertSame(['assistant', 'error', 'El asistente no está disponible en este momento, intenta en unos minutos.'], [$error->role, $error->kind, $error->body]);
        $this->assertTrue($error->data('retryable'));
        $this->assertSame(['error', 'error'], AiInteraction::query()->orderBy('id')->pluck('status')->all());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'Assistant provider failed'));

        $this->actingAs($this->jhon)->get(route('assistant.index', ['conversation' => $error->conversation_id]))
            ->assertInertia(fn ($page) => $page->where('status', 'unavailable'));
    }

    public function test_a_rate_limit_is_retried_once_and_the_answer_goes_on(): void
    {
        $this->provider(429, $this->text('Hola, ¿en qué te ayudo?'));

        $this->ask($this->jhon, 'Hola');

        $this->assertProviderCalls(2);
        $this->assertSame(['error', 'ok'], AiInteraction::query()->orderBy('id')->pluck('status')->all());
        $answer = AssistantMessage::query()->latest('id')->first();
        $this->assertSame(['answer', 'Hola, ¿en qué te ayudo?', []], [$answer->kind, $answer->body, $answer->data('sources')]);
    }

    public function test_retry_asks_the_same_question_again_without_a_new_user_bubble(): void
    {
        $this->provider(500, 500, $this->text('Sale a las 6 a. m.'));
        $this->ask($this->jhon, '¿A qué hora sale el informe?');
        $error = AssistantMessage::query()->latest('id')->first();

        $this->actingAs($this->jhon)->post(route('assistant.messages.retry', $error))
            ->assertRedirect(route('assistant.index', ['conversation' => $error->conversation_id]));

        $messages = $this->messagesOf($this->conversationOf($this->jhon));
        $this->assertSame(['text', 'answer'], array_map(fn (AssistantMessage $m) => $m->kind, $messages));
        $this->assertNull(AssistantMessage::query()->find($error->id));
    }

    public function test_only_a_retryable_last_error_of_your_own_conversation_can_be_retried(): void
    {
        $this->provider($this->text('Respuesta'));
        $this->ask($this->jhon, 'Pregunta');
        $answer = AssistantMessage::query()->latest('id')->first();

        $this->actingAs($this->jhon)->post(route('assistant.messages.retry', $answer))->assertNotFound();

        $error = AssistantMessage::query()->create(['conversation_id' => $answer->conversation_id, 'role' => 'assistant', 'kind' => 'error', 'body' => 'x', 'payload' => ['retryable' => true]]);
        $this->actingAs($this->superAdmin)->post(route('assistant.messages.retry', $error))->assertNotFound();
    }

    public function test_without_a_key_the_assistant_is_off_and_nothing_is_sent(): void
    {
        config(['services.deepseek.api_key' => null]);
        Http::fake();

        $this->ask($this->jhon, '¿Quién recibe el informe?')->assertSessionHasErrors(['question' => 'El asistente no está activo en este momento.']);

        Http::assertNothingSent();
        $this->assertSame(0, AssistantMessage::query()->count());
        $this->actingAs($this->jhon)->get(route('assistant.index'))->assertInertia(fn ($page) => $page->where('status', 'off_no_key'));
    }

    public function test_the_monthly_cap_warns_once_at_eighty_percent_and_stops_at_one_hundred(): void
    {
        $this->setting('ai.monthly_cost_cap_usd', 1.0);
        AiInteraction::query()->create(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'assistant', 'cost' => 0.79995, 'status' => 'ok']);
        $this->provider($this->text('Uno'), $this->text('Dos'));

        $this->ask($this->jhon, 'Primera');
        $this->ask($this->jhon, 'Segunda', $this->conversationOf($this->jhon));

        $alerts = Alert::query()->where('source', 'ai')->get();
        $this->assertCount(1, $alerts);
        $this->assertSame('warning', $alerts[0]->level);
        $this->assertStringContainsString('80 %', $alerts[0]->title);
        $this->assertSame(1, $alerts[0]->occurrences);

        AiInteraction::query()->create(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'assistant', 'cost' => 0.3, 'status' => 'ok']);
        $this->ask($this->jhon, 'Tercera', $this->conversationOf($this->jhon))
            ->assertSessionHasErrors(['question' => 'El asistente llegó al tope de gasto del mes.']);

        $this->assertProviderCalls(2);
        $this->actingAs($this->jhon)->get(route('assistant.index'))->assertInertia(fn ($page) => $page->where('status', 'off_cap')->where('monthly_cap', 'US$ 1'));
        $this->assertSame(1, Alert::query()->where('source', 'ai')->where('level', 'critical')->count());
    }

    public function test_the_context_keeps_the_last_thirty_minutes_of_the_conversation(): void
    {
        $conversation = AssistantConversation::query()->create(['user_id' => $this->jhon->id, 'title' => 'Ventas', 'last_activity_at' => now()]);
        $old = $conversation->messages()->create(['role' => 'user', 'kind' => 'text', 'body' => 'Pregunta vieja de hace una hora']);
        $old->forceFill(['created_at' => now()->subMinutes(40)])->save();
        $recent = $conversation->messages()->create(['role' => 'user', 'kind' => 'text', 'body' => 'Pregunta reciente sobre ventas']);
        $recent->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $reply = $conversation->messages()->create(['role' => 'assistant', 'kind' => 'answer', 'body' => 'Respuesta reciente', 'payload' => ['sources' => []]]);
        $reply->forceFill(['created_at' => now()->subMinutes(9)])->save();
        $this->provider($this->text('Listo'));

        $this->ask($this->jhon, '¿Y a qué hora?', $conversation);

        $messages = $this->sentBodies()[0]['messages'];
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame('Pregunta reciente sobre ventas', $messages[1]['content']);
        $this->assertSame('Respuesta reciente', $messages[2]['content']);
        $this->assertSame('¿Y a qué hora?', $messages[3]['content']);
    }

    public function test_conversations_are_private_to_their_owner(): void
    {
        $conversation = AssistantConversation::query()->create(['user_id' => $this->superAdmin->id, 'title' => 'Privada', 'last_activity_at' => now()]);
        Http::fake();

        $this->actingAs($this->jhon)->get(route('assistant.index', ['conversation' => $conversation->id]))->assertNotFound();
        $this->ask($this->jhon, 'Hola', $conversation)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_aprender_in_the_chat_creates_a_draft_even_with_the_assistant_off(): void
    {
        config(['services.deepseek.api_key' => null]);
        Http::fake();

        $this->ask($this->jhon, '/aprender El informe de ventas diario lo envía Toma Pedidos a las 6 a. m. a gerencia');

        $answer = AssistantMessage::query()->latest('id')->first();
        $this->assertSame('answer', $answer->kind);
        $this->assertStringContainsString('borrador', $answer->body);
        $this->assertSame(1, KnowledgeDraft::query()->where('source', 'app')->count());
        Http::assertNothingSent();
    }

    public function test_questions_are_required_and_short(): void
    {
        Http::fake();

        $this->ask($this->jhon, '')->assertSessionHasErrors('question');
        $this->ask($this->jhon, str_repeat('a', 501))->assertSessionHasErrors('question');
        $this->ask($this->reader($this->derivados, 'Sin asistente')->syncPermissions([]), 'Hola')->assertForbidden();
        Http::assertNothingSent();
    }
}
