<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\KnowledgeFilters;
use App\Domain\Assistant\KnowledgeSearch;
use App\Domain\Assistant\Synonyms;

/**
 * App\Domain\Assistant\KnowledgeSearch: the backend of the assistant's
 * `buscar_conocimiento` tool (keyword fallback on SQLite).
 */
class KnowledgeSearchServiceTest extends AssistantTestCase
{
    private function search(): KnowledgeSearch
    {
        return app(KnowledgeSearch::class);
    }

    public function test_it_ranks_title_matches_first_and_highlights_them(): void
    {
        $title = $this->card(['title' => 'Informe de cartera por asesor', 'fields' => ['shows' => 'Saldos']]);
        $body = $this->card(['title' => 'Informe de ventas', 'fields' => ['shows' => 'Ventas y algo de cartera']]);
        $this->card(['title' => 'Conectarse a la VPN', 'type' => 'procedure', 'fields' => ['steps' => ['Abrir el cliente']]]);

        $hits = $this->search()->search($this->jhon, 'cartera');

        $this->assertSame([$title->key, $body->key], array_map(fn (array $hit) => $hit['item']->key, $hits));
        $this->assertGreaterThan($hits[1]['score'], $hits[0]['score']);
        $this->assertSame(['text' => 'Informe de cartera por asesor', 'highlights' => [[11, 18]]], $hits[0]['title']);
        $this->assertSame([], $hits[1]['title']['highlights']);
        $this->assertStringContainsString('cartera', $hits[1]['snippet']['text']);
        $this->assertNotEmpty($hits[1]['snippet']['highlights']);
    }

    public function test_accents_and_case_are_ignored(): void
    {
        $card = $this->card(['title' => 'Facturación electrónica', 'fields' => ['shows' => 'Facturas']]);

        $this->assertSame([$card->key], array_map(fn (array $hit) => $hit['item']->key, $this->search()->search($this->jhon, 'FACTURACION electronica')));
    }

    public function test_glossary_synonyms_expand_the_query(): void
    {
        $this->card([
            'type' => 'glossary',
            'title' => 'Pedido bloqueado por cartera',
            'fields' => ['term' => 'Pedido bloqueado por cartera', 'definition' => 'SAP lo retiene por cupo', 'also_called' => 'pedido retenido, pedido frenado'],
        ]);
        $procedure = $this->card([
            'type' => 'procedure',
            'title' => 'Liberar un pedido bloqueado',
            'fields' => ['steps' => ['Pedir a cartera que revise el cupo'], 'who_can' => null],
        ]);

        $groups = app(Synonyms::class)->groups($this->jhon);
        $this->assertContains(['pedido bloqueado por cartera', 'pedido retenido', 'pedido frenado'], $groups);
        $this->assertSame(['bloqueado', 'cartera', 'retenido'], app(Synonyms::class)->expand(['pedido', 'frenado'], $groups));
        $this->assertSame([], app(Synonyms::class)->expand(['frenado'], $groups), 'Every word of a phrase must be asked.');

        $keys = array_map(fn (array $hit) => $hit['item']->key, $this->search()->search($this->jhon, 'pedido frenado'));
        $this->assertContains($procedure->key, $keys);
    }

    public function test_placeholders_are_not_synonyms(): void
    {
        $this->card(['type' => 'glossary', 'title' => 'Cupo', 'fields' => ['term' => 'Cupo', 'definition' => 'Límite', 'also_called' => '[SINÓNIMOS]']]);

        $this->assertSame([['cupo']], app(Synonyms::class)->groups($this->jhon));
    }

    public function test_visibility_applies_to_hits_and_synonyms(): void
    {
        $reader = $this->reader();
        $public = $this->card(['title' => 'Informe de cartera']);
        $this->card(['title' => 'Cartera solo TI', 'visibility' => 'it']);
        $this->card(['title' => 'Cartera Derivados'], [$this->derivados], $this->derivadosVentas);
        $this->card(['type' => 'glossary', 'title' => 'Secreto', 'visibility' => 'it', 'fields' => ['term' => 'cartera', 'definition' => 'x', 'also_called' => 'cobranza']]);

        $this->assertSame([$public->key], array_map(fn (array $hit) => $hit['item']->key, $this->search()->search($reader, 'cartera')));
        $this->assertSame([], app(Synonyms::class)->groups($reader));
        $this->assertCount(4, $this->search()->search($this->jhon, 'cartera'));
        $this->assertSame([], $this->search()->search($this->paola, 'cartera'), 'Without assistant permissions nothing is found.');
    }

    public function test_filters_limit_and_key_lookup(): void
    {
        $report = $this->card(['title' => 'Cartera semanal', 'review_at' => '2026-01-01']);
        $this->card(['title' => 'Cartera diaria', 'type' => 'procedure', 'fields' => ['steps' => ['Paso']]]);

        $filters = new KnowledgeFilters(type: 'report', overdue: true);
        $this->assertSame([$report->key], array_map(fn (array $hit) => $hit['item']->key, $this->search()->search($this->jhon, 'cartera', $filters)));
        $this->assertCount(1, $this->search()->search($this->jhon, 'cartera', limit: 1));
        $this->assertSame([$report->key], array_map(fn (array $hit) => $hit['item']->key, $this->search()->search($this->jhon, strtolower($report->key))));
        $this->assertSame([], $this->search()->search($this->jhon, 'de la por'));
    }
}
