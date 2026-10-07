<?php

namespace Tests\Feature\Permissions;

use App\Actions\Users\AssignInitialRoles;
use App\Models\User;
use Database\Seeders\InitialUsersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignInitialRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gestor.initial_users.super_admins' => ['admin.one@example.com', 'admin.two@example.com'],
            'gestor.initial_users.project_approvers' => ['admin.one@example.com', 'approver@example.com'],
            'gestor.initial_users.vault_owner' => 'admin.one@example.com',
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_super_admin_email_gets_role_and_global_flag(): void
    {
        $user = User::factory()->create(['email' => 'Admin.Two@Example.com']);

        app(AssignInitialRoles::class)->handle($user);

        $user->refresh();
        $this->assertTrue($user->hasRole('Súper Admin'));
        $this->assertTrue($user->is_global);
        $this->assertFalse($user->hasRole('Usuario final'));
        $this->assertFalse($user->can('ai.vault.access'));
    }

    public function test_vault_owner_gets_direct_vault_permission(): void
    {
        $user = User::factory()->create(['email' => 'admin.one@example.com']);

        app(AssignInitialRoles::class)->handle($user);

        $user->refresh();
        $this->assertTrue($user->hasRole('Súper Admin'));
        $this->assertTrue($user->hasRole('Aprobador de proyectos'));
        $this->assertTrue($user->hasDirectPermission('ai.vault.access'));
    }

    public function test_project_approver_gets_role(): void
    {
        $user = User::factory()->create(['email' => 'approver@example.com']);

        app(AssignInitialRoles::class)->handle($user);

        $this->assertSame(['Aprobador de proyectos'], $user->fresh()->getRoleNames()->all());
        $this->assertFalse($user->fresh()->is_global);
    }

    public function test_unknown_email_gets_end_user(): void
    {
        $user = User::factory()->create(['email' => 'someone@example.com']);

        app(AssignInitialRoles::class)->handle($user);

        $this->assertSame(['Usuario final'], $user->fresh()->getRoleNames()->all());
        $this->assertFalse($user->fresh()->is_global);
    }

    public function test_initial_users_seeder_is_safe_to_repeat_and_only_touches_existing_users(): void
    {
        $owner = User::factory()->create(['email' => 'admin.one@example.com']);
        $other = User::factory()->create(['email' => 'someone@example.com']);

        $this->seed(InitialUsersSeeder::class);
        $this->seed(InitialUsersSeeder::class);

        $this->assertSame(2, User::count());
        $this->assertEqualsCanonicalizing(['Súper Admin', 'Aprobador de proyectos'], $owner->fresh()->getRoleNames()->all());
        $this->assertSame(['Usuario final'], $other->fresh()->getRoleNames()->all());
        $this->assertSame(['ai.vault.access'], $owner->fresh()->getDirectPermissions()->pluck('name')->all());
    }

    public function test_config_parses_comma_separated_emails(): void
    {
        $keys = [
            'GESTOR_SUPER_ADMIN_EMAILS' => ' One@Example.com, two@example.com ,,',
            'GESTOR_PROJECT_APPROVER_EMAILS' => '',
            'GESTOR_VAULT_OWNER_EMAIL' => ' Owner@Example.com ',
        ];

        foreach ($keys as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            $config = require config_path('gestor.php');
        } finally {
            foreach (array_keys($keys) as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }

        $this->assertSame(['one@example.com', 'two@example.com'], $config['initial_users']['super_admins']);
        $this->assertSame([], $config['initial_users']['project_approvers']);
        $this->assertSame('owner@example.com', $config['initial_users']['vault_owner']);
    }
}
