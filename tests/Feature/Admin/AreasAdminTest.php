<?php

namespace Tests\Feature\Admin;

use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AreasAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['areas.manage', 'companies.view_all'] as $permission) {
            Permission::create(['name' => $permission]);
        }

        $this->segurex = Company::factory()->create();
        $this->derivados = Company::factory()->create();

        Area::factory()->for($this->segurex)->create(['name' => 'Ventas', 'slug' => 'ventas']);
        Area::factory()->for($this->derivados)->create(['name' => 'Ventas', 'slug' => 'ventas']);
        Area::factory()->for($this->derivados)->create(['name' => 'Cartera', 'slug' => 'cartera']);
    }

    private function manager(Company $company, bool $global = false): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo($global ? ['areas.manage', 'companies.view_all'] : ['areas.manage']);

        return $user;
    }

    public function test_index_requires_permission(): void
    {
        $this->actingAs(User::factory()->create(['company_id' => $this->segurex->id]))
            ->get(route('admin.areas.index'))
            ->assertForbidden();
    }

    public function test_index_honours_company_scope_for_regular_users(): void
    {
        $this->actingAs($this->manager($this->derivados))
            ->get(route('admin.areas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Areas/Index', false)
                ->has('areas', 2)
                ->has('areas.0', fn (Assert $area) => $area
                    ->where('company_id', $this->derivados->id)
                    ->hasAll(['id', 'company_id', 'company_short_name', 'name', 'slug', 'is_active'])
                )
                ->has('filters', fn (Assert $filters) => $filters->where('search', null))
            );
    }

    public function test_global_user_sees_all_areas_then_only_the_chosen_company(): void
    {
        $user = $this->manager($this->segurex, global: true);

        $this->actingAs($user)
            ->get(route('admin.areas.index'))
            ->assertInertia(fn (Assert $page) => $page->has('areas', 3));

        $this->post(route('company.switch'), ['company_id' => $this->segurex->id]);

        $this->get(route('admin.areas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('areas', 1)
                ->where('areas.0.company_id', $this->segurex->id)
            );
    }

    public function test_index_filters_by_search(): void
    {
        $this->actingAs($this->manager($this->derivados))
            ->get(route('admin.areas.index', ['search' => 'cart']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('areas', 1)
                ->where('areas.0.slug', 'cartera')
                ->where('filters.search', 'cart')
            );
    }

    public function test_regular_manager_stores_area_in_own_company(): void
    {
        $this->actingAs($this->manager($this->segurex))
            ->post(route('admin.areas.store'), [
                'name' => 'Tesorería',
                'company_id' => $this->derivados->id, // ignored: cannot leave own company
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $area = Area::withoutGlobalScopes()->where('slug', 'tesoreria')->firstOrFail();
        $this->assertSame($this->segurex->id, $area->company_id);
    }

    public function test_global_manager_in_all_context_must_choose_a_company(): void
    {
        $this->actingAs($this->manager($this->segurex, global: true))
            ->post(route('admin.areas.store'), ['name' => 'Tesorería'])
            ->assertSessionHasErrors('company_id');

        $this->post(route('admin.areas.store'), ['name' => 'Tesorería', 'company_id' => $this->derivados->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $this->derivados->id,
            Area::withoutGlobalScopes()->where('slug', 'tesoreria')->value('company_id'),
        );
    }

    public function test_slug_is_unique_per_company(): void
    {
        $this->actingAs($this->manager($this->segurex))
            ->post(route('admin.areas.store'), ['name' => 'Ventas'])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->manager($this->segurex))
            ->post(route('admin.areas.store'), ['name' => 'Cartera'])
            ->assertSessionHasNoErrors();
    }

    public function test_update_area_of_own_company(): void
    {
        $area = Area::withoutGlobalScopes()->where('company_id', $this->segurex->id)->firstOrFail();

        $this->actingAs($this->manager($this->segurex))
            ->put(route('admin.areas.update', $area), ['name' => 'Ventas nacionales', 'is_active' => false])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $area->refresh();
        $this->assertSame('Ventas nacionales', $area->name);
        $this->assertFalse($area->is_active);
    }

    public function test_cannot_update_area_of_another_company(): void
    {
        $area = Area::withoutGlobalScopes()->where('company_id', $this->derivados->id)->firstOrFail();

        $this->actingAs($this->manager($this->segurex))
            ->put(route('admin.areas.update', $area), ['name' => 'Hackeada', 'is_active' => true])
            ->assertNotFound();
    }
}
