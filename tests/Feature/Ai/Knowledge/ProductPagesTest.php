<?php

namespace Tests\Feature\Ai\Knowledge;

use App\Enums\Ai\ProductStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiHoursSavedReport;
use App\Models\AiProductIncident;
use App\Models\AiRating;
use Inertia\Testing\AssertableInertia as Assert;

class ProductPagesTest extends KnowledgeTestCase
{
    public function test_index_returns_exactly_the_contract_props(): void
    {
        $this->product();

        $this->assertSame($this->withShared(['products', 'filters', 'state_counts', 'areas']), $this->propKeys(route('ai.products.index')));

        $this->actingAs($this->paola)->get(route('ai.products.index'))->assertForbidden();
    }

    public function test_index_lists_products_with_their_shape(): void
    {
        $product = $this->product([
            'slug' => 'visualizador-cartera',
            'name' => 'Visualizador de cartera',
            'description' => 'Cartera por cliente',
            'owner_name' => 'Cartera',
            'url' => 'https://cartera.example.test',
            'repository' => 'ryr/visualizador',
            'ai_model' => 'deepseek-chat',
            'monthly_cost' => 120000,
        ]);
        $request = $this->request(['product_id' => $product->id, 'status' => RequestStatus::InProduction]);
        AiRating::create(['ai_request_id' => $request->id, 'user_id' => $this->paola->id, 'solved_need' => 4, 'ease_of_use' => 5, 'rated_at' => now()]);
        AiHoursSavedReport::create(['ai_request_id' => $request->id, 'user_id' => $this->paola->id, 'hours_per_week' => 3, 'reported_at' => now()]);
        $this->request(['product_id' => $product->id, 'status' => RequestStatus::Evaluating]);

        $this->actingAs($this->jhon)
            ->get(route('ai.products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Products/Index', false)
                ->where('filters', ['q' => null, 'company_id' => null, 'area_id' => null, 'state' => null])
                ->where('state_counts', ['production' => 1, 'pilot' => 0, 'development' => 0, 'retired' => 0])
                ->has('areas')
                ->has('products', 1)
                ->where('products.0', [
                    'slug' => 'visualizador-cartera',
                    'name' => 'Visualizador de cartera',
                    'company_id' => $this->segurex->id,
                    'description' => 'Cartera por cliente',
                    'state' => 'production',
                    'version' => 'v3.1.0',
                    'stack' => 'Next.js',
                    'host' => 'Vercel',
                    'areas' => ['Ventas'],
                    'owner' => 'Cartera',
                    'url' => 'https://cartera.example.test',
                    'repository' => 'ryr/visualizador',
                    'ai_model' => 'deepseek-chat',
                    'rating' => ['average' => 4, 'count' => 1],
                    'hours_saved_per_month' => 13,
                    'monthly_cost' => 120000,
                    'open_requests' => 1,
                    'monitoring_active' => false,
                ])
            );
    }

    public function test_money_is_null_without_costs_view(): void
    {
        $product = $this->product(['monthly_cost' => 120000]);
        $viewer = $this->itWithoutCosts();

        $this->actingAs($viewer)
            ->get(route('ai.products.index'))
            ->assertInertia(fn (Assert $page) => $page->where('products.0.monthly_cost', null));

        $this->actingAs($viewer)
            ->get(route('ai.products.show', $product))
            ->assertInertia(fn (Assert $page) => $page->where('product.monthly_cost', null)->where('cost_note', null));
    }

    public function test_index_filters_and_company_visibility_with_three_companies(): void
    {
        $this->product(['name' => 'Visualizador de cartera', 'stack' => 'Next.js']);
        $this->product(['name' => 'Toma pedidos', 'stack' => 'Laravel', 'status' => ProductStatus::Pilot]);
        $this->product(['name' => 'Portafolio Derivados'], $this->derivados);
        $this->product(['name' => 'Producto tercera'], $this->third);

        $this->actingAs($this->jhon)
            ->get(route('ai.products.index'))
            ->assertInertia(fn (Assert $page) => $page->has('products', 4));

        $this->actingAs($this->jhon)
            ->get(route('ai.products.index', ['state' => 'pilot']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products', 1)
                ->where('products.0.name', 'Toma pedidos')
                // Counts keep the other filters but ignore the state one.
                ->where('state_counts', ['production' => 3, 'pilot' => 1, 'development' => 0, 'retired' => 0])
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.products.index', ['q' => 'laravel']))
            ->assertInertia(fn (Assert $page) => $page->has('products', 1)->where('filters.q', 'laravel'));

        $this->actingAs($this->jhon)
            ->get(route('ai.products.index', ['company_id' => $this->derivados->id]))
            ->assertInertia(fn (Assert $page) => $page->has('products', 1)->where('products.0.company_id', $this->derivados->id));

        $this->actingAs($this->jhon)
            ->get(route('ai.products.index', ['area_id' => $this->ventas->id]))
            ->assertInertia(fn (Assert $page) => $page->has('products', 2));

        // A company context shows only its products and ignores `company_id`.
        $this->actingAs($this->jhon)
            ->withSession(['current_company_id' => $this->third->id])
            ->get(route('ai.products.index', ['company_id' => $this->segurex->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products', 1)
                ->where('products.0.name', 'Producto tercera')
                ->where('filters.company_id', null)
            );
    }

    public function test_show_returns_exactly_the_contract_props(): void
    {
        $product = $this->product();

        $this->assertSame(
            $this->withShared(['product', 'health', 'incidents', 'hours_note', 'cost_note', 'request_notes', 'incident_form', 'now']),
            $this->propKeys(route('ai.products.show', $product)),
        );

        $this->actingAs($this->paola)->get(route('ai.products.show', $product))->assertForbidden();
    }

    public function test_show_has_versions_requests_docs_incidents_and_notes(): void
    {
        $product = $this->product(['slug' => 'visualizador-cartera', 'name' => 'Visualizador de cartera', 'monthly_cost' => 120000, 'ai_provider' => 'DeepSeek']);
        $old = $product->versions()->create(['version' => 'v3.0.0', 'released_at' => '2026-08-01', 'summary' => 'Primera versión']);
        $current = $product->versions()->create(['version' => 'v3.1.0', 'released_at' => '2026-09-22', 'summary' => 'Filtro por zona']);
        $delivered = $this->request(['product_id' => $product->id, 'status' => RequestStatus::InProduction, 'title' => 'Filtro por zona']);
        $current->requests()->attach($delivered->id);
        $open = $this->request(['product_id' => $product->id, 'status' => RequestStatus::InDevelopment, 'title' => 'Cartera por asesor']);
        $project = $this->project(['product_id' => $product->id, 'due_date' => '2026-11-14']);
        $project->requests()->attach($open->id);
        $waiting = $this->request(['product_id' => $product->id, 'status' => RequestStatus::Received, 'requester_id' => $this->juan->id]);
        AiHoursSavedReport::create(['ai_request_id' => $delivered->id, 'user_id' => $this->paola->id, 'hours_per_week' => 4, 'reported_at' => now()]);
        $doc = $this->doc($product, 'user_manual', 'Manual de usuario');
        $other = $this->product();
        $this->doc($other, 'user_manual', 'Manual de otro');
        AiProductIncident::create(['ai_product_id' => $product->id, 'title' => 'No cargaba', 'started_at' => now()->subDays(2), 'resolved_at' => now()->subDay(), 'reported_by_id' => $this->jhon->id]);

        $this->actingAs($this->jhon)
            ->get(route('ai.products.show', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Products/Show', false)
                ->where('health', ['enabled' => false])
                ->where('product.slug', 'visualizador-cartera')
                ->where('product.monthly_cost', 120000)
                ->where('product.open_requests', 2)
                ->where('product.next_version', null)
                ->where('product.requests_from_button_this_month', 0)
                ->where('product.versions', [
                    ['tag' => 'v3.1.0', 'date' => '2026-09-22', 'summary' => 'Filtro por zona', 'request_keys' => [['key' => $delivered->key, 'title' => 'Filtro por zona']], 'current' => true],
                    ['tag' => 'v3.0.0', 'date' => '2026-08-01', 'summary' => 'Primera versión', 'request_keys' => [], 'current' => false],
                ])
                ->has('product.requests', 2)
                ->where('product.requests.0.key', $waiting->key)
                ->where('product.requests.1.key', $open->key)
                ->has('product.docs', 1)
                ->where('product.docs.0.id', $doc->id)
                ->where('product.docs.0.owner', ['kind' => 'product', 'key' => 'visualizador-cartera', 'name' => 'Visualizador de cartera'])
                ->has('incidents', 1)
                ->where('incidents.0.title', 'No cargaba')
                ->where('incidents.0.reported_by.name', 'Jhon Castro')
                ->where('hours_note', '4 h a la semana, según 1 persona que la usa')
                ->where('cost_note', 'Hosting en Vercel y consumo de IA (DeepSeek)')
                ->where("request_notes.{$open->key}", "Proyecto {$project->key} · entrega proyectada 14 nov")
                ->where("request_notes.{$waiting->key}", 'Juan Pérez · Recibida')
                ->has('now')
            );

        $this->assertNotNull($old);
    }

    public function test_show_of_a_product_without_data(): void
    {
        $product = $this->product(['hosting' => null, 'monthly_cost' => null]);

        $this->actingAs($this->jhon)
            ->get(route('ai.products.show', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('incidents', [])
                ->where('hours_note', null)
                ->where('cost_note', null)
                ->where('request_notes', [])
                ->where('product.rating', null)
                ->where('product.hours_saved_per_month', null)
                ->where('product.monthly_cost', null)
                ->where('product.versions', [])
                ->where('product.docs', [])
            );
    }
}
