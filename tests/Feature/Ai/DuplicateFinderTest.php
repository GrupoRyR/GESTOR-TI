<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\DuplicateFinder;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;

class DuplicateFinderTest extends AiTestCase
{
    private function finder(): DuplicateFinder
    {
        return app(DuplicateFinder::class);
    }

    public function test_keywords_drop_spanish_stopwords_and_accents(): void
    {
        $this->assertSame(
            ['alerta', 'pedidos', 'bloqueados', 'cartera'],
            DuplicateFinder::keywords('Una alerta de los pedidos BLOQUEADOS por cartera, para que'),
        );
        $this->assertSame(['facturacion', 'electronica'], DuplicateFinder::keywords('Facturación electrónica'));
    }

    public function test_it_finds_similar_open_requests_ranked_by_matches(): void
    {
        $best = $this->request(['title' => 'Alerta de pedidos bloqueados por cartera', 'problem' => 'El asesor no sabe cuándo un pedido queda bloqueado.']);
        $partial = $this->request(['title' => 'Reporte de cartera vencida', 'problem' => 'Necesitamos ver la cartera por edades.']);
        $this->request(['title' => 'Subir fotos al Portafolio', 'problem' => 'Diseño sube fotos una por una.']);

        $found = $this->finder()->similar('Avisar al asesor cuando el pedido quede bloqueado por cartera', $this->segurex->id);

        $this->assertSame([$best->key, $partial->key], $found->pluck('key')->all());
    }

    public function test_accents_do_not_hide_a_duplicate(): void
    {
        $existing = $this->request(['title' => 'Conciliación bancaria automática', 'problem' => 'Tesorería concilia a mano.']);

        $found = $this->finder()->similar('conciliacion bancaria desde SAP', $this->segurex->id);

        $this->assertSame([$existing->key], $found->pluck('key')->all());
    }

    public function test_rejected_and_closed_requests_are_not_offered(): void
    {
        $this->request(['title' => 'Conciliación bancaria', 'status' => RequestStatus::Rejected]);
        $this->request(['title' => 'Conciliación bancaria', 'status' => RequestStatus::Closed]);

        $this->assertCount(0, $this->finder()->similar('conciliación bancaria', $this->segurex->id));
    }

    public function test_it_filters_by_company_and_excludes_a_request(): void
    {
        $own = $this->request(['title' => 'Conciliación bancaria']);
        $other = $this->request(['title' => 'Conciliación bancaria', 'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);

        $this->assertSame([$own->key], $this->finder()->similar('conciliación bancaria', $this->segurex->id)->pluck('key')->all());
        $this->assertCount(2, $this->finder()->similar('conciliación bancaria', null));
        $this->assertSame([$other->key], $this->finder()->similar('conciliación bancaria', null, 5, $own->id)->pluck('key')->all());
    }

    public function test_it_respects_the_limit_and_ignores_empty_text(): void
    {
        AiRequest::factory()->count(4)->create([
            'company_id' => $this->segurex->id,
            'area_id' => $this->ventas->id,
            'title' => 'Conciliación bancaria',
        ]);

        $this->assertCount(2, $this->finder()->similar('conciliación bancaria', $this->segurex->id, 2));
        $this->assertCount(0, $this->finder()->similar('de la por', $this->segurex->id));
    }
}
