<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\ProductStatus;
use App\Enums\Ai\ProductVersionSource;
use App\Enums\Ai\RequestStatus;
use App\Models\AiInteraction;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\MonitoredApp;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class AiProductTest extends AiTestCase
{
    private function product(array $attributes = [], array $companies = []): AiProduct
    {
        $product = AiProduct::factory()->create($attributes);

        foreach ($companies === [] ? [$this->segurex] : $companies as $i => $company) {
            $product->companies()->attach($company->id, ['is_primary' => $i === 0]);
        }

        return $product;
    }

    public function test_a_product_stores_its_catalog_fields_and_casts(): void
    {
        $product = $this->product([
            'slug' => 'toma-pedidos',
            'name' => 'Toma Pedidos SAP',
            'status' => ProductStatus::Pilot,
            'monthly_cost' => 380000,
            'owner_user_id' => $this->jhon->id,
        ])->fresh();

        $this->assertSame(ProductStatus::Pilot, $product->status);
        $this->assertSame('Piloto', $product->status->label());
        $this->assertSame('380000.00', (string) $product->monthly_cost);
        $this->assertSame('COP', $product->monthly_cost_currency);
        $this->assertTrue($product->owner->is($this->jhon));
        $this->assertSame('Jhon Castro', $product->ownerLabel());
        $this->assertSame('toma-pedidos', $product->getRouteKey());
    }

    public function test_owner_label_falls_back_to_free_text_and_placeholder(): void
    {
        $this->assertSame('Gerencia de cartera', $this->product(['owner_name' => 'Gerencia de cartera'])->ownerLabel());
        $this->assertNull($this->product()->ownerLabel());
    }

    public function test_the_slug_is_unique_even_for_soft_deleted_products(): void
    {
        $this->product(['slug' => 'portafolio'])->delete();

        $this->expectException(QueryException::class);
        AiProduct::factory()->create(['slug' => 'portafolio']);
    }

    public function test_companies_and_areas_pivots_mark_the_primary_one(): void
    {
        $product = $this->product([], [$this->derivados, $this->segurex]);
        $product->areas()->attach($this->ventas->id, ['is_primary' => true]);
        $product->areas()->attach($this->compras->id, ['is_primary' => false]);

        $this->assertTrue($product->primaryCompany()->is($this->derivados));
        $this->assertEqualsCanonicalizing([$this->derivados->id, $this->segurex->id], $product->companies->pluck('id')->all());
        $this->assertTrue($product->primaryArea()->is($this->ventas));
        $this->assertCount(2, $product->areas);
    }

    public function test_visible_to_filters_by_the_user_company_context_through_the_pivot(): void
    {
        $segurexOnly = $this->product(['slug' => 'visualizador-cartera']);
        $derivadosOnly = $this->product(['slug' => 'portafolio'], [$this->derivados]);
        $shared = $this->product(['slug' => 'compartido'], [$this->segurex, $this->derivados]);
        $thirdOnly = $this->product(['slug' => 'tercera-app'], [$this->third]);

        $slugs = fn ($user) => AiProduct::query()->visibleTo($user)->orderBy('slug')->pluck('slug')->all();

        $this->assertSame(['compartido', 'visualizador-cartera'], $slugs($this->paola));
        $this->assertSame(['compartido', 'portafolio'], $slugs($this->derivadosUser));

        // companies.view_all with "Todas" sees every product; with a company selected, only that one.
        $this->assertSame(['compartido', 'portafolio', 'tercera-app', 'visualizador-cartera'], $slugs($this->superAdmin));
        app(CurrentCompany::class)->set($this->third->id);
        $this->assertSame(['tercera-app'], $slugs($this->superAdmin));

        $orphan = $this->user($this->segurex, 'Usuario final', 'Sin empresa');
        $orphan->forceFill(['company_id' => null])->save();
        $this->assertSame([], $slugs($orphan));

        $this->assertNotNull($segurexOnly);
        $this->assertNotNull($derivadosOnly);
        $this->assertNotNull($shared);
        $this->assertNotNull($thirdOnly);
    }

    public function test_requests_point_to_a_product_and_lose_it_when_the_product_row_is_deleted(): void
    {
        $product = $this->product();
        $request = $this->request(['product_id' => $product->id, 'product_version' => 'v2.4.0']);

        $this->assertTrue($request->product->is($product));
        $this->assertTrue($product->requests()->first()->is($request));

        $product->forceDelete();

        $this->assertNull($request->fresh()->product_id);
    }

    public function test_a_request_cannot_point_to_a_missing_product(): void
    {
        $this->expectException(QueryException::class);

        $this->request(['product_id' => 999]);
    }

    public function test_soft_deleted_products_still_resolve_from_their_requests(): void
    {
        $product = $this->product();
        $request = $this->request(['product_id' => $product->id]);
        $product->delete();

        $this->assertTrue($request->fresh()->product->is($product));
    }

    public function test_open_requests_count_skips_finished_and_delivered_requests(): void
    {
        $product = $this->product();
        $other = $this->product();

        $this->request(['product_id' => $product->id]);
        $this->request(['product_id' => $product->id, 'status' => RequestStatus::InDevelopment]);
        $this->request(['product_id' => $product->id, 'status' => RequestStatus::Backlog, 'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);
        $this->request(['product_id' => $product->id, 'status' => RequestStatus::InProduction]);
        $this->request(['product_id' => $product->id, 'status' => RequestStatus::Rejected]);
        $this->request(['product_id' => $product->id, 'status' => RequestStatus::Closed]);
        $this->request(['product_id' => $other->id]);

        $this->assertSame(3, $product->openRequestsCount());
    }

    public function test_versions_link_the_requests_they_include(): void
    {
        $product = $this->product(['current_version' => 'v3.1.0']);
        $a = $this->request(['product_id' => $product->id]);
        $b = $this->request(['product_id' => $product->id]);

        $old = AiProductVersion::factory()->for($product, 'product')->create(['version' => 'v3.0.0', 'released_at' => '2026-08-18']);
        $current = AiProductVersion::factory()->for($product, 'product')->create([
            'version' => 'v3.1.0',
            'released_at' => '2026-09-22',
            'summary' => 'Totales por edad de cartera.',
            'notes' => "## Cambios\n- Totales",
            'source' => ProductVersionSource::GithubRelease,
            'release_url' => 'https://github.com/ryr/visualizador/releases/tag/v3.1.0',
        ]);
        $current->requests()->attach([$a->id, $b->id]);
        $old->requests()->attach($a->id);

        $this->assertSame(['v3.1.0', 'v3.0.0'], $product->versions()->pluck('version')->all(), 'newest first');
        $this->assertTrue($product->currentVersionRecord()->is($current));
        $this->assertTrue($current->isCurrent());
        $this->assertFalse($old->isCurrent());
        $this->assertSame(ProductVersionSource::GithubRelease, $current->fresh()->source);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $current->requests->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['v3.0.0', 'v3.1.0'], $a->productVersions->pluck('version')->all());

        $this->expectException(QueryException::class);
        AiProductVersion::factory()->for($product, 'product')->create(['version' => 'v3.1.0']);
    }

    public function test_versions_default_to_manual_source(): void
    {
        $version = AiProductVersion::factory()->for($this->product(), 'product')->create();

        $this->assertSame(ProductVersionSource::Manual, $version->fresh()->source);
    }

    public function test_health_says_monitoring_is_not_active_without_a_monitored_app_or_module(): void
    {
        $product = $this->product(['slug' => 'toma-pedidos']);

        $this->assertSame(['monitoring_active' => false, 'status' => null, 'label' => 'Salud: monitoreo aún no activo'], $product->health());

        $app = MonitoredApp::create(['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP', 'type' => MonitoredApp::TYPE_WEB, 'ai_product_id' => $product->id, 'last_status' => 'up']);
        $this->assertTrue($product->fresh()->monitoredApp->is($app));
        $this->assertTrue($app->product->is($product));

        config(['modules.monitoring.active' => false]);
        $this->assertFalse($product->fresh()->health()['monitoring_active']);

        config(['modules.monitoring.active' => true]);
        $this->assertSame(['monitoring_active' => true, 'status' => 'up', 'label' => 'Salud: up'], $product->fresh()->health());
    }

    public function test_monitored_apps_lose_the_link_when_the_product_row_is_deleted(): void
    {
        $product = $this->product();
        $app = MonitoredApp::create(['slug' => 'x', 'name' => 'X', 'type' => MonitoredApp::TYPE_WEB, 'ai_product_id' => $product->id]);

        $product->forceDelete();

        $this->assertNull($app->fresh()->ai_product_id);
    }

    public function test_monthly_ai_spend_sums_only_this_month_and_this_product(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00'));
        $product = $this->product();
        $other = $this->product();

        $log = function (AiProduct $source, string $cost, string $at): void {
            $interaction = AiInteraction::create([
                'provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'product',
                'source_type' => $source->getMorphClass(), 'source_id' => $source->id,
                'cost' => $cost, 'status' => 'ok',
            ]);
            $interaction->forceFill(['created_at' => Carbon::parse($at)])->save();
        };

        $log($product, '1.250000', '2026-10-01 00:00:00');
        $log($product, '0.750000', '2026-10-15 11:00:00');
        $log($product, '9.000000', '2026-09-30 23:59:59');
        $log($product, '5.000000', '2026-11-01 00:00:00');
        $log($other, '3.000000', '2026-10-10 10:00:00');

        $this->assertSame(2.0, $product->monthlyAiSpend());
        $this->assertSame(0.0, $this->product()->monthlyAiSpend());
    }

    public function test_rating_and_hours_saved_are_not_available_yet(): void
    {
        $product = $this->product();

        $this->assertNull($product->averageRating());
        $this->assertNull($product->hoursSaved());
    }

    public function test_cost_is_visible_only_with_ai_costs_view(): void
    {
        $product = $this->product(['monthly_cost' => 120000]);

        $this->assertTrue($product->costVisibleTo($this->jhon));
        $this->assertTrue($product->costVisibleTo($this->edward));
        $this->assertFalse($product->costVisibleTo($this->paola));
        $this->assertFalse($product->costVisibleTo($this->ventasApprover));
    }
}
