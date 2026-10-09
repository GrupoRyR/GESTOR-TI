<?php

namespace Tests\Feature\Admin;

use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\TelegramLink;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Administración › Usuarios (`Admin/Users/Index`, types/pages/admin.ts).
 */
class UsersAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);

        $this->admin = User::factory()->microsoft()->create([
            'name' => 'Jhon Castro',
            'email' => 'jhon.castro@segurex.com',
            'company_id' => $this->segurex->id,
        ]);
        $this->admin->assignRole('Súper Admin');
        Permission::withExclusiveGrant(fn () => $this->admin->givePermissionTo(Permission::VAULT));
    }

    /**
     * Admin restricted to their company: `users.manage` without `companies.view_all`.
     */
    private function restrictedAdmin(Company $company): User
    {
        return tap(User::factory()->microsoft()->create(['company_id' => $company->id]))
            ->givePermissionTo(['admin.access', 'users.manage']);
    }

    private function member(Company $company, array $attributes = [], array $roles = ['Usuario final']): User
    {
        return tap(User::factory()->microsoft()->create(['company_id' => $company->id, ...$attributes]))->assignRole($roles);
    }

    public function test_index_requires_users_manage(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));

        $this->actingAs($this->member($this->segurex))->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_index_returns_exact_props(): void
    {
        $paola = $this->member($this->segurex, ['name' => 'Paola Ríos', 'email' => 'paola.rios@segurex.com', 'last_login_at' => '2026-10-03 11:00:00']);
        TelegramLink::query()->create(['user_id' => $paola->id, 'chat_id' => '555', 'linked_at' => now()]);
        $emergency = User::factory()->create(['name' => 'Cuenta de emergencia', 'email' => 'admin.emergencia@example.com']);
        $emergency->assignRole('Súper Admin');
        $this->member($this->derivados, ['name' => 'Andrés Méndez', 'active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index', false)
                ->has('users.data', 4)
                ->where('users.data.0.name', 'Andrés Méndez')
                ->where('users.data.0.active', false)
                ->where('users.data.1', [
                    'id' => $emergency->id,
                    'name' => 'Cuenta de emergencia',
                    'email' => 'admin.emergencia@example.com',
                    'company_id' => null,
                    'roles' => ['Súper Admin'],
                    'last_login_at' => null,
                    'telegram_linked' => false,
                    'is_local' => true,
                    'active' => true,
                ])
                ->where('users.data.2.name', 'Jhon Castro')
                ->where('users.data.3', fn ($row) => $row['id'] === $paola->id
                    && $row['company_id'] === $this->segurex->id
                    && $row['roles'] === ['Usuario final']
                    && str_starts_with((string) $row['last_login_at'], '2026-10-03T11:00:00')
                    && $row['telegram_linked'] === true
                    && $row['is_local'] === false)
                ->has('users.links')
                ->where('users.meta.total', 4)
                ->where('users.meta.per_page', 25)
                ->where('filters', ['q' => null, 'role' => null])
                ->where('selected', null)
                ->has('role_options', 5)
                ->where('role_options.0', [
                    'name' => 'Súper Admin',
                    'label' => 'Súper Admin',
                    'description' => 'Administra empresas, usuarios e integraciones',
                    'requires_area' => false,
                ])
                ->where('role_options.2.name', 'Aprobador de área')
                ->where('role_options.2.requires_area', true)
                ->where('vault_holder_name', 'Jhon Castro')
                ->where('area_options', [])
            );

        $props = $this->actingAs($this->admin)->get(route('admin.users.index'))->viewData('page')['props'];
        $own = array_diff(array_keys($props), ['errors', 'auth', 'flash', 'companies', 'current_company_id', 'notifications_unread', 'modules']);
        $this->assertEqualsCanonicalizing(
            ['users', 'filters', 'selected', 'role_options', 'area_options', 'vault_holder_name'],
            array_values($own),
        );
    }

    public function test_area_options_list_active_areas_by_name(): void
    {
        $ventas = Area::factory()->for($this->segurex)->create(['name' => 'Ventas']);
        $compras = Area::factory()->for($this->derivados)->create(['name' => 'Compras']);
        Area::factory()->for($this->segurex)->inactive()->create(['name' => 'Archivo']);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page->where('area_options', [
                ['id' => $compras->id, 'name' => 'Compras', 'company_id' => $this->derivados->id],
                ['id' => $ventas->id, 'name' => 'Ventas', 'company_id' => $this->segurex->id],
            ]));
    }

    public function test_filters_by_query_and_role(): void
    {
        $this->member($this->segurex, ['name' => 'Paola Ríos', 'email' => 'paola.rios@segurex.com']);
        $this->member($this->segurex, ['name' => 'Edward Ramanan'], ['Aprobador de proyectos', 'Usuario final']);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['q' => 'paola.rios']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Paola Ríos'])
                ->where('filters', ['q' => 'paola.rios', 'role' => null]));

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['role' => 'Aprobador de proyectos']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Edward Ramanan'])
                ->where('filters.role', 'Aprobador de proyectos'));

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['role' => 'Dios']))
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 3)->where('filters.role', null));
    }

    public function test_restricted_admin_only_sees_users_of_their_company(): void
    {
        $eva = $this->member($this->derivados, ['name' => 'Eva Derivados']);
        Area::factory()->for($this->segurex)->create(['name' => 'Ventas']);
        $admin = $this->restrictedAdmin($this->derivados);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->pluck('company_id')->unique()->all() === [$this->derivados->id])
                ->has('users.data', 2)
                ->where('area_options', []));

        $this->actingAs($admin)->get(route('admin.users.show', $this->admin))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.users.show', $eva))->assertOk();
        $this->actingAs($admin)->put(route('admin.users.roles.update', $this->admin), ['roles' => [], 'area_id' => null])->assertNotFound();
        $this->actingAs($admin)->patch(route('admin.users.deactivate', $this->admin))->assertNotFound();
    }

    public function test_global_admin_with_a_selected_company_sees_that_company(): void
    {
        $this->member($this->derivados, ['name' => 'Eva Derivados']);

        $this->actingAs($this->admin)
            ->withSession([CurrentCompany::SESSION_KEY => $this->derivados->id])
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Eva Derivados']));
    }

    public function test_show_renders_the_selected_user_with_employee_and_approved_area(): void
    {
        $edward = $this->member($this->segurex, ['name' => 'Edward Ramanan'], ['Aprobador de área', 'Usuario final']);
        $gerencia = Area::factory()->for($this->segurex)->create(['name' => 'Gerencia']);
        $ventas = Area::factory()->for($this->segurex)->create(['name' => 'Ventas', 'approver_user_id' => $edward->id]);
        Employee::factory()->for($this->segurex)->create([
            'user_id' => $edward->id,
            'area_id' => $gerencia->id,
            'job_title' => 'Gerente financiero',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $edward))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index', false)
                ->where('selected.id', $edward->id)
                ->where('selected.roles', ['Aprobador de área', 'Usuario final'])
                ->where('selected.job_title', 'Gerente financiero')
                ->where('selected.area_name', 'Gerencia')
                ->where('selected.approver_area_id', $ventas->id)
                ->has('selected', 12)
                ->has('users.data', 2));

        $emergency = User::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $emergency))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.is_local', true)
                ->where('selected.job_title', null)
                ->where('selected.area_name', null)
                ->where('selected.approver_area_id', null));
    }

    public function test_roles_update_syncs_roles_and_logs(): void
    {
        $paola = $this->member($this->segurex, ['name' => 'Paola Ríos']);

        $this->actingAs($this->admin)
            ->from(route('admin.users.show', $paola))
            ->put(route('admin.users.roles.update', $paola), ['roles' => ['Aprobador de proyectos', 'Usuario final'], 'area_id' => null])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.show', $paola))
            ->assertSessionHas('success', 'Roles de Paola Ríos actualizados.');

        $this->assertEqualsCanonicalizing(['Aprobador de proyectos', 'Usuario final'], $paola->fresh()->getRoleNames()->all());

        $activity = Activity::query()->where('event', 'user_roles_updated')->sole();
        $this->assertSame('users', $activity->log_name);
        $this->assertTrue($activity->causer->is($this->admin));
        $this->assertTrue($activity->subject->is($paola));
        $this->assertSame(['Usuario final'], $activity->properties['old']['roles']);
        $this->assertSame(['Aprobador de proyectos', 'Usuario final'], $activity->properties['attributes']['roles']);
    }

    public function test_area_approver_role_requires_an_area_of_the_user_company(): void
    {
        $paola = $this->member($this->segurex);
        $compras = Area::factory()->for($this->derivados)->create(['name' => 'Compras']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.users.roles.update', $paola), ['roles' => ['Aprobador de área'], 'area_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area_id' => 'Escoge el área que aprueba.']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.users.roles.update', $paola), ['roles' => ['Aprobador de área'], 'area_id' => $compras->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area_id' => 'El área debe ser un área activa de la empresa del usuario.']);

        $this->assertFalse($paola->fresh()->hasRole('Aprobador de área'));
    }

    public function test_area_approver_role_sets_and_clears_the_area_approver(): void
    {
        $paola = $this->member($this->segurex, ['name' => 'Paola Ríos']);
        $ventas = Area::factory()->for($this->segurex)->create(['name' => 'Ventas']);
        $compras = Area::factory()->for($this->segurex)->create(['name' => 'Compras']);

        $this->actingAs($this->admin)
            ->put(route('admin.users.roles.update', $paola), ['roles' => ['Aprobador de área', 'Usuario final'], 'area_id' => $ventas->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($paola->id, $ventas->fresh()->approver_user_id);

        // Moving to another area releases the previous one.
        $this->actingAs($this->admin)
            ->put(route('admin.users.roles.update', $paola), ['roles' => ['Aprobador de área', 'Usuario final'], 'area_id' => $compras->id])
            ->assertSessionHasNoErrors();

        $this->assertNull($ventas->fresh()->approver_user_id);
        $this->assertSame($paola->id, $compras->fresh()->approver_user_id);

        // Removing the role clears the approval.
        $this->actingAs($this->admin)
            ->put(route('admin.users.roles.update', $paola), ['roles' => ['Usuario final'], 'area_id' => $compras->id])
            ->assertSessionHasNoErrors();

        $this->assertNull($compras->fresh()->approver_user_id);
        $this->assertSame(['Usuario final'], $paola->fresh()->getRoleNames()->all());
    }

    public function test_roles_update_rejects_unknown_roles_and_keeps_the_vault_permission(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('admin.users.roles.update', $this->admin), ['roles' => ['Dios'], 'area_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles.0']);

        $this->actingAs($this->admin)
            ->put(route('admin.users.roles.update', $this->admin), ['roles' => ['Líder IA'], 'area_id' => null])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->admin->fresh()->hasDirectPermission(Permission::VAULT));
        $this->assertSame(['Líder IA'], $this->admin->fresh()->getRoleNames()->all());
    }

    public function test_roles_of_local_or_deactivated_users_cannot_be_changed(): void
    {
        $local = tap(User::factory()->create(['company_id' => $this->segurex->id]))->assignRole('Súper Admin');
        $inactive = $this->member($this->segurex, ['active' => false]);

        $this->actingAs($this->admin)
            ->putJson(route('admin.users.roles.update', $local), ['roles' => [], 'area_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles' => 'Los roles de la cuenta de emergencia no se cambian desde aquí.']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.users.roles.update', $inactive), ['roles' => [], 'area_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles' => 'El usuario está desactivado.']);

        $this->assertTrue($local->fresh()->hasRole('Súper Admin'));
    }

    public function test_deactivate_marks_the_user_inactive_and_logs(): void
    {
        $paola = $this->member($this->segurex, ['name' => 'Paola Ríos']);

        $this->actingAs($this->admin)
            ->from(route('admin.users.show', $paola))
            ->patch(route('admin.users.deactivate', $paola))
            ->assertRedirect(route('admin.users.show', $paola))
            ->assertSessionHas('success', 'Paola Ríos quedó desactivado.');

        $this->assertFalse($paola->fresh()->active);

        $activity = Activity::query()->where('event', 'user_deactivated')->sole();
        $this->assertSame('users', $activity->log_name);
        $this->assertTrue($activity->subject->is($paola));
        $this->assertSame(['active' => true], $activity->properties['old']);
        $this->assertSame(['active' => false], $activity->properties['attributes']);
    }

    public function test_admins_cannot_deactivate_themselves(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.users.deactivate', $this->admin))->assertForbidden();

        $this->assertTrue($this->admin->fresh()->active);
    }

    public function test_deactivate_requires_users_manage(): void
    {
        $paola = $this->member($this->segurex);

        $this->actingAs($this->member($this->segurex))->patch(route('admin.users.deactivate', $paola))->assertForbidden();
    }

    public function test_area_approver_options_exclude_deactivated_users(): void
    {
        $this->member($this->segurex, ['name' => 'Inactivo', 'active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.areas.index', ['company_id' => $this->segurex->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('approver_options', [['id' => $this->admin->id, 'name' => 'Jhon Castro']]));
    }
}
