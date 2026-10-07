<?php

namespace Tests\Feature\Permissions;

use App\Actions\Users\GrantVaultAccess;
use App\Models\Exceptions\ExclusivePermissionException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class VaultAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        Permission::withExclusiveGrant(fn () => $owner->givePermissionTo('ai.vault.access'));

        return $owner;
    }

    public function test_role_cannot_receive_the_vault_permission(): void
    {
        $this->expectException(ExclusivePermissionException::class);

        Role::findByName('Súper Admin')->givePermissionTo('ai.vault.access');
    }

    public function test_role_sync_cannot_include_the_vault_permission(): void
    {
        $role = Role::findByName('Líder IA');

        try {
            $role->syncPermissions(['ai.manage', 'ai.vault.access']);
            $this->fail('Expected ExclusivePermissionException.');
        } catch (ExclusivePermissionException) {
            // Nothing was detached either.
            $this->assertTrue($role->fresh()->hasPermissionTo('assistant.use'));
        }
    }

    public function test_vault_permission_cannot_be_assigned_to_a_role_from_the_permission_side(): void
    {
        $this->expectException(ExclusivePermissionException::class);

        Permission::findByName('ai.vault.access')->assignRole('Súper Admin');
    }

    public function test_direct_grant_outside_the_action_is_rejected(): void
    {
        $this->expectException(ExclusivePermissionException::class);

        User::factory()->create()->givePermissionTo('ai.vault.access');
    }

    public function test_super_admin_does_not_get_vault_access(): void
    {
        $admin = tap(User::factory()->create())->assignRole('Súper Admin');

        $this->assertTrue($admin->can('roles.manage'));
        $this->assertFalse($admin->can('ai.vault.access'));
    }

    public function test_actor_without_vault_access_cannot_grant_it(): void
    {
        $actor = tap(User::factory()->create())->assignRole('Súper Admin');
        $target = User::factory()->create();

        try {
            app(GrantVaultAccess::class)->handle($actor, $target);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertFalse($target->fresh()->can('ai.vault.access'));
        }
    }

    public function test_vault_owner_grants_access_and_it_is_logged(): void
    {
        $owner = $this->owner();
        $target = User::factory()->create();

        app(GrantVaultAccess::class)->handle($owner, $target);

        $this->assertTrue($target->fresh()->can('ai.vault.access'));

        $activity = Activity::query()->where('event', 'vault_access_granted')->sole();
        $this->assertTrue($activity->causer->is($owner));
        $this->assertTrue($activity->subject->is($target));
    }
}
