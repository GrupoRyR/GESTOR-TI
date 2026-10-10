<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\LearnDrafts;
use App\Domain\Assistant\Unanswered;
use App\Models\KnowledgeItem;
use App\Models\UnansweredQuestion;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Unanswered questions (PROMPT 6.8.1, `Assistant/Unanswered/Index`): what
 * the assistant could not find, grouped by question, to document it.
 */
class UnansweredTest extends CurationTestCase
{
    public function test_record_groups_the_same_question_and_counts_each_ask(): void
    {
        $service = app(Unanswered::class);

        $first = $service->record($this->jhon, '¿Cada cuánto se respalda el servidor de SAP?', 'app', 10, 100);
        $this->travel(2)->hours();
        $again = $service->record($this->superAdmin, 'cada cuanto se respalda el servidor de sap', 'telegram');
        $this->travel(1)->hours();
        $service->record($this->jhon, '  ¿Cada cuánto se RESPALDA el servidor de SAP? ', 'telegram');
        $other = $service->record(null, '¿Qué IP tiene el NVR de la bodega?', 'telegram');

        $this->assertSame($first->id, $again->id);
        $this->assertNotSame($first->id, $other->id);
        $first->refresh();
        $this->assertSame(3, $first->times_asked);
        $this->assertSame('¿Cada cuánto se respalda el servidor de SAP?', $first->question);
        $this->assertSame('open', $first->status);
        $this->assertSame(now()->subHours(3)->toIso8601String(), $first->first_asked_at->toIso8601String());
        $this->assertSame(now()->toIso8601String(), $first->last_asked_at->toIso8601String());
        $this->assertSame(Unanswered::hash('cada cuanto se respalda el servidor de sap'), $first->hash);

        $ask = $first->asks()->oldest('id')->first();
        $this->assertSame(['app', 10, 100], [$ask->channel, $ask->conversation_id, $ask->message_id]);
        $this->assertSame(3, $first->asks()->count());

        // A dismissed question asked again opens a new row.
        $service->dismiss($first, $this->jhon);
        $reopened = $service->record($this->jhon, '¿Cada cuánto se respalda el servidor de SAP?');
        $this->assertNotSame($first->id, $reopened->id);
        $this->assertSame(1, $reopened->times_asked);
    }

    public function test_index_props_order_and_tab_counts(): void
    {
        $service = app(Unanswered::class);
        $backup = $service->record($this->jhon, '¿Cada cuánto se respalda el servidor de SAP?');
        $service->record($this->superAdmin, '¿Cada cuánto se respalda el servidor de SAP?');
        $this->travel(1)->hours();
        $printer = $service->record($this->superAdmin, '¿Cómo se agrega una impresora en la bodega?');
        $dismissed = $service->record($this->jhon, '¿Dónde están los manuales?');
        $service->dismiss($dismissed, $this->jhon);
        $card = $this->card(['title' => 'Informe de ventas diario']);
        app(LearnDrafts::class)->fromText($this->jhon, 'el informe de cartera sale los lunes');

        $this->assertSame(
            $this->withShared(['questions', 'order', 'card_options', 'now']),
            $this->propKeys(route('assistant.unanswered.index')),
        );

        $this->actingAs($this->jhon)
            ->get(route('assistant.unanswered.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Unanswered/Index')
                ->where('order', 'times')
                ->where('unanswered_count', 2)
                ->where('drafts_count', 1)
                ->where('now', now()->toIso8601String())
                ->where('questions', [
                    [
                        'id' => $backup->id,
                        'text' => '¿Cada cuánto se respalda el servidor de SAP?',
                        'times_asked' => 2,
                        'askers' => [
                            ['id' => $this->jhon->id, 'name' => 'Jhon Castro', 'job_title' => null, 'avatar_url' => $this->jhon->avatar_url],
                            ['id' => $this->superAdmin->id, 'name' => 'Leonardo Herrera', 'job_title' => null, 'avatar_url' => $this->superAdmin->avatar_url],
                        ],
                        'last_asked_at' => now()->subHour()->toIso8601String(),
                    ],
                    [
                        'id' => $printer->id,
                        'text' => '¿Cómo se agrega una impresora en la bodega?',
                        'times_asked' => 1,
                        'askers' => [['id' => $this->superAdmin->id, 'name' => 'Leonardo Herrera', 'job_title' => null, 'avatar_url' => $this->superAdmin->avatar_url]],
                        'last_asked_at' => now()->toIso8601String(),
                    ],
                ])
                ->where('card_options', [['key' => $card->key, 'type' => 'report', 'title' => 'Informe de ventas diario', 'visibility' => 'company']])
            );

        $this->actingAs($this->jhon)
            ->get(route('assistant.unanswered.index', ['orden' => 'recientes']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order', 'recent')
                ->where('questions.0.id', $printer->id)
                ->where('questions.1.id', $backup->id)
            );

        // The counts reach every Asistente TI page.
        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.index'))
            ->assertInertia(fn (Assert $page) => $page->where('unanswered_count', 2)->where('drafts_count', 1));
        $this->actingAs($this->reader())
            ->get(route('assistant.knowledge.index'))
            ->assertInertia(fn (Assert $page) => $page->where('unanswered_count', 0)->where('drafts_count', 0));
    }

    public function test_link_marks_the_question_documented(): void
    {
        $question = app(Unanswered::class)->record($this->jhon, '¿Qué informe muestra las ventas por zona?');
        $card = $this->card(['title' => 'Informe de ventas por zona']);
        $hidden = $this->card([], [$this->derivados], $this->derivadosVentas);
        $manager = $this->user($this->segurex, 'Usuario final', 'Gestor Segurex');
        $manager->givePermissionTo(['assistant.use', 'assistant.knowledge.manage']);

        $this->actingAs($manager)
            ->post(route('assistant.unanswered.link', $question), ['card' => $hidden->key])
            ->assertSessionHasErrors(['card' => 'Elige una ficha de la lista.']);

        $this->actingAs($this->jhon)
            ->post(route('assistant.unanswered.link', $question), ['card' => $card->key])
            ->assertRedirect(route('assistant.unanswered.index'))
            ->assertSessionHas('success', "La pregunta quedó enlazada a {$card->key}.");

        $question->refresh();
        $this->assertSame('documented', $question->status);
        $this->assertSame($card->id, $question->knowledge_item_id);
        $this->assertSame($this->jhon->id, $question->resolved_by_id);
        $this->assertNotNull($question->resolved_at);

        $this->actingAs($this->jhon)
            ->post(route('assistant.unanswered.discard', $question))
            ->assertSessionHas('error', 'Esta pregunta ya no está pendiente.');
    }

    public function test_discard_dismisses_the_question(): void
    {
        $question = app(Unanswered::class)->record($this->jhon, '¿Qué IP tiene el NVR de la bodega?');

        $this->actingAs($this->jhon)
            ->post(route('assistant.unanswered.discard', $question))
            ->assertRedirect(route('assistant.unanswered.index'))
            ->assertSessionHas('success', 'Pregunta descartada.');

        $this->assertSame('dismissed', $question->fresh()->status);
        $this->actingAs($this->jhon)
            ->get(route('assistant.unanswered.index'))
            ->assertInertia(fn (Assert $page) => $page->where('questions', [])->where('unanswered_count', 0));
    }

    public function test_documenting_prefills_the_form_and_store_resolves_the_question(): void
    {
        $question = app(Unanswered::class)->record($this->jhon, '¿Cada cuánto se respalda el servidor de SAP?');

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.create', ['pregunta' => $question->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('prefill.origin.kind', 'unanswered')
                ->where('prefill.origin.question.id', $question->id)
                ->where('prefill.origin.question.times_asked', 1)
                ->where('prefill.values', ['title' => 'Cada cuánto se respalda el servidor de SAP'])
            );

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.create', ['pregunta' => 999]))
            ->assertInertia(fn (Assert $page) => $page->where('prefill', null));

        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload(['unanswered_id' => $question->id]))
            ->assertSessionHasNoErrors();

        $card = KnowledgeItem::query()->sole();
        $this->assertSame('manual', $card->source);
        $question->refresh();
        $this->assertSame('documented', $question->status);
        $this->assertSame($card->id, $question->knowledge_item_id);

        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload(['title' => 'Otra', 'unanswered_id' => $question->id]))
            ->assertSessionHasErrors(['unanswered_id' => 'Esa pregunta ya no está pendiente.']);
    }

    public function test_permissions(): void
    {
        $question = app(Unanswered::class)->record($this->jhon, '¿Qué IP tiene el NVR de la bodega?');
        $reader = $this->reader();

        $this->actingAs($reader)
            ->get(route('assistant.unanswered.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('questions', [])->where('card_options', [])->where('unanswered_count', 0));
        $this->actingAs($reader)->post(route('assistant.unanswered.discard', $question))->assertForbidden();
        $this->actingAs($reader)->post(route('assistant.unanswered.link', $question), ['card' => 'K-001'])->assertForbidden();
        $this->actingAs($this->paola)->get(route('assistant.unanswered.index'))->assertForbidden();
        $this->assertSame('open', UnansweredQuestion::query()->findOrFail($question->id)->status);
    }
}
