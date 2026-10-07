<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\LoginWithMicrosoft;
use App\Actions\Auth\MicrosoftIdentity;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginLinksEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gestor.initial_users.super_admins' => [],
            'gestor.initial_users.project_approvers' => [],
            'gestor.initial_users.vault_owner' => null,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::factory()->create([
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'domains' => ['segurex.com'],
        ]);
    }

    private function login(string $azureId = 'azure-ana', string $email = 'ana@segurex.com'): User
    {
        return app(LoginWithMicrosoft::class)->handle(new MicrosoftIdentity(
            azureId: $azureId,
            email: $email,
            name: 'Ana Pérez',
            tenantId: '11111111-1111-1111-1111-111111111111',
        ));
    }

    public function test_links_the_employee_with_the_same_azure_id(): void
    {
        $employee = Employee::factory()->for($this->company)->fromGraph('azure-ana')->create(['email' => 'otra@segurex.com']);

        $user = $this->login();

        $this->assertSame($user->id, $employee->fresh()->user_id);
    }

    public function test_links_the_employee_by_email_when_there_is_no_azure_match(): void
    {
        $employee = Employee::factory()->for($this->company)->create(['email' => 'ANA@segurex.com']);

        $user = $this->login();

        $this->assertSame($user->id, $employee->fresh()->user_id);
    }

    public function test_does_not_link_employees_of_another_company_or_already_linked(): void
    {
        $other = Company::factory()->create();
        $foreign = Employee::factory()->for($other)->fromGraph('azure-ana')->create(['email' => 'ana@segurex.com']);
        $someone = User::factory()->create();
        $taken = Employee::factory()->for($this->company)->create(['email' => 'ana@segurex.com', 'user_id' => $someone->id]);

        $this->login();

        $this->assertNull($foreign->fresh()->user_id);
        $this->assertSame($someone->id, $taken->fresh()->user_id);
    }

    public function test_login_without_employee_still_works_and_is_idempotent(): void
    {
        $user = $this->login();
        $this->assertNotNull($user->id);

        $employee = Employee::factory()->for($this->company)->create(['email' => 'ana@segurex.com']);
        $this->login();
        $this->login();

        $this->assertSame($user->id, $employee->fresh()->user_id);
    }
}
