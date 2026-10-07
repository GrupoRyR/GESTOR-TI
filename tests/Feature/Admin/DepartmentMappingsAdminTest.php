<?php

namespace Tests\Feature\Admin;

use App\Models\Area;
use App\Models\Company;
use App\Models\DepartmentAreaMapping;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DepartmentMappingsAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private Area $contabilidad;

    private Area $foreignArea;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['employees.manage', 'companies.view_all'] as $permission) {
            Permission::create(['name' => $permission]);
        }

        $this->segurex = Company::factory()->create();
        $this->derivados = Company::factory()->create();
        $this->contabilidad = Area::factory()->for($this->segurex)->create(['name' => 'Contabilidad']);
        $this->foreignArea = Area::factory()->for($this->derivados)->create(['name' => 'Cartera']);

        DepartmentAreaMapping::factory()->forArea($this->foreignArea)->create(['department' => 'Cartera']);
    }

    private function manager(bool $global = false): User
    {
        $user = User::factory()->create(['company_id' => $this->segurex->id]);
        $user->givePermissionTo($global ? ['employees.manage', 'companies.view_all'] : ['employees.manage']);

        return $user;
    }

    public function test_index_requires_employees_manage(): void
    {
        $this->actingAs(User::factory()->create(['company_id' => $this->segurex->id]))
            ->get(route('admin.department-mappings.index'))
            ->assertForbidden();
    }

    public function test_index_returns_exact_props_in_company_scope(): void
    {
        $mapping = DepartmentAreaMapping::factory()->forArea($this->contabilidad)->create(['department' => 'Contabilidad y Tesorería']);
        Employee::factory()->for($this->segurex)->create(['department' => 'Ventas']);
        Employee::factory()->for($this->segurex)->create(['department' => 'Ventas']);
        Employee::factory()->for($this->segurex)->create(['department' => 'CONTABILIDAD Y TESORERIA']);
        Employee::factory()->for($this->derivados)->create(['department' => 'Bodega']);

        $this->actingAs($this->manager())
            ->get(route('admin.department-mappings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/DepartmentMappings/Index', false)
                ->has('mappings', 1)
                ->where('mappings.0', [
                    'id' => $mapping->id,
                    'company_id' => $this->segurex->id,
                    'company_short_name' => $this->segurex->short_name,
                    'department' => 'Contabilidad y Tesorería',
                    'area' => ['id' => $this->contabilidad->id, 'name' => 'Contabilidad'],
                ])
                ->where('areas', [['id' => $this->contabilidad->id, 'name' => 'Contabilidad', 'company_id' => $this->segurex->id]])
                // Departments seen in employees that are not mapped yet.
                ->where('unmapped_departments', ['Ventas'])
            );
    }

    public function test_store_applies_the_mapping_to_unlocked_employees(): void
    {
        $free = Employee::factory()->for($this->segurex)->create(['department' => 'Contabilidad']);
        $ventas = Area::factory()->for($this->segurex)->create(['name' => 'Ventas']);
        $locked = Employee::factory()->for($this->segurex)->create(['department' => 'contabilidad', 'area_id' => $ventas->id, 'area_locked' => true]);

        $this->actingAs($this->manager())
            ->post(route('admin.department-mappings.store'), ['department' => ' Contabilidad ', 'area_id' => $this->contabilidad->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Mapeo guardado.');

        $mapping = DepartmentAreaMapping::withoutGlobalScopes()->where('company_id', $this->segurex->id)->sole();
        $this->assertSame('Contabilidad', $mapping->department);
        $this->assertSame('contabilidad', $mapping->department_key);

        $this->assertSame($this->contabilidad->id, $free->fresh()->area_id);
        $this->assertSame($ventas->id, $locked->fresh()->area_id);
    }

    public function test_store_rejects_duplicates_ignoring_case_and_accents(): void
    {
        DepartmentAreaMapping::factory()->forArea($this->contabilidad)->create(['department' => 'Tesorería']);

        $this->actingAs($this->manager())
            ->post(route('admin.department-mappings.store'), ['department' => 'TESORERIA', 'area_id' => $this->contabilidad->id])
            ->assertSessionHasErrors('department');
    }

    public function test_store_rejects_areas_outside_the_company_scope(): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.department-mappings.store'), ['department' => 'Bodega', 'area_id' => $this->foreignArea->id])
            ->assertSessionHasErrors('area_id');
    }

    public function test_global_manager_maps_any_company_through_the_area(): void
    {
        $this->actingAs($this->manager(global: true))
            ->post(route('admin.department-mappings.store'), ['department' => 'Bodega', 'area_id' => $this->foreignArea->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $this->derivados->id,
            DepartmentAreaMapping::withoutGlobalScopes()->where('department_key', 'bodega')->value('company_id'),
        );
    }

    public function test_destroy_clears_the_area_of_unlocked_employees(): void
    {
        $mapping = DepartmentAreaMapping::factory()->forArea($this->contabilidad)->create(['department' => 'Contabilidad']);
        $free = Employee::factory()->for($this->segurex)->create(['department' => 'Contabilidad', 'area_id' => $this->contabilidad->id]);

        $this->actingAs($this->manager())
            ->delete(route('admin.department-mappings.destroy', $mapping))
            ->assertSessionHas('success', 'Mapeo eliminado.');

        $this->assertModelMissing($mapping);
        $this->assertNull($free->fresh()->area_id);
    }

    public function test_destroy_of_another_company_is_not_found(): void
    {
        $foreign = DepartmentAreaMapping::withoutGlobalScopes()->where('company_id', $this->derivados->id)->sole();

        $this->actingAs($this->manager())
            ->delete(route('admin.department-mappings.destroy', $foreign))
            ->assertNotFound();
    }
}
