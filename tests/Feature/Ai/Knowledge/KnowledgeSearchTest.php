<?php

namespace Tests\Feature\Ai\Knowledge;

use App\Enums\Ai\RequestStatus;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

class KnowledgeSearchTest extends KnowledgeTestCase
{
    public function test_returns_exactly_the_contract_props_and_nothing_before_typing(): void
    {
        $this->assertSame(
            $this->withShared(['query', 'results', 'type_counts', 'company_counts', 'areas', 'took_ms', 'now']),
            $this->propKeys(route('ai.search')),
        );

        $this->actingAs($this->jhon)
            ->get(route('ai.search'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Search/Index', false)
                ->where('query', ['q' => '', 'types' => ['docs', 'requests', 'products'], 'company_id' => null, 'area_id' => null])
                ->where('results', null)
                ->where('type_counts', ['docs' => 0, 'requests' => 0, 'products' => 0])
                ->where('company_counts', [])
            );
    }

    public function test_it_finds_docs_requests_and_products_with_highlights(): void
    {
        $product = $this->product(['slug' => 'visualizador-cartera', 'name' => 'Visualizador de cartera', 'current_version' => 'v3.1.0']);
        $doc = $this->doc($product, 'user_manual', 'Manual de usuario', "# Manual\n\n## Ver la cartera por asesor\nAbre Filtros, elige el asesor.\n");
        $this->doc($product, 'decisions', 'Decisiones', "Nada que ver aquí.\n");
        $request = $this->request(['title' => 'Cartera por asesor en el visualizador', 'problem' => 'No vemos la cartera de cada asesor']);
        $this->request(['title' => 'Factura electrónica', 'problem' => 'Otra cosa']);

        $this->actingAs($this->jhon)
            ->get(route('ai.search', ['q' => 'cartera asesor']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('query.q', 'cartera asesor')
                ->where('type_counts', ['docs' => 1, 'requests' => 1, 'products' => 1])
                ->has('results.docs', 1)
                ->where('results.docs.0.doc.id', $doc->id)
                ->where('results.docs.0.snippet.text', '# Manual ## Ver la cartera por asesor Abre Filtros, elige el asesor.')
                ->where('results.docs.0.snippet.highlights', [[19, 26], [31, 37], [61, 67]])
                ->has('results.requests', 1)
                ->where('results.requests.0', [
                    'request' => ['key' => $request->key, 'status' => 'received', 'company_id' => $this->segurex->id, 'area' => ['id' => $this->ventas->id, 'name' => 'Ventas']],
                    'title' => ['text' => 'Cartera por asesor en el visualizador', 'highlights' => [[0, 7], [12, 18]]],
                ])
                ->has('results.products', 1)
                ->where('results.products.0.product', ['slug' => 'visualizador-cartera', 'name' => 'Visualizador de cartera', 'company_id' => $this->segurex->id, 'state' => 'production', 'version' => 'v3.1.0'])
                ->where('results.products.0.name', ['text' => 'Visualizador de cartera', 'highlights' => [[16, 23]]])
                ->has('took_ms')
            );
    }

    public function test_type_company_and_area_filters_keep_the_counts(): void
    {
        $this->request(['title' => 'Cartera Segurex']);
        $this->request(['title' => 'Cartera Derivados', 'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'requester_id' => $this->derivadosUser->id, 'created_by_id' => $this->derivadosUser->id]);
        $this->request(['title' => 'Cartera compras', 'area_id' => $this->compras->id]);
        $this->product(['name' => 'Cartera']);

        $this->actingAs($this->jhon)
            ->get(route('ai.search', ['q' => 'cartera', 'type' => 'requests', 'company_id' => $this->segurex->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('query.types', ['requests'])
                ->where('query.company_id', $this->segurex->id)
                ->has('results.requests', 2)
                ->where('results.products', [])
                ->where('results.docs', [])
                ->where('type_counts', ['docs' => 0, 'requests' => 2, 'products' => 1])
                ->where('company_counts', [$this->segurex->id => 2, $this->derivados->id => 1, $this->third->id => 0])
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.search', ['q' => 'cartera', 'area_id' => $this->compras->id]))
            ->assertInertia(fn (Assert $page) => $page->has('results.requests', 1)->where('results.requests.0.title.text', 'Cartera compras'));
    }

    public function test_requesters_see_only_their_company_and_no_documentation(): void
    {
        $segurexProduct = $this->product(['name' => 'Cartera Segurex']);
        $this->product(['name' => 'Cartera Derivados'], $this->derivados);
        $this->product(['name' => 'Cartera Tercera'], $this->third);
        $this->doc($segurexProduct, 'user_manual', 'Manual de cartera', "Cartera\n");
        $this->doc($segurexProduct, 'architecture', 'Arquitectura de cartera', "Cartera\n");
        $this->request(['title' => 'Cartera de Paola']);
        $this->request(['title' => 'Cartera de Diego', 'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'requester_id' => $this->derivadosUser->id, 'created_by_id' => $this->derivadosUser->id]);

        $this->actingAs($this->paola)
            ->get(route('ai.search', ['q' => 'cartera']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.docs', [])
                ->has('results.requests', 1)
                ->where('results.requests.0.title.text', 'Cartera de Paola')
                ->has('results.products', 1)
                ->where('results.products.0.name.text', 'Cartera Segurex')
                ->where('type_counts', ['docs' => 0, 'requests' => 1, 'products' => 1])
                ->where('company_counts', [])
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.search', ['q' => 'cartera']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('type_counts', ['docs' => 2, 'requests' => 2, 'products' => 3])
            );

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('ai.search', ['q' => 'cartera']))->assertForbidden();
    }

    public function test_layout_search_goes_to_the_gestor_search_for_module_users(): void
    {
        $this->actingAs($this->paola)
            ->get(route('search', ['q' => 'cartera']))
            ->assertRedirect(route('ai.search', ['q' => 'cartera']));

        $this->actingAs($this->jhon)
            ->get(route('search'))
            ->assertRedirect(route('ai.search'));

        $role = Role::create(['name' => 'Solo colaboradores', 'guard_name' => 'web']);
        $role->givePermissionTo(['employees.view']);
        $viewer = $this->user($this->segurex, 'Solo colaboradores', 'Recursos Humanos');

        $this->actingAs($viewer)
            ->get(route('search', ['q' => 'castro']))
            ->assertRedirect(route('employees.index', ['q' => 'castro']));

        config(['modules.ai.active' => false]);
        $this->actingAs($this->paola)
            ->from(route('home'))
            ->get(route('search', ['q' => 'cartera']))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');
    }

    public function test_finished_requests_are_found_too(): void
    {
        $this->request(['title' => 'Cartera cerrada', 'status' => RequestStatus::Closed]);

        $this->actingAs($this->jhon)
            ->get(route('ai.search', ['q' => 'cartera']))
            ->assertInertia(fn (Assert $page) => $page->has('results.requests', 1)->where('results.requests.0.request.status', 'closed'));
    }
}
