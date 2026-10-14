<?php

namespace Tests\Feature\Assistant\Chat;

use App\Models\AiInteraction;
use App\Models\AssistantCitation;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `Assistant/Chat` (types/pages/assistant-chat.ts): exact props, first
 * open, conversations, the `?fuente=` preview, statuses and no access.
 */
class ChatPageTest extends ChatTestCase
{
    public function test_the_page_returns_exactly_the_contract_props(): void
    {
        $this->assertSame($this->withShared(self::PAGE_PROPS), $this->propKeys(route('assistant.index')));

        $this->actingAs($this->jhon)->get(route('assistant.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Chat')
                ->where('status', 'ready')
                // Default cap confirmed by Jhon: US$ 10 a month.
                ->where('monthly_cap', 'US$ 10')
                ->where('conversations', [])
                ->where('conversation', null)
                ->where('selected_source', null)
                ->where('revealed_credential', null)
                ->where('first_open_suggestions', config('gestor.assistant.suggestions'))
                ->where('unanswered_count', 0)
                ->where('drafts_count', 0)
                ->where('can_configure_ai', false)
                ->where('access_holders', [])
                ->where('now', now()->toIso8601String())
            );

        $this->assertCount(4, config('gestor.assistant.suggestions'));
        $this->actingAs($this->superAdmin)->get(route('assistant.index'))->assertInertia(fn (Assert $page) => $page->where('can_configure_ai', true));
    }

    public function test_a_conversation_with_an_answer_and_its_sources(): void
    {
        $card = $this->card(['type' => 'report', 'title' => 'Informe de ventas diario']);
        $hidden = $this->card(['title' => 'Solo TI', 'visibility' => 'it']);
        $older = AssistantConversation::query()->create(['user_id' => $this->jhon->id, 'title' => 'Vieja', 'last_activity_at' => now()->subDay()]);
        $conversation = AssistantConversation::query()->create(['user_id' => $this->jhon->id, 'title' => 'Informe de ventas diario', 'last_activity_at' => now()]);
        AssistantConversation::query()->create(['user_id' => $this->superAdmin->id, 'title' => 'Ajena', 'last_activity_at' => now()]);
        $question = $conversation->messages()->create(['role' => 'user', 'kind' => 'text', 'body' => '¿Quién lo recibe?']);
        $answer = $conversation->messages()->create(['role' => 'assistant', 'kind' => 'answer', 'body' => 'Gerencia.', 'payload' => ['sources' => [$card->key, $hidden->key, 'K-999']]]);

        $this->actingAs($this->reader())->get(route('assistant.index'))->assertInertia(fn (Assert $page) => $page->where('conversations', []));

        $this->actingAs($this->jhon)->get(route('assistant.index', ['conversation' => $conversation->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('conversations', [
                    ['id' => $conversation->id, 'title' => 'Informe de ventas diario', 'last_message_at' => $conversation->last_activity_at->toIso8601String()],
                    ['id' => $older->id, 'title' => 'Vieja', 'last_message_at' => $older->last_activity_at->toIso8601String()],
                ])
                ->where('conversation.id', $conversation->id)
                ->where('conversation.started_at', $conversation->created_at->toIso8601String())
                ->where('conversation.messages', [
                    ['id' => $question->id, 'at' => $question->created_at->toIso8601String(), 'role' => 'user', 'kind' => 'text', 'text' => '¿Quién lo recibe?'],
                    [
                        'id' => $answer->id, 'at' => $answer->created_at->toIso8601String(), 'role' => 'assistant', 'kind' => 'answer', 'text' => 'Gerencia.',
                        // Jhon manages knowledge: he sees the "Solo TI" card; unknown keys are dropped.
                        'sources' => [
                            ['key' => $card->key, 'type' => 'report', 'title' => 'Informe de ventas diario', 'visibility' => 'company'],
                            ['key' => $hidden->key, 'type' => $hidden->type, 'title' => 'Solo TI', 'visibility' => 'it'],
                        ],
                        'suggestions' => [],
                    ],
                ])
            );
    }

    public function test_the_source_preview_is_a_partial_reload_of_a_visible_card(): void
    {
        $card = $this->card(['type' => 'report', 'title' => 'Informe de ventas diario', 'description' => 'Sale a las 6 a. m.']);
        $hidden = $this->card(['title' => 'Solo TI', 'visibility' => 'it']);

        $this->actingAs($this->jhon)
            ->withHeaders($this->partial('selected_source'))
            ->get(route('assistant.index', ['fuente' => $card->key]))
            ->assertOk()
            ->assertJsonPath('props.selected_source.key', $card->key)
            ->assertJsonPath('props.selected_source.description', 'Sale a las 6 a. m.')
            ->assertJsonPath('props.selected_source.company_ids', [$this->segurex->id])
            ->assertJsonMissingPath('props.conversations');

        $this->actingAs($this->reader())
            ->withHeaders($this->partial('selected_source'))
            ->get(route('assistant.index', ['fuente' => $hidden->key]))
            ->assertJsonPath('props.selected_source', null);
    }

    public function test_new_conversation_reuses_an_empty_one(): void
    {
        $this->actingAs($this->jhon)->post(route('assistant.conversations.store'))->assertRedirect();
        $first = $this->conversationOf($this->jhon);
        $this->actingAs($this->jhon)->post(route('assistant.conversations.store'))
            ->assertRedirect(route('assistant.index', ['conversation' => $first->id]));

        $this->assertSame(1, AssistantConversation::query()->count());
        $this->assertSame('Nueva conversación', $first->title);
    }

    public function test_people_without_the_assistant_see_who_has_it(): void
    {
        $outsider = $this->paola;

        $this->actingAs($outsider)->get(route('assistant.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('conversations', [])
                ->where('conversation', null)
                ->where('access_holders', fn ($holders) => collect($holders)->pluck('name')->sort()->values()->all() === ['Jhon Castro', 'Leonardo Herrera'])
            );

        $this->actingAs($outsider)->post(route('assistant.conversations.store'))->assertForbidden();
    }

    public function test_citations_feed_the_card_usage(): void
    {
        $card = $this->card(['title' => 'Informe de ventas diario']);
        $conversation = AssistantConversation::query()->create(['user_id' => $this->superAdmin->id, 'title' => 'x', 'last_activity_at' => now()]);
        $message = $conversation->messages()->create(['role' => 'assistant', 'kind' => 'answer', 'body' => 'x']);
        AssistantCitation::query()->create(['knowledge_item_id' => $card->id, 'message_id' => $message->id, 'user_id' => $this->jhon->id, 'cited_at' => now()->subMonths(2)]);
        AssistantCitation::query()->create(['knowledge_item_id' => $card->id, 'message_id' => $message->id, 'user_id' => $this->jhon->id, 'cited_at' => now()->subDay()]);
        AssistantCitation::query()->create(['knowledge_item_id' => $card->id, 'message_id' => $message->id, 'user_id' => $this->superAdmin->id, 'cited_at' => now()->subHour()]);

        $this->actingAs($this->jhon)->get(route('assistant.knowledge.show', $card))
            ->assertInertia(fn (Assert $page) => $page
                ->where('card.usage_count_month', 2)
                ->where('card.last_cited_at', now()->subHour()->toIso8601String())
                ->where('card.last_cited_by.name', 'Leonardo Herrera')
            );
    }

    public function test_the_ai_spend_of_integrations_includes_the_assistant(): void
    {
        AiInteraction::query()->create(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'assistant', 'cost' => 0.25, 'status' => 'ok']);
        Http::fake();

        $this->actingAs($this->superAdmin)->get(route('admin.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('ai.month_spend_usd', 0.25));
    }

    public function test_an_unanswered_question_bubble_waits_and_errors_offer_retry(): void
    {
        $conversation = AssistantConversation::query()->create(['user_id' => $this->jhon->id, 'title' => 'x', 'last_activity_at' => now()]);
        $error = $conversation->messages()->create(['role' => 'assistant', 'kind' => 'error', 'body' => 'El asistente no está disponible en este momento, intenta en unos minutos.', 'payload' => ['retryable' => true], 'status' => 'failed']);

        $this->actingAs($this->jhon)->get(route('assistant.index', ['conversation' => $conversation->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('status', 'unavailable')
                ->where('conversation.messages.0', [
                    'id' => $error->id, 'at' => $error->created_at->toIso8601String(), 'role' => 'assistant', 'kind' => 'error',
                    'text' => 'El asistente no está disponible en este momento, intenta en unos minutos.', 'retryable' => true,
                ])
            );

        $this->assertInstanceOf(AssistantMessage::class, $error);
    }
}
