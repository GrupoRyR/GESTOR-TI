<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\DraftParser;
use App\Domain\Assistant\DraftRejected;
use App\Domain\Assistant\HeuristicDraftParser;
use App\Domain\Assistant\LearnDrafts;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\KnowledgeDraft;
use App\Models\KnowledgeItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `/aprender` drafts (PROMPT 6.8.1, `Assistant/Drafts/Index`): the only
 * write the assistant makes; nothing is published until approved.
 */
class LearnDraftsTest extends CurationTestCase
{
    private const MESSAGE = 'el informe de ventas diario lo envía Toma Pedidos a las 6 a. m. a gerencia';

    private function draft(string $text = self::MESSAGE, ?User $author = null, string $source = 'telegram'): KnowledgeDraft
    {
        return app(LearnDrafts::class)->fromText($author ?? $this->jhon, $text, $source, '12345', 77);
    }

    public function test_from_text_stores_the_raw_text_with_a_heuristic_prefill(): void
    {
        $this->assertInstanceOf(HeuristicDraftParser::class, app(DraftParser::class));

        $draft = $this->draft();

        $this->assertSame('pending', $draft->status);
        $this->assertSame(self::MESSAGE, $draft->message);
        $this->assertSame('telegram', $draft->source);
        $this->assertSame($this->jhon->id, $draft->requested_by_id);
        $this->assertSame('12345', $draft->telegram_chat_id);
        $this->assertSame(77, $draft->telegram_message_id);
        $this->assertSame('report', $draft->proposed['type']);
        $this->assertSame('El informe de ventas diario lo envía Toma Pedidos a las 6 a. m. a gerencia', $draft->proposed['title']);
        $this->assertSame('El informe de ventas diario lo envía Toma Pedidos a las 6 a. m. a gerencia.', $draft->proposed['description']);
        $this->assertSame([$this->segurex->id], $draft->proposed['company_ids']);
        $this->assertSame('assumed', $draft->proposed['origins']['company']);
        $this->assertSame($this->ventas->id, $draft->proposed['area_id']);
        $this->assertSame('message', $draft->proposed['origins']['area']);
        $this->assertSame('it', $draft->proposed['visibility']);
        $this->assertSame('2027-04-07', $draft->proposed['review_at']);
        $this->assertSame('Toma Pedidos', $draft->proposed['fields']['source_system']);
        $this->assertSame('6 a. m.', $draft->proposed['fields']['schedule']);
        $this->assertSame('gerencia', $draft->proposed['fields']['recipients']);
        $this->assertSame(['shows', 'owner'], $draft->missing);

        $named = $this->draft('la VPN de Derivados usa FortiClient y la cuenta la crea Leonardo', $this->leonardoUser());
        $this->assertSame('system', $named->proposed['type']);
        $this->assertSame([$this->derivados->id], $named->proposed['company_ids']);
        $this->assertSame('message', $named->proposed['origins']['company']);
        $this->assertSame('la VPN de Derivados usa FortiClient y la cuenta la crea Leonardo', $named->message);
    }

    public function test_from_text_refuses_empty_text_and_secrets(): void
    {
        foreach (['   ', 'la clave del NVR es password=Bodega2024'] as $text) {
            try {
                $this->draft($text);
                $this->fail('Expected DraftRejected');
            } catch (DraftRejected $rejected) {
                $this->assertNotSame('', $rejected->getMessage());
            }
        }

        $this->assertSame(0, KnowledgeDraft::query()->count());
    }

    public function test_index_returns_the_contract_props_and_the_selected_draft(): void
    {
        $older = $this->draft('robotcamara revisa las cámaras cada 15 minutos y avisa por Telegram si una no responde', source: 'app');
        $this->travel(5)->minutes();
        $similar = $this->card(['title' => 'Informe de ventas diario']);
        $newer = $this->draft(self::MESSAGE.' (ver '.$similar->key.')');

        $this->assertSame(
            $this->withShared(['drafts', 'selected', 'requested', 'opened_from', 'now']),
            $this->propKeys(route('assistant.drafts.index')),
        );

        $this->actingAs($this->jhon)
            ->get(route('assistant.drafts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Drafts/Index')
                ->where('drafts_count', 2)
                ->has('drafts', 2)
                ->where('drafts.0.id', $newer->id)
                ->where('drafts.1.id', $older->id)
                ->where('selected.id', $newer->id)
                ->where('requested', false)
                ->where('opened_from', null)
                ->where('drafts.0', fn ($draft) => $draft['author']['id'] === $this->jhon->id
                    && $draft['channel'] === 'telegram'
                    && $draft['received_at'] === $newer->created_at->toIso8601String()
                    && $draft['similar_to']['key'] === $similar->key
                    && $draft['linked_card']['key'] === $similar->key
                    && $draft['proposed']['type'] === 'report'
                    && $draft['proposed']['company_ids'] === [$this->segurex->id]
                    && $draft['proposed']['area'] === ['id' => $this->ventas->id, 'name' => 'Ventas', 'company_id' => $this->segurex->id]
                    && $draft['proposed']['visibility'] === 'it'
                    && $draft['proposed']['review_at'] === '2027-04-07'
                    && count($draft) === 8
                    && count($draft['proposed']) === 8)
                ->where('drafts.0.proposed.fields', [
                    ['label' => 'Empresa', 'value' => 'Segurex', 'origin' => 'assumed'],
                    ['label' => 'Área', 'value' => 'Ventas', 'origin' => 'message'],
                    ['label' => 'Qué muestra', 'value' => null, 'origin' => 'missing'],
                    ['label' => 'Quién lo envía · sistema origen', 'value' => 'Toma Pedidos', 'origin' => 'message'],
                    ['label' => 'Destinatarios', 'value' => 'gerencia (ver '.$similar->key.')', 'origin' => 'message'],
                    ['label' => 'Horario', 'value' => '6 a. m.', 'origin' => 'message'],
                    ['label' => 'Dueño', 'value' => null, 'origin' => 'missing'],
                ])
                ->where('drafts.1.similar_to', null)
                ->where('drafts.1.linked_card', null)
                ->where('drafts.1.channel', 'app')
            );

        $this->actingAs($this->jhon)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Assistant/Drafts/Index',
                'X-Inertia-Partial-Data' => 'selected,requested',
            ])
            ->get(route('assistant.drafts.index', ['borrador' => $older->id, 'desde' => 'telegram']))
            ->assertOk()
            ->assertJsonPath('props.selected.id', $older->id)
            ->assertJsonPath('props.requested', true)
            ->assertJsonMissingPath('props.drafts');
        $this->flushHeaders();

        $this->actingAs($this->jhon)
            ->get(route('assistant.drafts.index', ['borrador' => $older->id, 'desde' => 'telegram']))
            ->assertInertia(fn (Assert $page) => $page->where('opened_from', 'telegram')->where('selected.id', $older->id));

        $this->actingAs($this->jhon)
            ->get(route('assistant.drafts.index', ['borrador' => 999]))
            ->assertInertia(fn (Assert $page) => $page->where('selected.id', $newer->id)->where('requested', false));
    }

    public function test_approve_publishes_a_card_with_source_learn(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->jhon)
            ->post(route('assistant.drafts.approve', $draft), ['visibility' => 'company', 'review_at' => '2027-01-07'])
            ->assertRedirect(route('assistant.drafts.index'))
            ->assertSessionHas('success', fn (string $message) => str_starts_with($message, 'Ficha K-') && str_ends_with($message, ' publicada.'));

        $card = KnowledgeItem::query()->sole();
        $this->assertSame('learn', $card->source);
        $this->assertSame('report', $card->type);
        $this->assertSame('company', $card->visibility);
        $this->assertSame('2027-01-07', $card->review_at->toDateString());
        $this->assertSame($this->ventas->id, $card->area_id);
        $this->assertNull($card->owner_id);
        $this->assertSame('Toma Pedidos', $card->fields['source_system']);
        $this->assertNull($card->fields['shows']);
        $this->assertSame([$this->segurex->id], $card->companies->pluck('id')->map(fn ($id) => (int) $id)->all());

        $draft->refresh();
        $this->assertSame('approved', $draft->status);
        $this->assertSame($card->id, $draft->knowledge_item_id);
        $this->assertSame($this->jhon->id, $draft->decided_by_id);
        $this->assertNotNull($draft->decided_at);

        $this->actingAs($this->jhon)
            ->post(route('assistant.drafts.approve', $draft), ['visibility' => 'company', 'review_at' => '2027-01-07'])
            ->assertSessionHas('error', 'Este borrador ya se decidió.');
        $this->assertSame(1, KnowledgeItem::query()->count());
    }

    public function test_approve_needs_a_title_and_a_company_and_valid_values(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->jhon)
            ->post(route('assistant.drafts.approve', $draft), ['visibility' => 'todos', 'review_at' => 'mañana'])
            ->assertSessionHasErrors(['visibility', 'review_at']);

        $noCompany = $draft->replicate();
        $noCompany->proposed = [...$draft->proposed, 'company_ids' => []];
        $noCompany->save();

        $this->actingAs($this->jhon)
            ->post(route('assistant.drafts.approve', $noCompany), ['visibility' => 'it', 'review_at' => '2027-04-07'])
            ->assertRedirect(route('assistant.drafts.index', ['borrador' => $noCompany->id]))
            ->assertSessionHas('error', 'Falta la empresa. Usa “Editar antes de aprobar” para completarla.');

        $noTitle = $draft->replicate();
        $noTitle->proposed = [...$draft->proposed, 'title' => ' '];
        $noTitle->save();

        $this->actingAs($this->jhon)
            ->post(route('assistant.drafts.approve', $noTitle), ['visibility' => 'it', 'review_at' => '2027-04-07'])
            ->assertSessionHas('error', 'Falta el título. Usa “Editar antes de aprobar” para completarlo.');

        $this->assertSame(0, KnowledgeItem::query()->count());
    }

    public function test_discard_requires_a_reason(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->jhon)
            ->post(route('assistant.drafts.discard', $draft), ['reason' => ' '])
            ->assertSessionHasErrors(['reason' => 'Escribe por qué lo descartas.']);

        $this->actingAs($this->jhon)
            ->post(route('assistant.drafts.discard', $draft), ['reason' => 'Ya está en K-031'])
            ->assertRedirect(route('assistant.drafts.index'))
            ->assertSessionHas('success', 'Borrador descartado.');

        $draft->refresh();
        $this->assertSame('discarded', $draft->status);
        $this->assertSame('Ya está en K-031', $draft->discard_reason);
        $this->assertSame($this->jhon->id, $draft->decided_by_id);
        $this->assertSame(0, KnowledgeItem::query()->count());
    }

    public function test_merge_adds_the_new_facts_to_an_existing_card(): void
    {
        $card = $this->card(['title' => 'Informe de ventas diario', 'description' => 'Pedidos del día.', 'fields' => ['shows' => 'Pedidos', 'recipients' => 'Directores']]);
        $draft = $this->draft();

        $this->actingAs($this->jhon)
            ->post(route('assistant.drafts.merge', ['draft' => $draft, 'card' => $card->key]))
            ->assertRedirect(route('assistant.knowledge.show', $card))
            ->assertSessionHas('success', "Agregaste lo nuevo a {$card->key}.");

        $card->refresh();
        $this->assertSame('Toma Pedidos', $card->fields['source_system']);
        $this->assertSame('6 a. m.', $card->fields['schedule']);
        $this->assertSame('Directores', $card->fields['recipients']);
        $this->assertSame("Pedidos del día.\n\nEl informe de ventas diario lo envía Toma Pedidos a las 6 a. m. a gerencia.", $card->description);

        $draft->refresh();
        $this->assertSame('merged', $draft->status);
        $this->assertSame($card->id, $draft->knowledge_item_id);
    }

    public function test_edit_before_approving_prefills_the_form_and_store_approves_the_draft(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.create', ['borrador' => $draft->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('prefill.origin', [
                    'kind' => 'draft',
                    'draft_id' => $draft->id,
                    'author' => ['id' => $this->jhon->id, 'name' => 'Jhon Castro', 'job_title' => null, 'avatar_url' => $this->jhon->avatar_url],
                    'channel' => 'telegram',
                    'message' => self::MESSAGE,
                ])
                ->where('prefill.values', [
                    'type' => 'report',
                    'title' => 'El informe de ventas diario lo envía Toma Pedidos a las 6 a. m. a gerencia',
                    'description' => 'El informe de ventas diario lo envía Toma Pedidos a las 6 a. m. a gerencia.',
                    'fields' => ['shows' => null, 'source_system' => 'Toma Pedidos', 'recipients' => 'gerencia', 'schedule' => '6 a. m.', 'format' => null],
                    'company_ids' => [$this->segurex->id],
                    'area_id' => $this->ventas->id,
                    'owner_id' => null,
                    'visibility' => 'it',
                    'review_at' => '2027-04-07',
                ])
            );

        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload(['title' => 'Informe de ventas diario', 'draft_id' => $draft->id]))
            ->assertSessionHasNoErrors();

        $card = KnowledgeItem::query()->sole();
        $this->assertSame('learn', $card->source);
        $draft->refresh();
        $this->assertSame('approved', $draft->status);
        $this->assertSame($card->id, $draft->knowledge_item_id);

        // A decided draft no longer prefills and cannot be approved twice.
        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.create', ['borrador' => $draft->id]))
            ->assertInertia(fn (Assert $page) => $page->where('prefill', null));
        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload(['draft_id' => $draft->id]))
            ->assertSessionHasErrors(['draft_id' => 'Ese borrador ya se aprobó o se descartó.']);
    }

    public function test_permissions(): void
    {
        $draft = $this->draft();
        $reader = $this->reader();

        $this->actingAs($reader)
            ->get(route('assistant.drafts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafts', [])
                ->where('selected', null)
                ->where('drafts_count', 0)
            );

        $this->actingAs($reader)->post(route('assistant.drafts.approve', $draft), ['visibility' => 'it', 'review_at' => '2027-04-07'])->assertForbidden();
        $this->actingAs($reader)->post(route('assistant.drafts.discard', $draft), ['reason' => 'No'])->assertForbidden();
        $this->actingAs($this->paola)->get(route('assistant.drafts.index'))->assertForbidden();
        $this->assertSame('pending', $draft->fresh()->status);
    }

    private function leonardoUser(): User
    {
        return $this->superAdmin;
    }
}
