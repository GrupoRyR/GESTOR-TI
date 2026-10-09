<?php

namespace Tests\Feature\Ai\Triage;

use App\Enums\Ai\ProductStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiProduct;
use App\Models\Role;
use Inertia\Testing\AssertableInertia as Assert;

class AreasTest extends TriageTestCase
{
    public function test_areas_return_exactly_the_contract_props(): void
    {
        $this->assertSame(
            $this->withShared(['company_id', 'area_companies', 'areas', 'selected_area']),
            $this->propKeys(route('ai.areas.index', ['company_id' => $this->segurex->id])),
        );

        $this->actingAs($this->paola)->get(route('ai.areas.index'))->assertForbidden();
    }

    public function test_counters_and_semaphore_per_area(): void
    {
        $product = AiProduct::factory()->create(['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos', 'status' => ProductStatus::Production]);
        $product->companies()->attach($this->segurex->id, ['is_primary' => true]);
        $product->areas()->attach($this->ventas->id, ['is_primary' => true]);

        // Ventas: one overdue unanswered request → late.
        $late = $this->request(['first_response_due_at' => now()->subDays(2)]);
        $this->evaluated(['status' => RequestStatus::Backlog]);
        $this->request(['status' => RequestStatus::InProduction, 'first_responded_at' => now(), 'hours_per_week' => 3, 'people_count' => 2]);
        // Compras: waiting on the requester → attention.
        $waiting = $this->request(['area_id' => $this->compras->id, 'status' => RequestStatus::NeedsInfo, 'first_responded_at' => now()]);

        $this->actingAs($this->jhon)
            ->get(route('ai.areas.index', ['company_id' => $this->segurex->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Areas/Index', false)
                ->where('company_id', $this->segurex->id)
                ->has('area_companies', 3)
                ->has('areas', 2)
                ->where('selected_area', null)
                ->has('areas.0', fn (Assert $area) => $area
                    ->where('area.name', 'Compras')
                    ->where('semaphore', 'attention')
                    ->where('reason', "{$waiting->key} espera la respuesta de Paola")
                    ->etc()
                )
                ->has('areas.1', fn (Assert $area) => $area
                    ->where('area', ['id' => $this->ventas->id, 'name' => 'Ventas', 'company_id' => $this->segurex->id])
                    ->where('owner', 'Gerente de ventas')
                    ->where('semaphore', 'late')
                    ->where('reason', "{$late->key} sin primera respuesta, vencida hace 2 días")
                    ->where('products_in_use', 1)
                    ->where('projects_in_progress', 0)
                    ->where('backlog', 1)
                    // 3 h × 2 people × 52/12 = 26.
                    ->where('hours_saved_per_month', 26)
                )
            );
    }

    public function test_selected_area_detail(): void
    {
        $backlog = $this->evaluated(['status' => RequestStatus::Backlog]);
        $inbox = $this->request();

        $this->actingAs($this->jhon)
            ->get(route('ai.areas.index', ['company_id' => $this->segurex->id, 'area_id' => $this->ventas->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_area.summary.area.id', $this->ventas->id)
                ->where('selected_area.products', [])
                ->where('selected_area.projects', [])
                ->where('selected_area.backlog.0.key', $backlog->key)
                ->where('selected_area.inbox_keys', [$inbox->key])
            );
    }

    public function test_company_switch_is_limited_to_reachable_companies(): void
    {
        $role = Role::create(['name' => 'TI Segurex', 'guard_name' => 'web']);
        $role->givePermissionTo(['ai.manage', 'ai.requests.create']);
        $local = $this->user($this->segurex, 'TI Segurex', 'TI local');

        $this->actingAs($local)
            ->get(route('ai.areas.index', ['company_id' => $this->derivados->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('company_id', $this->segurex->id)
                ->has('area_companies', 1)
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.areas.index', ['company_id' => $this->third->id]))
            ->assertInertia(fn (Assert $page) => $page->where('company_id', $this->third->id)->where('areas', []));
    }
}
