<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $permissions = Permission::count();
        $roles = Role::count();
        $pivot = Role::query()->withCount('permissions')->pluck('permissions_count', 'name')->all();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($permissions, Permission::count());
        $this->assertSame($roles, Role::count());
        $this->assertSame($pivot, Role::query()->withCount('permissions')->pluck('permissions_count', 'name')->all());
        $this->assertSame(count(config('permissions.permissions')), $permissions);
        $this->assertSame(array_keys(config('permissions.roles')), Role::query()->orderBy('id')->pluck('name')->all());
    }

    public function test_database_seeder_seeds_roles_with_model_events_muted(): void
    {
        $this->seed();

        $this->assertSame(['ai.requests.create'], Role::findByName('Usuario final')->permissions->pluck('name')->all());
    }

    public function test_every_permission_referenced_by_modules_and_routes_exists(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $referenced = collect(config('modules'))->pluck('permission')->filter();

        foreach (Route::getRoutes() as $route) {
            /** @var RouteDefinition $route */
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && Str::startsWith($middleware, 'can:')) {
                    $referenced->push(Str::before(Str::after($middleware, 'can:'), ','));
                }
            }
        }

        $this->assertContains('roles.manage', $referenced->all());

        $existing = Permission::query()->pluck('name')->all();

        foreach ($referenced->unique() as $permission) {
            $this->assertContains($permission, $existing, "Permission [{$permission}] is referenced but not seeded.");
            $this->assertArrayHasKey($permission, config('permissions.permissions'));
        }
    }

    public function test_every_permission_belongs_to_a_known_module_and_has_a_label(): void
    {
        foreach (config('permissions.permissions') as $name => $definition) {
            $this->assertArrayHasKey($definition['module'], config('modules'), $name);
            $this->assertNotEmpty($definition['label'], $name);
        }
    }

    public function test_super_admin_has_everything_except_the_vault_and_project_approval(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $expected = collect(array_keys(config('permissions.permissions')))
            ->reject(fn (string $name) => in_array($name, ['ai.vault.access', 'ai.projects.approve'], true))
            ->sort()->values()->all();

        $actual = Role::findByName('Súper Admin')->permissions->pluck('name')->sort()->values()->all();

        $this->assertSame($expected, $actual);
        $this->assertNotContains('ai.vault.access', $actual);
        $this->assertNotContains('ai.projects.approve', $actual);
    }

    public function test_project_approval_is_only_in_the_project_approver_role(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(
            ['Aprobador de proyectos'],
            Permission::findByName('ai.projects.approve')->roles->pluck('name')->all(),
        );
        $this->assertSame(['ai.projects.approve'], Permission::explicitNames());
    }

    public function test_reserved_operations_module_replaces_automations(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertArrayHasKey('operations.view', config('permissions.permissions'));
        $this->assertArrayNotHasKey('automations.view', config('permissions.permissions'));
        $this->assertSame('operations', config('permissions.permissions')['operations.view']['module']);
        $this->assertContains('operations.view', Role::findByName('Súper Admin')->permissions->pluck('name'));
    }

    public function test_no_role_holds_the_vault_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(0, Permission::findByName('ai.vault.access')->roles()->count());
    }

    public function test_end_user_only_creates_requests(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(
            ['ai.requests.create'],
            Role::findByName('Usuario final')->permissions->pluck('name')->all(),
        );
    }

    public function test_credentials_permissions_are_only_in_super_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['credentials.view', 'assistant.credentials'] as $permission) {
            $this->assertSame(
                ['Súper Admin'],
                Permission::findByName($permission)->roles->pluck('name')->all(),
            );
        }
    }

    public function test_other_roles_match_their_definition(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['ai.requests.create', 'ai.requests.approve_area', 'ai.area.view'],
            Role::findByName('Aprobador de área')->permissions->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['ai.requests.create', 'ai.projects.approve', 'ai.costs.view', 'companies.view_all'],
            Role::findByName('Aprobador de proyectos')->permissions->pluck('name')->all(),
        );

        $leader = Role::findByName('Líder IA')->permissions->pluck('name');
        $this->assertContains('ai.manage', $leader);
        $this->assertContains('assistant.knowledge.manage', $leader);
        $this->assertContains('companies.view_all', $leader);
        // Colaboradores (`employees.index`); create/sync come with `ai.manage`.
        $this->assertContains('employees.view', $leader);
        $this->assertNotContains('employees.manage', $leader);
        $this->assertNotContains('credentials.view', $leader);
        $this->assertNotContains('ai.vault.access', $leader);
        $this->assertNotContains('ai.projects.approve', $leader);
        $this->assertNotContains('assistant.credentials', $leader);
        $this->assertNotContains('admin.access', $leader);
    }
}
