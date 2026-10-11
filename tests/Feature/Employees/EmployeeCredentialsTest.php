<?php

namespace Tests\Feature\Employees;

use App\Models\AccessSystem;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAccess;
use App\Models\Permission;
use App\Models\User;
use App\Services\Credentials\EmployeeCredentialService;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\AccessSystemSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class EmployeeCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'S3cr3t-Pl@in-Value';

    private Company $company;

    private Employee $employee;

    private AccessSystem $office;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['credentials.view', 'companies.view_all'] as $permission) {
            Permission::create(['name' => $permission]);
        }

        $this->company = Company::factory()->create();
        $this->employee = Employee::factory()->for($this->company)->create(['name' => 'Juan Pérez']);
        $this->office = AccessSystem::factory()->create(['name' => 'Office 365']);
    }

    private function access(array $overrides = []): EmployeeAccess
    {
        return EmployeeAccess::create(array_merge([
            'employee_id' => $this->employee->id,
            'access_system_id' => $this->office->id,
            'username' => 'juan.perez',
            'password' => self::SECRET,
        ], $overrides));
    }

    private function viewer(bool $global = true, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => ($company ?? $this->company)->id]);
        $user->givePermissionTo($global ? ['credentials.view', 'companies.view_all'] : ['credentials.view']);

        return $user;
    }

    public function test_access_system_seeder_creates_the_default_systems(): void
    {
        $this->seed(AccessSystemSeeder::class);
        $this->seed(AccessSystemSeeder::class);

        foreach (['Office 365', 'SAP Business One', 'VPN'] as $name) {
            $this->assertSame(1, AccessSystem::where('name', $name)->count());
        }
    }

    public function test_password_is_encrypted_at_rest_and_never_serialized(): void
    {
        $access = $this->access();

        $raw = DB::table('employee_accesses')->where('id', $access->id)->value('password');
        $this->assertNotSame(self::SECRET, $raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertSame(self::SECRET, $access->fresh()->password);

        $this->assertArrayNotHasKey('password', $access->fresh()->toArray());
        $this->assertStringNotContainsString(self::SECRET, $access->fresh()->toJson());
        $this->assertNotNull($access->last_rotated_at);
    }

    public function test_activity_log_never_contains_the_password_and_records_rotation(): void
    {
        $access = $this->access();
        $raw = DB::table('employee_accesses')->where('id', $access->id)->value('password');

        $this->travel(5)->minutes();
        $access->update(['password' => 'Another-Plain-Value']);
        $access->update(['notes' => 'Cambiada por soporte']);

        $activities = Activity::where('subject_type', $access->getMorphClass())->get();
        $this->assertCount(3, $activities);

        $json = $activities->pluck('properties')->toJson();
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString('Another-Plain-Value', $json);
        $this->assertStringNotContainsString($raw, $json);
        $this->assertStringNotContainsString('"password"', $json);

        $rotation = $activities[1];
        $this->assertTrue($rotation->properties['password_changed']);
        $this->assertArrayHasKey('last_rotated_at', $rotation->properties['attributes']);
        $this->assertArrayNotHasKey('password_changed', $activities[2]->properties->all());
    }

    public function test_reveal_requires_credentials_view(): void
    {
        $access = $this->access();
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $this->expectException(AuthorizationException::class);

        try {
            app(EmployeeCredentialService::class)->reveal($user, $access, 'web');
        } finally {
            $this->assertSame(0, Activity::where('event', 'credential_revealed')->count());
        }
    }

    public function test_reveal_returns_the_value_and_logs_who_what_and_channel(): void
    {
        $access = $this->access();
        $viewer = $this->viewer();

        $value = app(EmployeeCredentialService::class)->reveal($viewer, $access, 'telegram');

        $this->assertSame(self::SECRET, $value);

        $activity = Activity::where('event', 'credential_revealed')->sole();
        $this->assertSame('credentials', $activity->log_name);
        $this->assertSame($viewer->id, $activity->causer_id);
        $this->assertSame($access->id, $activity->subject_id);
        $this->assertSame('telegram', $activity->properties['channel']);
        $this->assertSame($this->employee->id, $activity->properties['employee_id']);
        $this->assertSame('Juan Pérez', $activity->properties['employee_name']);
        $this->assertSame('Office 365', $activity->properties['access_system']);
        $this->assertSame('juan.perez', $activity->properties['username']);
        $this->assertStringNotContainsString(self::SECRET, $activity->properties->toJson());
    }

    public function test_reveal_rejects_unknown_channels(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(EmployeeCredentialService::class)->reveal($this->viewer(), $this->access(), 'email');
    }

    public function test_restricted_viewer_cannot_reveal_accesses_of_another_company(): void
    {
        $access = $this->access();
        $viewer = $this->viewer(global: false, company: Company::factory()->create());

        $this->expectException(AuthorizationException::class);

        app(EmployeeCredentialService::class)->reveal($viewer, $access, 'web');
    }

    public function test_find_for_delivery_returns_candidates_without_passwords(): void
    {
        $this->access();
        $other = Employee::factory()->for($this->company)->create(['name' => 'Juan Gómez']);
        $this->access(['employee_id' => $other->id, 'username' => 'juan.gomez']);
        $vpn = AccessSystem::factory()->create(['name' => 'VPN']);
        $this->access(['access_system_id' => $vpn->id, 'username' => 'jperez-vpn']);
        Employee::factory()->for($this->company)->inactive()->create(['name' => 'Juan Inactivo']);

        $service = app(EmployeeCredentialService::class);

        $candidates = $service->findForDelivery('juan', 'office');
        $this->assertCount(2, $candidates);
        $this->assertEqualsCanonicalizing(['juan.perez', 'juan.gomez'], $candidates->pluck('username')->all());

        foreach ($candidates as $candidate) {
            $this->assertNull($candidate->getAttributes()['password'] ?? null);
            $this->assertStringNotContainsString(self::SECRET, json_encode($candidate));
            $this->assertNotNull($candidate->employee);
            $this->assertNotNull($candidate->system);
        }

        $exact = $service->findForDelivery('juan pérez', 'vpn');
        $this->assertCount(1, $exact);
        $this->assertSame('jperez-vpn', $exact->first()->username);

        $this->assertCount(0, $service->findForDelivery('', 'office'));
    }

    public function test_find_for_delivery_honours_the_company_context(): void
    {
        $this->access();
        $other = Company::factory()->create();
        $foreign = Employee::factory()->for($other)->create(['name' => 'Juan Ajeno']);
        $this->access(['employee_id' => $foreign->id, 'username' => 'juan.ajeno']);

        $service = app(EmployeeCredentialService::class);

        $this->assertCount(2, $service->findForDelivery('juan', 'office'));

        $scoped = app(CurrentCompany::class)->runAs($this->company->id, fn () => $service->findForDelivery('juan', 'office'));
        $this->assertSame(['juan.perez'], $scoped->pluck('username')->all());
    }
}
