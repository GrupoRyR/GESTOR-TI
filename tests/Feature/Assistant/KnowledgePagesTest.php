<?php

namespace Tests\Feature\Assistant;

use App\Models\KnowledgeItem;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `Assistant/Knowledge/{Index,Show,Create,Edit}` (types/pages/assistant-knowledge.ts).
 */
class KnowledgePagesTest extends AssistantTestCase
{
    private const INDEX_PROPS = ['cards', 'filters', 'counts', 'area_options', 'type_examples', 'today', 'unanswered_count', 'drafts_count'];

    private const SHOW_PROPS = ['card', 'today', 'now', 'unanswered_count', 'drafts_count'];

    private const FORM_PROPS = ['area_options', 'owner_options', 'related_options', 'knowledge_managers', 'type_examples', 'type_counts', 'today', 'unanswered_count', 'drafts_count'];

    public function test_index_returns_exactly_the_contract_props(): void
    {
        $this->card();

        $this->assertSame($this->withShared(self::INDEX_PROPS), $this->propKeys(route('assistant.knowledge.index')));

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Knowledge/Index')
                ->where('filters', ['q' => null, 'type' => null, 'company_id' => null, 'area_id' => null, 'visibility' => null, 'overdue' => false])
                ->where('today', self::TODAY)
                ->where('unanswered_count', 0)
                ->where('drafts_count', 0)
                ->has('cards.data', 1)
                ->has('cards.links')
                ->where('cards.meta.per_page', 8)
            );
    }

    public function test_index_rows_counts_areas_and_type_examples(): void
    {
        $report = $this->card(['type' => 'report', 'title' => 'Informe de ventas diario', 'review_at' => '2027-01-15', 'updated_at' => now()->subDays(2)]);
        $system = $this->card(['type' => 'system', 'title' => 'SAP Business One', 'visibility' => 'it', 'review_at' => '2026-09-30', 'fields' => ['what_is' => 'ERP']], [$this->segurex, $this->derivados]);
        $this->card(['type' => 'glossary', 'title' => 'Pedido bloqueado', 'review_at' => '2026-10-20', 'fields' => ['term' => 'Pedido bloqueado', 'definition' => 'Retenido por cupo']], [$this->derivados], $this->derivadosVentas);

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts', [
                    'total' => 3,
                    'by_type' => ['system' => 1, 'report' => 1, 'data_flow' => 0, 'procedure' => 0, 'glossary' => 1],
                    'by_visibility' => ['it' => 1, 'company' => 2],
                    'overdue' => 1,
                ])
                ->has('cards.data', 3)
                ->where('cards.data.2.key', $report->key)
                ->where('cards.data.2', fn ($row) => $row['id'] === $report->id
                    && $row['type'] === 'report'
                    && $row['title'] === 'Informe de ventas diario'
                    && $row['visibility'] === 'company'
                    && $row['company_ids'] === [$this->segurex->id]
                    && $row['area'] === ['id' => $this->ventas->id, 'name' => 'Ventas', 'company_id' => $this->segurex->id]
                    && $row['owner']['id'] === $this->paola->id
                    && $row['review_at'] === '2027-01-15'
                    && $row['review_status'] === 'ok'
                    && is_string($row['updated_at'])
                    && count($row) === 11)
                ->where('cards.data.1.key', $system->key)
                ->where('cards.data.1.company_ids', [$this->segurex->id, $this->derivados->id])
                ->where('cards.data.1.review_status', 'overdue')
                ->where('cards.data.0.review_status', 'due_soon')
                ->where('area_options', [
                    ['id' => $this->ventas->id, 'name' => 'Ventas', 'company_id' => $this->segurex->id],
                    ['id' => $this->derivadosVentas->id, 'name' => 'Ventas', 'company_id' => $this->derivados->id],
                ])
                ->where('type_examples.report', ['key' => $report->key, 'type' => 'report', 'title' => 'Informe de ventas diario', 'visibility' => 'company'])
                ->where('type_examples.system.key', $system->key)
                ->where('type_examples.data_flow', null)
                ->where('type_examples.procedure', null)
            );
    }

    public function test_filters_combine_and_paginate_eight_per_page(): void
    {
        foreach (range(1, 9) as $n) {
            $this->card(['type' => 'procedure', 'title' => "Procedimiento {$n}", 'fields' => ['steps' => ['Paso'], 'who_can' => null], 'updated_at' => now()->subMinutes($n)]);
        }
        $overdue = $this->card(['type' => 'report', 'title' => 'Informe viejo', 'review_at' => '2026-01-01', 'visibility' => 'it']);
        $this->card(['type' => 'report', 'title' => 'Informe Derivados'], [$this->derivados], $this->derivadosVentas);

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.index', ['tipo' => 'procedure']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', 'procedure')
                ->has('cards.data', 8)
                ->where('cards.meta.total', 9)
                ->where('cards.meta.last_page', 2)
                ->where('cards.data.0.title', 'Procedimiento 1')
                ->where('counts.total', 11)
            );

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.index', ['tipo' => 'procedure', 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('cards.data', 1)->where('cards.data.0.title', 'Procedimiento 9'));

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.index', ['vencidas' => 1, 'visibilidad' => 'it']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.overdue', true)
                ->where('filters.visibility', 'it')
                ->has('cards.data', 1)
                ->where('cards.data.0.key', $overdue->key)
            );

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.index', ['empresa' => $this->derivados->id, 'area' => $this->derivadosVentas->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.company_id', $this->derivados->id)
                ->where('filters.area_id', $this->derivadosVentas->id)
                ->has('cards.data', 1)
                ->where('cards.data.0.title', 'Informe Derivados')
            );

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.index', ['q' => 'informe viejo', 'tipo' => 'nada']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.q', 'informe viejo')
                ->where('filters.type', null)
                ->has('cards.data', 2)
                ->where('cards.data.1.title', 'Informe viejo')
            );
    }

    public function test_readers_see_only_company_cards_of_their_company_and_no_management_filters(): void
    {
        $reader = $this->reader();
        $public = $this->card(['title' => 'Conectarse a la VPN', 'type' => 'procedure', 'fields' => ['steps' => ['Abrir el cliente'], 'who_can' => null]], [$this->segurex, $this->derivados]);
        $this->card(['title' => 'Flujo solo TI', 'visibility' => 'it']);
        $this->card(['title' => 'Informe Derivados'], [$this->derivados], $this->derivadosVentas);
        $this->card(['title' => 'Informe Tercera'], [$this->third], null);

        $this->actingAs($reader)
            ->get(route('assistant.knowledge.index', ['visibilidad' => 'it', 'vencidas' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.visibility', null)
                ->where('filters.overdue', false)
                ->has('cards.data', 1)
                ->where('cards.data.0.key', $public->key)
                ->where('counts.total', 1)
                ->where('counts.by_visibility', ['it' => 0, 'company' => 1])
                ->where('unanswered_count', 0)
            );

        $derivadosReader = $this->reader($this->derivados, 'Lector Derivados');
        $this->actingAs($derivadosReader)
            ->get(route('assistant.knowledge.index'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.total', 2));

        $this->actingAs($this->paola)->get(route('assistant.knowledge.index'))->assertForbidden();
    }

    public function test_jhon_with_a_company_selected_sees_only_that_company(): void
    {
        $this->card(['title' => 'Segurex'], [$this->segurex]);
        $this->card(['title' => 'Ambas'], [$this->segurex, $this->derivados]);
        $this->card(['title' => 'Derivados'], [$this->derivados], $this->derivadosVentas);

        $this->actingAs($this->jhon)
            ->withSession(['current_company_id' => $this->derivados->id])
            ->get(route('assistant.knowledge.index'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.total', 2));
    }

    public function test_show_returns_the_full_card(): void
    {
        $product = $this->product(['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP', 'current_version' => 'v2.4.0']);
        $related = $this->card(['type' => 'data_flow', 'title' => 'Pedidos a SAP', 'visibility' => 'it', 'fields' => ['origin_destination' => 'Toma Pedidos a SAP B1']]);
        $this->actingAs($this->jhon)->post(route('assistant.knowledge.store'), $this->payload());
        $card = KnowledgeItem::query()->latest('id')->firstOrFail();
        $this->actingAs($this->jhon)->put(route('assistant.knowledge.update', $card), $this->payload([
            'related_card_keys' => [$related->key],
            'related_product_slugs' => [$product->slug],
        ]))->assertRedirect(route('assistant.knowledge.show', $card));
        $this->actingAs($this->jhon)->post(route('assistant.knowledge.review', $card));

        $this->assertSame($this->withShared(self::SHOW_PROPS), $this->propKeys(route('assistant.knowledge.show', $card)));

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.show', $card))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Knowledge/Show')
                ->where('today', self::TODAY)
                ->has('now')
                ->where('card.key', $card->key)
                ->where('card.type', 'report')
                ->where('card.fields', [
                    'shows' => 'Los pedidos del día anterior, tomados de SAP B1',
                    'source_system' => 'Toma Pedidos SAP',
                    'recipients' => 'Gerencia y coordinadores de ventas',
                    'schedule' => 'Todos los días a las 6:00 a. m.',
                    'format' => null,
                ])
                ->where('card.description', 'Lo envía Toma Pedidos SAP a las 6:00 a. m.')
                ->where('card.related_cards', [['key' => $related->key, 'type' => 'data_flow', 'title' => 'Pedidos a SAP', 'visibility' => 'it']])
                ->where('card.related_products', [['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP', 'version' => 'v2.4.0']])
                ->where('card.review_at', '2027-04-07')
                ->where('card.last_reviewed_by.id', $this->jhon->id)
                ->has('card.last_reviewed_at')
                ->where('card.usage_count_month', 0)
                ->where('card.last_cited_at', null)
                ->where('card.last_cited_by', null)
                ->has('card.recent_changes', 3)
                ->where('card.recent_changes.0.what', 'la marcó como revisada')
                ->where('card.recent_changes.0.who.id', $this->jhon->id)
                ->where('card.recent_changes.1.what', 'cambió relacionadas')
                ->where('card.recent_changes.2.what', 'creó la ficha')
                ->where('card', fn ($full) => count($full) === 21)
            );
    }

    public function test_show_hides_cards_and_related_cards_the_person_cannot_see(): void
    {
        $reader = $this->reader();
        $hidden = $this->card(['title' => 'Solo TI', 'visibility' => 'it']);
        $public = $this->card(['title' => 'Pública']);
        $other = $this->card(['title' => 'De Derivados'], [$this->derivados], $this->derivadosVentas);
        $this->actingAs($this->jhon)->put(route('assistant.knowledge.update', $public), $this->payload(['title' => 'Pública', 'related_card_keys' => [$hidden->key]]));

        $this->actingAs($reader)->get(route('assistant.knowledge.show', $hidden))->assertNotFound();
        $this->actingAs($reader)->get(route('assistant.knowledge.show', $other))->assertNotFound();
        $this->actingAs($reader)
            ->get(route('assistant.knowledge.show', $public))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('card.related_cards', [])
                ->where('card.recent_changes', [])
            );
        $this->actingAs($this->paola)->get(route('assistant.knowledge.show', $public))->assertForbidden();
    }

    public function test_create_and_edit_return_the_form_props(): void
    {
        $product = $this->product(['slug' => 'visualizador-cartera', 'name' => 'Visualizador de cartera']);
        $card = $this->card(['title' => 'Informe de ventas diario']);
        $other = $this->card(['type' => 'glossary', 'title' => 'Pedido bloqueado', 'fields' => ['term' => 'Pedido bloqueado', 'definition' => 'Retenido']]);

        $this->assertSame($this->withShared([...self::FORM_PROPS, 'prefill']), $this->propKeys(route('assistant.knowledge.create')));
        $this->assertSame($this->withShared([...self::FORM_PROPS, 'card']), $this->propKeys(route('assistant.knowledge.edit', $card)));

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.create', ['pregunta' => 4]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Knowledge/Create')
                ->where('prefill', null)
                ->where('type_counts', ['system' => 0, 'report' => 1, 'data_flow' => 0, 'procedure' => 0, 'glossary' => 1])
                ->where('area_options', fn ($areas) => collect($areas)->pluck('id')->sort()->values()->all() === collect([$this->ventas->id, $this->compras->id, $this->derivadosVentas->id])->sort()->values()->all())
                ->where('owner_options', fn ($owners) => collect($owners)->pluck('id')->contains($this->paola->id))
                ->where('knowledge_managers', fn ($managers) => collect($managers)->pluck('id')->sort()->values()->all() === collect([$this->jhon->id, $this->superAdmin->id])->sort()->values()->all())
                ->where('related_options', fn ($options) => collect($options)->contains(fn ($o) => $o['kind'] === 'product' && $o['product']['slug'] === $product->slug)
                    && collect($options)->where('kind', 'card')->count() === 2)
            );

        $this->actingAs($this->jhon)
            ->get(route('assistant.knowledge.edit', $card))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Knowledge/Edit')
                ->where('card.key', $card->key)
                ->where('related_options', fn ($options) => collect($options)->where('kind', 'card')->pluck('card.key')->all() === [$other->key])
            );
    }

    public function test_management_pages_and_writes_need_the_manage_permission(): void
    {
        $reader = $this->reader();
        $card = $this->card();

        $this->actingAs($reader)->get(route('assistant.knowledge.create'))->assertForbidden();
        $this->actingAs($reader)->get(route('assistant.knowledge.edit', $card))->assertForbidden();
        $this->actingAs($reader)->post(route('assistant.knowledge.store'), $this->payload())->assertForbidden();
        $this->actingAs($reader)->put(route('assistant.knowledge.update', $card), $this->payload())->assertForbidden();
        $this->actingAs($reader)->delete(route('assistant.knowledge.destroy', $card))->assertForbidden();
        $this->actingAs($reader)->post(route('assistant.knowledge.review', $card))->assertForbidden();

        $this->assertSame(1, KnowledgeItem::query()->count());
    }

    public function test_cards_of_other_company_contexts_are_not_found_for_managers_without_view_all(): void
    {
        $manager = $this->user($this->segurex, 'Usuario final', 'Gestor Segurex');
        $manager->givePermissionTo(['assistant.use', 'assistant.knowledge.manage']);
        $card = $this->card([], [$this->derivados], $this->derivadosVentas);

        $this->actingAs($manager)->get(route('assistant.knowledge.edit', $card))->assertNotFound();
        $this->actingAs($manager)->get(route('assistant.knowledge.show', $card))->assertNotFound();
    }

    public function test_assistant_layout_tab_routes_exist(): void
    {
        foreach (['assistant.index', 'assistant.knowledge.index', 'assistant.import.create', 'assistant.drafts.index', 'assistant.unanswered.index'] as $name) {
            $this->assertTrue(app('router')->has($name), $name);
        }

        $this->actingAs($this->jhon)->get(route('assistant.import.create'))->assertRedirect(route('assistant.knowledge.index'));
        $this->actingAs($this->reader())->get(route('assistant.drafts.index'))->assertForbidden();
    }
}
