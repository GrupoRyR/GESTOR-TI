<?php

namespace Tests\Feature\Ai\Costs;

use App\Domain\Ai\CostRates;
use App\Models\AiCostRate;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * Administración › Costos y aprobaciones (`Admin/Costs/Index`,
 * types/pages/admin-costs.ts).
 */
class AdminCostsTest extends CostsTestCase
{
    public function test_page_returns_exactly_the_contract_props(): void
    {
        $this->assertSame(
            $this->withShared(['rates', 'area_values', 'approvers', 'approver_candidates', 'settings', 'today']),
            $this->propKeys(route('admin.costs.index')),
        );

        $this->actingAs($this->paola)->get(route('admin.costs.index'))->assertForbidden();
    }

    public function test_page_lists_rates_with_history_area_values_and_approvers(): void
    {
        AiCostRate::create(['user_id' => $this->jhon->id, 'rate' => 140_000, 'effective_from' => '2025-01-01']);
        AiCostRate::create(['user_id' => $this->jhon->id, 'rate' => 150_000, 'effective_from' => '2025-07-01', 'changed_by_id' => $this->superAdmin->id]);
        AiCostRate::create(['user_id' => $this->jhon->id, 'rate' => 160_000, 'effective_from' => '2026-01-01', 'changed_by_id' => $this->superAdmin->id]);
        $this->ventas->forceFill(['hour_value' => 45_000])->save();

        $this->actingAs($this->jhon)
            ->get(route('admin.costs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Costs/Index', false)
                ->has('rates', 2)
                ->where('rates.0.person.name', 'Jhon Castro')
                ->where('rates.0.rate', 160_000)
                ->where('rates.0.effective_from', '2026-01-01')
                ->where('rates.0.history', [
                    ['rate' => 150_000, 'effective_from' => '2025-07-01', 'changed_by' => 'Leonardo Herrera'],
                    ['rate' => 140_000, 'effective_from' => '2025-01-01', 'changed_by' => null],
                ])
                ->where('rates.1.person.name', 'Leonardo Herrera')
                ->where('rates.1.rate', 0)
                ->where('rates.1.history', [])
                ->where('area_values', [
                    ['area' => ['id' => $this->compras->id, 'name' => 'Compras', 'company_id' => $this->segurex->id], 'value' => null],
                    ['area' => ['id' => $this->ventas->id, 'name' => 'Ventas', 'company_id' => $this->segurex->id], 'value' => 45_000],
                    ['area' => ['id' => $this->derivadosVentas->id, 'name' => 'Ventas', 'company_id' => $this->derivados->id], 'value' => null],
                ])
                ->has('approvers', 1)
                ->where('approvers.0.person.name', 'Edward Ramanan')
                ->where('approvers.0.detail', 'Segurex Latam')
                ->where('approver_candidates', fn ($people) => collect($people)->pluck('name')->all() === ['Jhon Castro', 'Leonardo Herrera'])
                ->where('settings', ['require_other_approver' => false, 'reapproval_threshold_percent' => 20])
                ->where('today', '2026-10-07')
            );
    }

    public function test_without_costs_view_rates_and_values_are_null(): void
    {
        $this->actingAs($this->itWithoutCosts())
            ->get(route('admin.costs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rates', null)->where('area_values', null)->has('approvers', 1));
    }

    public function test_rates_change_from_a_date_and_keep_their_history(): void
    {
        $this->rate($this->jhon, 160_000);

        $this->actingAs($this->jhon)
            ->put(route('admin.costs.rates.update', $this->jhon->id), ['rate' => 180_000, 'effective_from' => '2026-11-01'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $rates = app(CostRates::class);
        $this->assertSame(160_000, $rates->rateFor($this->jhon->id, CarbonImmutable::parse('2026-10-31')));
        $this->assertSame(180_000, $rates->rateFor($this->jhon->id, CarbonImmutable::parse('2026-11-01')));
        $this->assertSame($this->jhon->id, AiCostRate::query()->where('rate', 180_000)->sole()->changed_by_id);

        // Same date again replaces the value.
        $this->actingAs($this->jhon)
            ->put(route('admin.costs.rates.update', $this->jhon->id), ['rate' => 175_000, 'effective_from' => '2026-11-01'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, AiCostRate::query()->where('user_id', $this->jhon->id)->count());

        $this->actingAs($this->jhon)
            ->put(route('admin.costs.rates.update', $this->jhon->id), ['rate' => null, 'effective_from' => 'ayer'])
            ->assertSessionHasErrors(['rate', 'effective_from']);

        $this->actingAs($this->jhon)
            ->put(route('admin.costs.rates.update', $this->paola->id), ['rate' => 1000, 'effective_from' => '2026-11-01'])
            ->assertNotFound();

        $this->actingAs($this->itWithoutCosts())
            ->put(route('admin.costs.rates.update', $this->jhon->id), ['rate' => 1, 'effective_from' => '2026-11-01'])
            ->assertForbidden();
    }

    public function test_area_hour_values_are_saved(): void
    {
        $this->actingAs($this->jhon)
            ->put(route('admin.costs.area-values.update'), ['values' => [
                ['area_id' => $this->ventas->id, 'value' => 50_000],
                ['area_id' => $this->compras->id, 'value' => null],
            ]])
            ->assertSessionHasNoErrors();

        $this->assertSame(50_000, $this->ventas->fresh()->hour_value);
        $this->assertNull($this->compras->fresh()->hour_value);

        $this->actingAs($this->jhon)
            ->put(route('admin.costs.area-values.update'), ['values' => [['area_id' => 99999, 'value' => 1]]])
            ->assertSessionHasErrors('values.0.area_id');

        $this->actingAs($this->itWithoutCosts())
            ->put(route('admin.costs.area-values.update'), ['values' => []])
            ->assertForbidden();
    }

    public function test_approvers_are_granted_and_revoked_through_the_role(): void
    {
        $this->actingAs($this->jhon)
            ->post(route('admin.costs.approvers.store'), ['user_id' => $this->superAdmin->id])
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->superAdmin->fresh()->hasRole('Aprobador de proyectos'));
        $this->assertTrue(Activity::query()->where('log_name', 'ai')->where('subject_id', $this->superAdmin->id)->where('event', 'approver_added')->exists());

        $this->actingAs($this->jhon)
            ->post(route('admin.costs.approvers.store'), ['user_id' => $this->paola->id])
            ->assertSessionHasErrors('user_id');

        $this->actingAs($this->jhon)->delete(route('admin.costs.approvers.destroy', $this->edward->id))->assertSessionHasNoErrors();
        $this->assertFalse($this->edward->fresh()->hasRole('Aprobador de proyectos'));

        // The last approver stays.
        $this->actingAs($this->jhon)->delete(route('admin.costs.approvers.destroy', $this->superAdmin->id))->assertSessionHasErrors('user');
        $this->assertTrue($this->superAdmin->fresh()->hasRole('Aprobador de proyectos'));

        $this->actingAs($this->edward)
            ->post(route('admin.costs.approvers.store'), ['user_id' => $this->jhon->id])
            ->assertForbidden();
    }

    public function test_settings_are_saved_by_it(): void
    {
        $this->actingAs($this->jhon)
            ->put(route('admin.costs.settings.update'), ['require_other_approver' => true, 'reapproval_threshold_percent' => 25])
            ->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertTrue($settings->get('ai.approval.require_other_if_evaluator'));
        $this->assertSame(0.25, $settings->get('ai.reapproval_threshold'));

        $this->actingAs($this->jhon)
            ->put(route('admin.costs.settings.update'), ['require_other_approver' => false, 'reapproval_threshold_percent' => 0])
            ->assertSessionHasErrors('reapproval_threshold_percent');

        $this->actingAs($this->edward)
            ->put(route('admin.costs.settings.update'), ['require_other_approver' => false, 'reapproval_threshold_percent' => 30])
            ->assertForbidden();
    }
}
