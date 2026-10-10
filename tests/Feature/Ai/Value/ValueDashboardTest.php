<?php

namespace Tests\Feature\Ai\Value;

use App\Enums\Ai\ProductStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiHoursSavedReport;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiProjectCost;
use App\Models\AiRating;
use App\Models\AiRequest;
use App\Models\AiRequestStatusChange;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AiRatingRequested;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Ai\Triage\TriageTestCase;

/**
 * Value dashboard (PROMPT 6.4.5 + 6.4.11, `Ai/Value/Index`). Clock: 7 Oct
 * 2026 (Bogotá); the default period is the last 6 complete months
 * (April–September 2026).
 *
 * Data: Ventas ($ 45.000/h) uses "Visualizador de cartera" (GIA-1, in
 * production since 10 Jun, 6 h/week reported); Compras ($ 30.000/h) got
 * GIA-2 without product (since 5 Sep, 3 h/week). "Cámaras" is still in
 * development with a project that already cost money.
 */
class ValueDashboardTest extends TriageTestCase
{
    private AiProduct $visualizador;

    private AiRequest $first;

    private AiRequest $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'America/Bogota'));

        $this->ventas->forceFill(['hour_value' => 45_000])->save();
        $this->compras->forceFill(['hour_value' => 30_000])->save();

        $this->visualizador = AiProduct::factory()->create(['slug' => 'visualizador-cartera', 'name' => 'Visualizador de cartera', 'monthly_cost' => 100_000]);
        $this->visualizador->companies()->attach($this->segurex->id, ['is_primary' => true]);
        $this->visualizador->areas()->attach($this->ventas->id, ['is_primary' => true]);

        $camaras = AiProduct::factory()->status(ProductStatus::Development)->create(['slug' => 'camaras', 'name' => 'Cámaras', 'monthly_cost' => 50_000]);
        $camaras->companies()->attach($this->segurex->id, ['is_primary' => true]);

        $this->first = $this->delivered(['area_id' => $this->ventas->id, 'product_id' => $this->visualizador->id, 'hours_per_week' => 4, 'people_count' => 2], '2026-05-01', '2026-06-10');
        $this->report($this->first, 6);
        $this->rateDelivery($this->first, 5, '2026-06-20');

        $this->second = $this->delivered(['area_id' => $this->compras->id, 'hours_per_week' => 2, 'people_count' => 1], '2026-07-01', '2026-09-05');
        $this->report($this->second, 3);
        $this->rateDelivery($this->second, 4, '2026-09-10');

        $this->request(['status' => RequestStatus::Backlog]);
        $this->request(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'requester_id' => $this->derivadosUser->id, 'created_by_id' => $this->derivadosUser->id]);

        $project = AiProject::factory()->create(['company_id' => $this->segurex->id, 'area_id' => $this->ventas->id, 'product_id' => $this->visualizador->id, 'status' => RequestStatus::Closed]);
        $this->cost($project, 10_700_000);
        $camarasProject = AiProject::factory()->create(['company_id' => $this->segurex->id, 'area_id' => $this->ventas->id, 'product_id' => $camaras->id, 'status' => RequestStatus::InDevelopment]);
        $this->cost($camarasProject, 2_000_000);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function delivered(array $attributes, string $created, string $production): AiRequest
    {
        $request = $this->request(['status' => RequestStatus::InProduction, ...$attributes]);
        $request->forceFill(['created_at' => CarbonImmutable::parse($created.' 09:00', 'America/Bogota')])->save();
        $change = AiRequestStatusChange::create([
            'ai_request_id' => $request->id,
            'from_status' => RequestStatus::InTesting,
            'to_status' => RequestStatus::InProduction,
            'user_id' => $this->jhon->id,
        ]);
        $change->forceFill(['created_at' => CarbonImmutable::parse($production.' 09:00', 'America/Bogota')])->save();

        return $request;
    }

    private function report(AiRequest $request, float $hoursPerWeek): void
    {
        AiHoursSavedReport::create(['ai_request_id' => $request->id, 'user_id' => $request->requester_id, 'hours_per_week' => $hoursPerWeek, 'reported_at' => now()]);
    }

    private function rateDelivery(AiRequest $request, int $score, string $at): void
    {
        AiRating::create(['ai_request_id' => $request->id, 'user_id' => $request->requester_id, 'solved_need' => $score, 'ease_of_use' => 4, 'rated_at' => CarbonImmutable::parse($at.' 10:00', 'America/Bogota')]);
    }

    private function cost(AiProject $project, int $amount): void
    {
        AiProjectCost::create(['ai_project_id' => $project->id, 'type' => 'external_provider', 'concept' => 'Desarrollo', 'recurrence' => 'once', 'amount' => $amount, 'date' => '2026-05-15']);
    }

    private function manager(): User
    {
        $role = Role::create(['name' => 'Gerencia', 'guard_name' => 'web']);
        $role->givePermissionTo(['ai.manage', 'companies.view_all']);

        return tap(User::factory()->create(['name' => 'Gerente General']))->assignRole('Gerencia');
    }

    public function test_the_dashboard_sends_the_exact_props_of_its_page(): void
    {
        $this->assertSame(
            $this->withShared(['metrics', 'filters', 'areas', 'products', 'area_notes', 'roi_note', 'awaiting_rating', 'cost_note', 'data_note', 'now']),
            $this->propKeys(route('ai.value.index')),
        );

        $this->actingAs($this->jhon)
            ->get(route('ai.value.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Value/Index')
                ->where('filters', ['period' => '6m', 'company_id' => null, 'area_id' => null])
                ->where('metrics.period', ['from' => '2026-04-01', 'to' => '2026-09-30', 'label' => 'Últimos 6 meses'])
                ->where('metrics.updated_at', now()->toIso8601String())
                // 6 h/week × 52/12 = 26 h (Ventas) + 3 h/week = 13 h (Compras).
                ->where('metrics.hours_saved_per_month', ['current' => 39, 'delta' => 13, 'delta_label' => 'frente a agosto'])
                ->where('metrics.satisfaction', ['average' => 4.5, 'ratings' => 2, 'apps' => 2])
                // 40 and 66 days from request to production; nothing the semester before.
                ->where('metrics.lead_time_days', ['current' => 53, 'delta' => 0])
                // Only products in production or pilot.
                ->where('metrics.monthly_cost', 100_000)
                ->where('metrics.hours_series', [
                    ['month' => '2026-04', 'value' => 0],
                    ['month' => '2026-05', 'value' => 0],
                    ['month' => '2026-06', 'value' => 26],
                    ['month' => '2026-07', 'value' => 26],
                    ['month' => '2026-08', 'value' => 26],
                    ['month' => '2026-09', 'value' => 39],
                ])
                ->where('metrics.hours_by_area', [['area' => 'Ventas', 'hours' => 26], ['area' => 'Compras', 'hours' => 13]])
                ->where('metrics.requests_by_status', [
                    ['status' => 'in_production', 'count' => 2],
                    ['status' => 'received', 'count' => 1],
                    ['status' => 'backlog', 'count' => 1],
                ])
                // 26 h × $ 45.000 − $ 100.000 a month pays $ 10.700.000 back in 10 months.
                ->where('metrics.roi', [[
                    'product' => 'Visualizador de cartera',
                    'hours_per_month' => 26,
                    'total_cost' => 10_700_000,
                    'saving_per_month' => 1_070_000,
                    'payback_months' => 10,
                ]])
                // ($ 1.170.000 + $ 390.000) / 39 h.
                ->where('metrics.hour_value', 40_000)
                ->has('areas', 3)
                ->where('products', [[
                    'slug' => 'visualizador-cartera',
                    'name' => 'Visualizador de cartera',
                    'company_id' => $this->segurex->id,
                    'hours_per_month' => 26,
                    'payback_months' => 10,
                ]])
                ->where('area_notes', [
                    'Ventas ahorra más: 26 h al mes, sobre todo con Visualizador de cartera.',
                    'Lo reportado por quienes las usan (39 h al mes) frente a lo estimado al pedir (43,3 h al mes).',
                ])
                ->where('roi_note', 'Cámaras no aparece: aún no está en producción.')
                ->where('awaiting_rating', null)
                ->where('cost_note', 'Hosting, bases de datos y dominios de 1 producto')
                ->where('data_note', null)
                ->where('now', now()->toIso8601String())
            );
    }

    public function test_money_is_null_without_costs_permission(): void
    {
        $this->actingAs($this->manager())
            ->get(route('ai.value.index'))
            ->assertOk()
            ->assertDontSee('10700000')
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.monthly_cost', null)
                ->where('metrics.roi', null)
                ->where('metrics.hour_value', null)
                ->where('metrics.hours_saved_per_month.current', 39)
                ->where('products.0.payback_months', null)
                ->where('roi_note', null)
                ->where('cost_note', null)
            );
    }

    public function test_filters_by_area_company_and_period(): void
    {
        $this->actingAs($this->jhon)
            ->get(route('ai.value.index', ['area_id' => $this->compras->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.area_id', $this->compras->id)
                ->where('metrics.hours_saved_per_month.current', 13)
                ->where('metrics.hours_by_area', [['area' => 'Compras', 'hours' => 13]])
                ->where('metrics.hour_value', 30_000)
                ->where('metrics.roi', [])
                ->where('products', [])
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.value.index', ['period' => '3m']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', '3m')
                ->where('metrics.period', ['from' => '2026-07-01', 'to' => '2026-09-30', 'label' => 'Últimos 3 meses'])
                ->has('metrics.hours_series', 3)
                ->where('metrics.satisfaction', ['average' => 4, 'ratings' => 1, 'apps' => 1])
                // GIA-1 reached production in June: the quarter before.
                ->where('metrics.lead_time_days', ['current' => 66, 'delta' => 26])
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.value.index', ['period' => 'nope', 'area_id' => 'x']))
            ->assertInertia(fn (Assert $page) => $page->where('filters', ['period' => '6m', 'company_id' => null, 'area_id' => null]));
    }

    public function test_a_company_without_ratings_shows_the_empty_state(): void
    {
        $waiting = $this->delivered([
            'company_id' => $this->derivados->id,
            'area_id' => $this->derivadosVentas->id,
            'requester_id' => $this->derivadosUser->id,
            'created_by_id' => $this->derivadosUser->id,
            'title' => 'Buscador por referencia del Portafolio',
        ], '2026-09-01', '2026-10-05');

        $this->actingAs($this->jhon)
            ->get(route('ai.value.index', ['company_id' => $this->derivados->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.company_id', $this->derivados->id)
                ->where('metrics', null)
                ->has('areas', 1)
                ->where('products', [])
                ->where('awaiting_rating.request_key', $waiting->key)
                ->where('awaiting_rating.title', 'Buscador por referencia del Portafolio')
                ->where('awaiting_rating.requester.name', 'Diego Restrepo')
            );
    }

    public function test_only_gestor_managers_open_it_and_export_it(): void
    {
        $this->actingAs($this->paola)->get(route('ai.value.index'))->assertForbidden();
        $this->actingAs($this->paola)->get(route('ai.value.export'))->assertForbidden();

        $response = $this->actingAs($this->jhon)->get(route('ai.value.export', ['period' => '12m']))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('valor-gestor-ia-2026-10-07.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_it_reminds_the_requester_to_rate_a_delivery(): void
    {
        Notification::fake();
        $waiting = $this->delivered([], '2026-09-01', '2026-10-05');

        $this->actingAs($this->jhon)
            ->from(route('ai.value.index'))
            ->post(route('ai.ratings.remind', ['request' => $waiting->key]))
            ->assertRedirect(route('ai.value.index'))
            ->assertSessionHas('success', 'Le recordamos a Paola Ríos que califique la entrega.');

        Notification::assertSentTo($this->paola, AiRatingRequested::class);
        $this->assertNotNull($waiting->fresh()->rating_reminded_at);

        // Already rated: nothing to remind.
        $this->actingAs($this->jhon)
            ->from(route('ai.value.index'))
            ->post(route('ai.ratings.remind', ['request' => $this->first->key]))
            ->assertSessionHas('error');

        $this->actingAs($this->paola)->post(route('ai.ratings.remind', ['request' => $waiting->key]))->assertForbidden();
    }
}
