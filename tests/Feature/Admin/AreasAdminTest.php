<?php

namespace Tests\Feature\Admin;

use App\Models\Area;
use App\Models\Company;
use App\Models\DepartmentAreaMapping;
use App\Models\Employee;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AreasAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private Area $ventas;

    private Area $cartera;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['areas.manage', 'companies.view_all'] as $permission) {
            Permission::create(['name' => $permission]);
        }

        $this->segurex = Company::factory()->create(['name' => 'A Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'B Derivados']);

        $this->ventas = Area::factory()->for($this->segurex)->create(['name' => 'Ventas', 'slug' => 'ventas']);
        Area::factory()->for($this->derivados)->create(['name' => 'Ventas', 'slug' => 'ventas']);
        $this->cartera = Area::factory()->for($this->derivados)->create(['name' => 'Cartera', 'slug' => 'cartera']);
    }

    private function manager(Company $company, bool $global = false): User
    {
        $user = User::factory()->create(['company_id' => $company->id, 'name' => 'Gestor '.$company->id]);
        $user->givePermissionTo($global ? ['areas.manage', 'companies.view_all'] : ['areas.manage']);

        return $user;
    }

    // ---- Index ------------------------------------------------------------

    public function test_index_requires_permission(): void
    {
        $this->actingAs(User::factory()->create(['company_id' => $this->segurex->id]))
            ->get(route('admin.areas.index'))
            ->assertForbidden();
    }

    public function test_index_returns_exact_props_of_the_company(): void
    {
        $approver = User::factory()->create(['company_id' => $this->segurex->id, 'name' => 'Andrea Approver']);
        $this->ventas->update(['approver_user_id' => $approver->id]);
        DepartmentAreaMapping::factory()->forArea($this->ventas)->create(['department' => 'Ventas Nacionales']);
        DepartmentAreaMapping::factory()->forArea($this->ventas)->create(['department' => 'Comercial']);

        Employee::factory()->for($this->segurex)->count(2)->create(['area_id' => $this->ventas->id, 'department' => 'Ventas Nacionales']);
        Employee::factory()->for($this->segurex)->inactive()->create(['area_id' => $this->ventas->id]);
        Employee::factory()->for($this->segurex)->count(3)->create(['department' => 'Logística']);
        Employee::factory()->for($this->segurex)->create(['department' => 'LOGISTICA ']);
        Employee::factory()->for($this->segurex)->create(['department' => 'ventas']);
        Employee::factory()->for($this->segurex)->inactive()->create(['department' => 'Bodega']);
        Employee::factory()->for($this->derivados)->create(['department' => 'Tesorería']);

        $manager = $this->manager($this->segurex);

        $this->actingAs($manager)
            ->get(route('admin.areas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Areas/Index', false)
                ->where('company_id', $this->segurex->id)
                ->where('areas', [[
                    'id' => $this->ventas->id,
                    'name' => 'Ventas',
                    'employees_count' => 2,
                    'departments' => ['Comercial', 'Ventas Nacionales'],
                    'approver' => ['id' => $approver->id, 'name' => 'Andrea Approver'],
                    'active' => true,
                ]])
                // Grouped by normalized name; "ventas" is suggested by the area slug.
                ->where('unmapped_departments', [
                    ['name' => 'Logística', 'employees_count' => 4, 'area_id' => null],
                    ['name' => 'ventas', 'employees_count' => 1, 'area_id' => $this->ventas->id],
                ])
                ->where('area_approval_enabled', false)
                ->where('approver_options', [
                    ['id' => $approver->id, 'name' => 'Andrea Approver'],
                    ['id' => $manager->id, 'name' => $manager->name],
                ])
            );
    }

    public function test_restricted_user_cannot_read_another_company(): void
    {
        $this->actingAs($this->manager($this->segurex))
            ->get(route('admin.areas.index', ['company_id' => $this->derivados->id]))
            ->assertForbidden();
    }

    public function test_global_user_chooses_the_company_and_defaults_to_the_context(): void
    {
        $user = $this->manager($this->segurex, global: true);

        // "Todas": first active company by name.
        $this->actingAs($user)
            ->get(route('admin.areas.index'))
            ->assertInertia(fn (Assert $page) => $page->where('company_id', $this->segurex->id)->has('areas', 1));

        $this->get(route('admin.areas.index', ['company_id' => $this->derivados->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('company_id', $this->derivados->id)
                ->where('areas.0.name', 'Cartera')
                ->has('areas', 2)
            );

        $this->post(route('company.switch'), ['company_id' => $this->derivados->id]);

        $this->get(route('admin.areas.index'))
            ->assertInertia(fn (Assert $page) => $page->where('company_id', $this->derivados->id));

        // An explicit company wins over the session context.
        $this->get(route('admin.areas.index', ['company_id' => $this->segurex->id]))
            ->assertInertia(fn (Assert $page) => $page->where('company_id', $this->segurex->id)->has('areas', 1));

        $this->get(route('admin.areas.index', ['company_id' => 999]))->assertNotFound();
    }

    // ---- Store / update ---------------------------------------------------

    public function test_store_creates_area_with_departments_and_approver(): void
    {
        $approver = User::factory()->create(['company_id' => $this->segurex->id]);
        $free = Employee::factory()->for($this->segurex)->create(['department' => 'Tesorería']);
        $locked = Employee::factory()->for($this->segurex)->create(['department' => 'TESORERIA', 'area_id' => $this->ventas->id, 'area_locked' => true]);

        $this->actingAs($this->manager($this->segurex))
            ->post(route('admin.areas.store'), [
                'company_id' => $this->segurex->id,
                'name' => 'Tesorería',
                'departments' => [' Tesorería ', 'Pagos'],
                'approver_id' => $approver->id,
                'active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Área creada.')
            ->assertRedirect();

        $area = Area::withoutGlobalScopes()->where('company_id', $this->segurex->id)->where('slug', 'tesoreria')->firstOrFail();
        $this->assertSame($approver->id, $area->approver_user_id);
        $this->assertTrue($area->is_active);
        $this->assertEqualsCanonicalizing(
            ['Tesorería', 'Pagos'],
            DepartmentAreaMapping::withoutGlobalScopes()->where('area_id', $area->id)->pluck('department')->all(),
        );
        $this->assertSame($area->id, $free->fresh()->area_id);
        $this->assertSame($this->ventas->id, $locked->fresh()->area_id);
    }

    public function test_store_validates_name_departments_and_approver(): void
    {
        $foreignUser = User::factory()->create(['company_id' => $this->derivados->id]);
        DepartmentAreaMapping::factory()->forArea($this->ventas)->create(['department' => 'Comercial']);

        $this->actingAs($this->manager($this->segurex))
            ->post(route('admin.areas.store'), [
                'company_id' => $this->segurex->id,
                'name' => 'Ventas',
                'departments' => ['Pagos', 'PAGOS'],
                'approver_id' => $foreignUser->id,
                'active' => true,
            ])
            ->assertSessionHasErrors(['name', 'departments.1', 'approver_id']);
    }

    public function test_restricted_user_cannot_store_in_another_company(): void
    {
        $this->actingAs($this->manager($this->segurex))
            ->post(route('admin.areas.store'), [
                'company_id' => $this->derivados->id,
                'name' => 'Tesorería',
                'departments' => [],
                'approver_id' => null,
                'active' => true,
            ])
            ->assertForbidden();
    }

    public function test_global_user_stores_in_the_chosen_company(): void
    {
        $this->actingAs($this->manager($this->segurex, global: true))
            ->post(route('admin.areas.store'), [
                'company_id' => $this->derivados->id,
                'name' => 'Tesorería',
                'departments' => [],
                'approver_id' => null,
                'active' => false,
            ])
            ->assertSessionHasNoErrors();

        $area = Area::withoutGlobalScopes()->where('company_id', $this->derivados->id)->where('slug', 'tesoreria')->firstOrFail();
        $this->assertFalse($area->is_active);
    }

    public function test_update_moves_departments_replaces_approver_and_is_audited(): void
    {
        DepartmentAreaMapping::factory()->forArea($this->ventas)->create(['department' => 'Comercial']);
        $mercadeo = Area::factory()->for($this->segurex)->create(['name' => 'Mercadeo', 'slug' => 'mercadeo']);
        DepartmentAreaMapping::factory()->forArea($mercadeo)->create(['department' => 'Publicidad']);
        $publicidad = Employee::factory()->for($this->segurex)->create(['department' => 'Publicidad', 'area_id' => $mercadeo->id]);
        $comercial = Employee::factory()->for($this->segurex)->create(['department' => 'Comercial', 'area_id' => $this->ventas->id]);
        $approver = User::factory()->create(['company_id' => $this->segurex->id]);
        $manager = $this->manager($this->segurex);

        $this->actingAs($manager)
            ->put(route('admin.areas.update', $this->ventas), [
                'company_id' => $this->segurex->id,
                'name' => 'Ventas nacionales',
                'departments' => ['Publicidad'],
                'approver_id' => $approver->id,
                'active' => false,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Área actualizada.');

        $area = $this->ventas->fresh();
        $this->assertSame('Ventas nacionales', $area->name);
        $this->assertFalse($area->is_active);
        $this->assertSame($approver->id, $area->approver_user_id);
        $this->assertSame(['Publicidad'], DepartmentAreaMapping::withoutGlobalScopes()->where('area_id', $area->id)->pluck('department')->all());
        $this->assertSame(0, DepartmentAreaMapping::withoutGlobalScopes()->where('area_id', $mercadeo->id)->count());
        $this->assertSame($area->id, $publicidad->fresh()->area_id);
        $this->assertNull($comercial->fresh()->area_id);

        $activity = Activity::query()->where('subject_type', $area->getMorphClass())->where('subject_id', $area->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($manager->id, $activity->causer_id);
        $this->assertSame($approver->id, $activity->properties['attributes']['approver_user_id']);
    }

    public function test_cannot_update_area_of_another_company(): void
    {
        $this->actingAs($this->manager($this->segurex))
            ->put(route('admin.areas.update', $this->cartera), [
                'company_id' => $this->segurex->id,
                'name' => 'Hackeada',
                'departments' => [],
                'approver_id' => null,
                'active' => true,
            ])
            ->assertNotFound();

        $this->assertSame('Cartera', $this->cartera->fresh()->name);
    }

    public function test_global_user_updates_areas_outside_the_session_context(): void
    {
        $user = $this->manager($this->segurex, global: true);
        $this->actingAs($user)->post(route('company.switch'), ['company_id' => $this->segurex->id]);

        $this->put(route('admin.areas.update', $this->cartera), [
            'company_id' => $this->derivados->id,
            'name' => 'Cartera y cobranzas',
            'departments' => [],
            'approver_id' => null,
            'active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Cartera y cobranzas', $this->cartera->fresh()->name);
    }

    // ---- Mappings -----------------------------------------------------------

    public function test_mappings_bulk_update_reapplies_to_unlocked_employees(): void
    {
        $free = Employee::factory()->for($this->segurex)->create(['department' => 'Logística']);
        $locked = Employee::factory()->for($this->segurex)->create(['department' => 'logistica', 'area_locked' => true]);
        $old = DepartmentAreaMapping::factory()->forArea($this->ventas)->create(['department' => 'Comercial']);
        $comercial = Employee::factory()->for($this->segurex)->create(['department' => 'Comercial', 'area_id' => $this->ventas->id]);

        $this->actingAs($this->manager($this->segurex))
            ->put(route('admin.areas.mappings.update'), [
                'company_id' => $this->segurex->id,
                'mappings' => [
                    ['department' => 'Logística', 'area_id' => $this->ventas->id],
                    ['department' => 'Comercial', 'area_id' => null],
                    ['department' => 'Bodega', 'area_id' => null],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Departamentos asignados.');

        $this->assertSame($this->ventas->id, $free->fresh()->area_id);
        $this->assertNull($locked->fresh()->area_id);
        $this->assertModelMissing($old);
        $this->assertNull($comercial->fresh()->area_id);
        $this->assertSame(1, DepartmentAreaMapping::withoutGlobalScopes()->where('company_id', $this->segurex->id)->count());
    }

    public function test_mappings_reject_areas_of_another_company_and_other_companies(): void
    {
        $manager = $this->manager($this->segurex);

        $this->actingAs($manager)
            ->put(route('admin.areas.mappings.update'), [
                'company_id' => $this->segurex->id,
                'mappings' => [['department' => 'Logística', 'area_id' => $this->cartera->id]],
            ])
            ->assertSessionHasErrors('mappings.0.area_id');

        $this->put(route('admin.areas.mappings.update'), [
            'company_id' => $this->derivados->id,
            'mappings' => [['department' => 'Logística', 'area_id' => $this->cartera->id]],
        ])->assertForbidden();

        $this->assertSame(0, DepartmentAreaMapping::withoutGlobalScopes()->count());
    }

    // ---- Area approval ------------------------------------------------------

    public function test_area_approval_toggle_is_persisted_per_company(): void
    {
        $this->actingAs($this->manager($this->segurex))
            ->put(route('admin.areas.approval.update'), ['company_id' => $this->segurex->id, 'enabled' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue(app(Settings::class)->get('areas.approval_enabled.'.$this->segurex->id));
        $this->assertFalse((bool) app(Settings::class)->get('areas.approval_enabled.'.$this->derivados->id, false));

        $this->get(route('admin.areas.index'))
            ->assertInertia(fn (Assert $page) => $page->where('area_approval_enabled', true));

        $this->put(route('admin.areas.approval.update'), ['company_id' => $this->derivados->id, 'enabled' => true])
            ->assertForbidden();
    }
}
