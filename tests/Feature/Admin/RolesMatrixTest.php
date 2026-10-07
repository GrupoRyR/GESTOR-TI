<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

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
        return tap(User::factory()->create())->assignRole('Súper Admin');
    }

    public function test_index_requires_roles_manage(): void
    {
        $user = tap(User::factory()->create())->givePermissionTo('admin.access');

        $this->actingAs($user)->get(route('admin.roles.index'))->assertForbidden();
    }

    public function test_index_returns_the_matrix(): void
    {
        $admin = $this->admin();
        tap(User::factory()->create())->assignRole('Usuario final');
        tap(User::factory()->create())->assignRole('Usuario final');

        $this->actingAs($admin)
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roles/Index', false)
                ->has('roles', count(config('permissions.roles')))
                ->has('roles.0', fn (Assert $role) => $role
                    ->where('name', 'Súper Admin')
                    ->where('users_count', 1)
                    ->whereType('id', 'integer')
                    ->whereType('permissions', 'array')
                )
                ->where('roles.4.name', 'Usuario final')
                ->where('roles.4.users_count', 2)
                ->where('roles.4.permissions', ['ai.requests.create'])
                ->has('permissions', count(config('permissions.permissions')))
                ->has('permissions.0', fn (Assert $permission) => $permission
                    ->where('name', 'admin.access')
                    ->whereType('label', 'string')
                    ->where('module', 'admin')
                    ->where('exclusive', false)
                )
                ->where('permissions', fn ($permissions) => collect($permissions)
                    ->firstWhere('name', 'ai.vault.access')['exclusive'] === true)
                ->has('modules', count(config('modules')))
                ->has('modules.0', fn (Assert $module) => $module
                    ->where('key', 'ai')
                    ->where('name', 'Gestor IA')
                )
            );
    }

    public function test_update_syncs_permissions_and_logs_the_change(): void
    {
        $admin = $this->admin();
        $role = Role::findByName('Usuario final');

        $this->actingAs($admin)
            ->from(route('admin.roles.index'))
            ->put(route('admin.roles.update', $role), [
                'permissions' => ['ai.requests.create', 'assistant.use'],
            ])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHas('success', 'Permisos del rol actualizados.');

        $this->assertEqualsCanonicalizing(
            ['ai.requests.create', 'assistant.use'],
            $role->fresh()->permissions->pluck('name')->all(),
        );

        $activity = Activity::query()->where('event', 'role_permissions_updated')->sole();
        $this->assertTrue($activity->causer->is($admin));
        $this->assertTrue($activity->subject->is($role));
        $this->assertSame(['ai.requests.create'], $activity->properties['old']['permissions']);
        $this->assertSame(['ai.requests.create', 'assistant.use'], $activity->properties['attributes']['permissions']);
    }

    public function test_update_can_clear_all_permissions(): void
    {
        $role = Role::findByName('Usuario final');

        $this->actingAs($this->admin())
            ->putJson(route('admin.roles.update', $role), ['permissions' => []])
            ->assertRedirect();

        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_vault_permission_cannot_be_attached_to_any_role(): void
    {
        $admin = $this->admin();

        foreach (Role::all() as $role) {
            $this->actingAs($admin)
                ->putJson(route('admin.roles.update', $role), [
                    'permissions' => ['ai.requests.create', 'ai.vault.access'],
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['permissions.1']);

            $this->assertNotContains('ai.vault.access', $role->fresh()->permissions->pluck('name'));
        }
    }

    public function test_unknown_permissions_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->putJson(route('admin.roles.update', Role::findByName('Usuario final')), [
                'permissions' => ['does.not.exist'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_update_requires_roles_manage(): void
    {
        $user = tap(User::factory()->create())->assignRole('Líder IA');

        $this->actingAs($user)
            ->put(route('admin.roles.update', Role::findByName('Usuario final')), ['permissions' => []])
            ->assertForbidden();
    }

    public function test_user_roles_update_syncs_and_logs(): void
    {
        $admin = $this->admin();
        $target = tap(User::factory()->create())->assignRole('Usuario final');

        $this->actingAs($admin)
            ->from(route('admin.roles.index'))
            ->put(route('admin.users.roles.update', $target), [
                'roles' => ['Usuario final', 'Aprobador de área'],
            ])
            ->assertRedirect(route('admin.roles.index'))
            ->assertSessionHas('success', 'Roles del usuario actualizados.');

        $this->assertEqualsCanonicalizing(['Usuario final', 'Aprobador de área'], $target->fresh()->getRoleNames()->all());

        $activity = Activity::query()->where('event', 'user_roles_updated')->sole();
        $this->assertTrue($activity->causer->is($admin));
        $this->assertSame(['Usuario final'], $activity->properties['old']['roles']);
    }

    public function test_user_roles_update_rejects_unknown_roles(): void
    {
        $this->actingAs($this->admin())
            ->putJson(route('admin.users.roles.update', User::factory()->create()), ['roles' => ['Dios']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['roles.0']);
    }

    public function test_user_roles_update_requires_users_manage(): void
    {
        $user = tap(User::factory()->create())->givePermissionTo(['admin.access', 'roles.manage']);

        $this->actingAs($user)
            ->put(route('admin.users.roles.update', User::factory()->create()), ['roles' => []])
            ->assertForbidden();
    }
}
