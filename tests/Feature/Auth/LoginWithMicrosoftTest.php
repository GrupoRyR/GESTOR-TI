<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\LoginWithMicrosoft;
use App\Actions\Auth\MicrosoftIdentity;
use App\Actions\Auth\MicrosoftLoginRejected;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginWithMicrosoftTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gestor.initial_users.super_admins' => ['jefe@segurex.com'],
            'gestor.initial_users.project_approvers' => [],
            'gestor.initial_users.vault_owner' => null,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::factory()->create([
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'domains' => ['segurex.com'],
        ]);
    }

    private function identity(array $overrides = []): MicrosoftIdentity
    {
        return new MicrosoftIdentity(...array_merge([
            'azureId' => 'azure-ana',
            'email' => 'Ana.Perez@Segurex.com',
            'name' => 'Ana Pérez',
            'tenantId' => '11111111-1111-1111-1111-111111111111',
        ], $overrides));
    }

    private function login(array $overrides = []): User
    {
        return app(LoginWithMicrosoft::class)->handle($this->identity($overrides));
    }

    public function test_active_tenant_creates_user_with_company_and_end_user_role(): void
    {
        $user = $this->login();

        $this->assertSame('ana.perez@segurex.com', $user->email);
        $this->assertSame('Ana Pérez', $user->name);
        $this->assertSame('azure-ana', $user->azure_id);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $user->tenant_id);
        $this->assertSame($this->company->id, $user->company_id);
        $this->assertNull($user->avatar_url);
        $this->assertNotNull($user->last_login_at);
        $this->assertTrue($user->hasRole('Usuario final'));
        $this->assertFalse(Hash::check('', $user->password));
    }

    public function test_configured_super_admin_email_gets_super_admin(): void
    {
        $user = $this->login(['azureId' => 'azure-jefe', 'email' => 'JEFE@segurex.com']);

        $this->assertTrue($user->fresh()->hasRole('Súper Admin'));
        $this->assertTrue($user->fresh()->is_global);
    }

    public function test_unknown_tenant_is_rejected_without_creating_user(): void
    {
        try {
            $this->login(['tenantId' => '99999999-9999-9999-9999-999999999999']);
            $this->fail('Expected rejection.');
        } catch (MicrosoftLoginRejected $e) {
            $this->assertSame('tenant_not_allowed', $e->reason);
        }

        $this->assertSame(0, User::count());
    }

    public function test_inactive_company_tenant_is_rejected(): void
    {
        $this->company->update(['is_active' => false]);

        $this->expectException(MicrosoftLoginRejected::class);

        try {
            $this->login();
        } finally {
            $this->assertSame(0, User::count());
        }
    }

    public function test_missing_tenant_is_rejected(): void
    {
        $this->expectException(MicrosoftLoginRejected::class);

        $this->login(['tenantId' => null]);
    }

    public function test_email_domain_outside_company_domains_is_rejected(): void
    {
        try {
            $this->login(['email' => 'intruso@gmail.com']);
            $this->fail('Expected rejection.');
        } catch (MicrosoftLoginRejected $e) {
            $this->assertSame('domain_not_allowed', $e->reason);
        }

        $this->assertSame(0, User::count());
    }

    public function test_external_guest_account_is_rejected(): void
    {
        $this->expectException(MicrosoftLoginRejected::class);

        $this->login(['external' => true]);
    }

    public function test_existing_user_matched_by_email_gets_azure_id_linked(): void
    {
        $existing = User::factory()->create(['email' => 'ana.perez@segurex.com', 'name' => 'Ana']);

        $user = $this->login();

        $this->assertSame($existing->id, $user->id);
        $this->assertSame('azure-ana', $user->fresh()->azure_id);
        $this->assertSame('Ana Pérez', $user->fresh()->name);
        $this->assertSame($this->company->id, $user->fresh()->company_id);
        $this->assertSame(1, User::count());
    }

    public function test_email_owned_by_another_azure_account_is_rejected(): void
    {
        $existing = User::factory()->create(['email' => 'ana.perez@segurex.com']);
        $existing->forceFill(['azure_id' => 'azure-other'])->save();

        $this->expectException(MicrosoftLoginRejected::class);

        $this->login();
    }

    public function test_second_login_does_not_duplicate_and_updates_profile(): void
    {
        $first = $this->login();
        $this->travel(1)->hours();

        $second = $this->login(['name' => 'Ana María Pérez']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, User::count());
        $this->assertSame('Ana María Pérez', $second->fresh()->name);
        $this->assertTrue($second->fresh()->last_login_at->gt($first->last_login_at));
        $this->assertSame(['Usuario final'], $second->fresh()->getRoleNames()->all());
    }
}
