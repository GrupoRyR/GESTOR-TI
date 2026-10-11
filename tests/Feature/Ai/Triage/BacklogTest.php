<?php

namespace Tests\Feature\Ai\Triage;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use App\Support\Settings;
use Inertia\Testing\AssertableInertia as Assert;

class BacklogTest extends TriageTestCase
{
    public function test_backlog_returns_exactly_the_contract_props(): void
    {
        $this->assertSame(
            $this->withShared(['items', 'manual_order', 'wip', 'filters', 'areas', 'now']),
            $this->propKeys(route('ai.backlog.index')),
        );

        $this->actingAs($this->paola)->get(route('ai.backlog.index'))->assertForbidden();
    }

    public function test_items_follow_the_suggested_priority_until_reordered(): void
    {
        $low = $this->evaluated(['status' => RequestStatus::Backlog, 'title' => 'Baja', 'final_priority' => 20, 'priority_overridden' => true]);
        $high = $this->evaluated(['status' => RequestStatus::Backlog, 'title' => 'Alta', 'final_priority' => 80, 'priority_overridden' => true]);
        $this->evaluated(['title' => 'En evaluación']);

        $this->actingAs($this->jhon)
            ->get(route('ai.backlog.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Backlog/Index', false)
                ->has('items', 2)
                ->where('items.0.key', $high->key)
                ->where('items.0.rank', 1)
                ->where('items.1.key', $low->key)
                ->where('manual_order', null)
                ->where('wip', ['in_development' => 0, 'limit' => 3])
                ->where('filters', ['area_id' => null, 'company_id' => null])
            );

        $this->actingAs($this->jhon)
            ->put(route('ai.backlog.reorder'), ['order' => [$low->key, $high->key]])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->jhon)
            ->get(route('ai.backlog.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.0.key', $low->key)
                ->where('items.0.rank', 1)
                ->where('manual_order.by.name', 'Jhon Castro')
                ->where('manual_order.moved_key', $low->key)
                ->where('manual_order.above_key', $high->key)
            );

        $this->actingAs($this->jhon)->delete(route('ai.backlog.manual'))->assertSessionHasNoErrors();

        $this->actingAs($this->jhon)
            ->get(route('ai.backlog.index'))
            ->assertInertia(fn (Assert $page) => $page->where('items.0.key', $high->key)->where('manual_order', null));
    }

    public function test_wip_limit_comes_from_settings_with_default_three(): void
    {
        $this->assertSame(3, config('gestor.settings_defaults')['ai.wip_limit']);

        app(Settings::class)->set('ai.wip_limit', 2);
        AiProject::factory()->create(['company_id' => $this->segurex->id, 'area_id' => $this->ventas->id, 'status' => RequestStatus::InDevelopment]);
        AiProject::factory()->create(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'status' => RequestStatus::InDevelopment]);

        $this->actingAs($this->jhon)
            ->withSession(['current_company_id' => $this->segurex->id])
            ->get(route('ai.backlog.index'))
            // WIP is global: both companies count.
            ->assertInertia(fn (Assert $page) => $page->where('wip', ['in_development' => 2, 'limit' => 2]));
    }

    public function test_filters_by_area_and_company(): void
    {
        $ventas = $this->evaluated(['status' => RequestStatus::Backlog]);
        $this->evaluated(['status' => RequestStatus::Backlog, 'area_id' => $this->compras->id]);
        $derivados = $this->evaluated(['status' => RequestStatus::Backlog, 'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);

        $this->actingAs($this->jhon)
            ->get(route('ai.backlog.index', ['area_id' => $this->ventas->id]))
            ->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.key', $ventas->key)->where('filters.area_id', $this->ventas->id));

        $this->actingAs($this->jhon)
            ->get(route('ai.backlog.index', ['company_id' => $this->derivados->id]))
            ->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.key', $derivados->key));
    }

    public function test_reorder_ignores_keys_outside_the_backlog(): void
    {
        $a = $this->evaluated(['status' => RequestStatus::Backlog]);
        $outside = $this->evaluated();

        $this->actingAs($this->jhon)
            ->put(route('ai.backlog.reorder'), ['order' => [$outside->key, $a->key]])
            ->assertSessionHasNoErrors();

        $this->assertNull($outside->fresh()->backlog_position);
        $this->assertSame(1, $a->fresh()->backlog_position);
    }
}
