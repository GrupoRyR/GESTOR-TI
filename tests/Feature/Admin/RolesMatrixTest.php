<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\RoleMatrix;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Administración › Roles y permisos (`Admin/Roles/Index`, types/pages/admin.ts).
 */
class RolesMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(['name' => 'Jhon Castro']))->assignRole('Súper Admin');
    }

    /**
     * Matrix payload of every role as stored now (visible permissions only).
     *
     * @return list<array{name: string, permissions: list<string>}>
     */
    private function currentMatrix(): array
    {
        $visible = RoleMatrix::visiblePermissionNames();

        return Role::query()->orderBy('id')->get()->map(fn (Role $role) => [
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->intersect($visible)->sort()->values()->all(),
        ])->all();
    }

    /**
     * @param  list<array{name: string, permissions: list<string>}>  $matrix
     * @return list<array{name: string, permissions: list<string>}>
     */
    private function with(array $matrix, string $role, string $permission, bool $on = true): array
    {
        return array_map(function (array $row) use ($role, $permission, $on) {
            if ($row['name'] === $role) {
                $row['permissions'] = $on
                    ? array_values(array_unique([...$row['permissions'], $permission]))
                    : array_values(array_diff($row['permissions'], [$permission]));
            }

            return $row;
        }, $matrix);
    }

    public function test_index_requires_roles_manage(): void
    {
        $user = tap(User::factory()->create())->givePermissionTo('admin.access');

        $this->actingAs($user)->get(route('admin.roles.index'))->assertForbidden();
    }

    public function test_index_returns_exact_props(): void
    {
        $admin = $this->admin();
        Permission::withExclusiveGrant(fn () => $admin->givePermissionTo(Permission::VAULT));
        tap(User::factory()->create(['name' => 'Edward Ramanan']))->assignRole('Aprobador de proyectos');
        tap(User::factory()->inactive()->create(['name' => 'Ex aprobador']))->assignRole('Aprobador de proyectos');

        $response = $this->actingAs($admin)->get(route('admin.roles.index'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Roles/Index', false)
            ->has('roles', 5)
            ->where('roles.0', fn ($role) => $role['name'] === 'Súper Admin'
                && $role['label'] === 'Súper Admin'
                && $role['scope'] === 'Global'
                && $role['system'] === true
                && ! in_array('ai.projects.approve', $role['permissions'], true)
                && in_array('credentials.view', $role['permissions'], true)
                && ! in_array('admin.access', $role['permissions'], true))
            ->where('roles.2.scope', 'Empresa y área')
            ->where('roles.3.permissions', ['ai.costs.view', 'ai.projects.approve', 'ai.requests.create', 'companies.view_all'])
            ->where('roles.4', [
                'name' => 'Usuario final',
                'label' => 'Usuario final',
                'scope' => 'Empresa',
                'permissions' => ['ai.requests.create'],
                'system' => true,
            ])
            ->has('permission_groups', 3)
            ->where('permission_groups.0.key', 'ai')
            ->where('permission_groups.0.label', 'Gestor IA')
            ->has('permission_groups.0.permissions', 10)
            ->where('permission_groups.0.permissions.0', ['name' => 'ai.manage', 'label' => 'Gestionar todo el Gestor IA'])
            ->where('permission_groups.0.permissions.3', [
                'name' => 'ai.projects.approve',
                'label' => 'Aprobar proyectos',
                'hint' => 'Hoy: Edward Ramanan',
            ])
            ->where('permission_groups.1.key', 'assistant')
            ->has('permission_groups.1.permissions', 3)
            ->where('permission_groups.2.key', 'base')
            ->where('permission_groups.2.permissions', [
                ['name' => 'companies.view_all', 'label' => 'Ver todas las empresas'],
                ['name' => 'credentials.view', 'label' => 'Ver contraseñas de colaboradores'],
            ])
            ->where('vault', ['holder_name' => 'Jhon Castro'])
            ->where('reserved_modules', ['Monitoreo', 'Tickets', 'Inventario', 'Operación'])
        );

        $props = $response->viewData('page')['props'];
        $own = array_diff(array_keys($props), ['errors', 'auth', 'flash', 'companies', 'current_company_id', 'notifications_unread', 'modules']);
        $this->assertEqualsCanonicalizing(['roles', 'permission_groups', 'vault', 'reserved_modules'], array_values($own));
    }

    public function test_matrix_permissions_exist_and_are_never_exclusive(): void
    {
        $catalog = array_keys(Permission::catalog());

        foreach (RoleMatrix::visiblePermissionNames() as $name) {
            $this->assertContains($name, $catalog);
            $this->assertFalse(Permission::isExclusive($name));
        }
    }

    public function test_update_syncs_visible_permissions_keeps_hidden_ones_and_logs(): void
    {
        $admin = $this->admin();
        $superAdmin = Role::findByName('Súper Admin');
        $hiddenBefore = $superAdmin->permissions->pluck('name')->diff(RoleMatrix::visiblePermissionNames())->sort()->values()->all();
        $this->assertContains('admin.access', $hiddenBefore);

        $matrix = $this->with($this->currentMatrix(), 'Usuario final', 'assistant.use');
        $matrix = $this->with($matrix, 'Súper Admin', 'ai.mcp.use', false);

        $this->actingAs($admin)
            ->from(route('admin.roles.index'))
            ->put(route('admin.roles.update'), ['roles' => $matrix])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHas('success', 'Permisos guardados.');

        $this->assertEqualsCanonicalizing(['ai.requests.create', 'assistant.use'], Role::findByName('Usuario final')->permissions->pluck('name')->all());

        $superAfter = Role::findByName('Súper Admin')->permissions->pluck('name');
        $this->assertNotContains('ai.mcp.use', $superAfter);
        foreach ($hiddenBefore as $hidden) {
            $this->assertContains($hidden, $superAfter);
        }

        // One entry per changed role, with before/after.
        $activities = Activity::query()->where('event', 'role_permissions_updated')->get();
        $this->assertCount(2, $activities);
        $final = $activities->first(fn (Activity $activity) => $activity->subject->name === 'Usuario final');
        $this->assertSame('users', $final->log_name);
        $this->assertTrue($final->causer->is($admin));
        $this->assertSame(['ai.requests.create'], $final->properties['old']['permissions']);
        $this->assertSame(['ai.requests.create', 'assistant.use'], $final->properties['attributes']['permissions']);
    }

    public function test_update_rejects_the_vault_and_hidden_permissions(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson(route('admin.roles.update'), ['roles' => [['name' => 'Usuario final', 'permissions' => ['ai.requests.create', 'ai.vault.access']]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles.0.permissions.1' => 'La bóveda no se da por roles.']);

        $this->actingAs($admin)
            ->putJson(route('admin.roles.update'), ['roles' => [['name' => 'Usuario final', 'permissions' => ['admin.access']]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles.0.permissions.0']);

        $this->assertSame(['ai.requests.create'], Role::findByName('Usuario final')->permissions->pluck('name')->all());
    }

    public function test_project_approval_only_goes_to_the_project_approver_role(): void
    {
        $matrix = $this->with($this->currentMatrix(), 'Súper Admin', 'ai.projects.approve');

        $this->actingAs($this->admin())
            ->putJson(route('admin.roles.update'), ['roles' => $matrix])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles.0.permissions' => 'Aprobar proyectos solo se da con el rol Aprobador de proyectos.']);

        $this->assertNotContains('ai.projects.approve', Role::findByName('Súper Admin')->permissions->pluck('name'));
    }

    public function test_update_rejects_unknown_roles(): void
    {
        $this->actingAs($this->admin())
            ->putJson(route('admin.roles.update'), ['roles' => [['name' => 'Dios', 'permissions' => []]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles.0.name']);
    }

    public function test_update_requires_roles_manage(): void
    {
        $user = tap(User::factory()->create())->assignRole('Líder IA');

        $this->actingAs($user)->put(route('admin.roles.update'), ['roles' => []])->assertForbidden();
        $this->actingAs($user)->post(route('admin.roles.store'), ['label' => 'Auditor', 'scope' => 'Global', 'copy_from' => null])->assertForbidden();
    }

    public function test_store_creates_a_custom_role_copying_permissions(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('admin.roles.index'))
            ->post(route('admin.roles.store'), ['label' => '  Auditor ', 'scope' => 'Global', 'copy_from' => 'Aprobador de proyectos'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHas('success', 'Rol Auditor agregado.');

        $role = Role::findByName('Auditor');
        $this->assertSame('global', $role->scope);
        $this->assertFalse($role->is_system);
        // Project approval is never copied (it belongs to Aprobador de proyectos).
        $this->assertEqualsCanonicalizing(['ai.costs.view', 'ai.requests.create', 'companies.view_all'], $role->permissions->pluck('name')->all());
        $this->assertTrue(Activity::query()->where('event', 'role_created')->where('subject_id', $role->id)->exists());

        $this->actingAs($admin)
            ->get(route('admin.roles.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('roles', 6)
                ->where('roles.5', [
                    'name' => 'Auditor',
                    'label' => 'Auditor',
                    'scope' => 'Global',
                    'permissions' => ['ai.costs.view', 'ai.requests.create', 'companies.view_all'],
                    'system' => false,
                ]));

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('role_options.5', ['name' => 'Auditor', 'label' => 'Auditor', 'description' => 'Rol agregado en Roles y permisos', 'requires_area' => false]));
    }

    public function test_store_without_copy_starts_empty_and_validates(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.roles.store'), ['label' => 'Soporte', 'scope' => 'Empresa y área', 'copy_from' => null])
            ->assertSessionHasNoErrors();

        $role = Role::findByName('Soporte');
        $this->assertSame('company_area', $role->scope);
        $this->assertCount(0, $role->permissions);

        $this->actingAs($admin)
            ->postJson(route('admin.roles.store'), ['label' => 'Líder IA', 'scope' => 'Mundo', 'copy_from' => 'Dios'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label', 'scope', 'copy_from']);
    }
}
