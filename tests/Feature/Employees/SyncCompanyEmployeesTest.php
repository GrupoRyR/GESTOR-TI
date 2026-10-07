<?php

namespace Tests\Feature\Employees;

use App\Jobs\SyncCompanyEmployees;
use App\Models\Area;
use App\Models\Company;
use App\Models\DepartmentAreaMapping;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SyncCompanyEmployeesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Graph users served per tenant id.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $directory = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => 'client-secret',
        ]);

        Sleep::fake();

        Http::fake(function (Request $request) {
            if (preg_match('#login\.microsoftonline\.com/([^/]+)/#', $request->url(), $match)) {
                return Http::response(['access_token' => 'token-'.$match[1], 'expires_in' => 3600]);
            }

            $tenant = substr($request->header('Authorization')[0] ?? '', strlen('Bearer token-'));

            return Http::response(['value' => $this->directory[$tenant] ?? []]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function graphUser(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'displayName' => 'User '.$id,
            'mail' => $id.'@segurex.com',
            'userPrincipalName' => $id.'@segurex.com',
            'jobTitle' => 'Analista',
            'department' => null,
            'accountEnabled' => true,
            'userType' => 'Member',
        ], $overrides);
    }

    private function company(string $tenant): Company
    {
        return Company::factory()->create(['tenant_id' => $tenant]);
    }

    /**
     * @return array{created: int, updated: int, deactivated: int}
     */
    private function sync(Company $company): array
    {
        return app()->call([new SyncCompanyEmployees($company->id), 'handle']);
    }

    private function employee(Company $company, string $azureId): Employee
    {
        return Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('azure_id', $azureId)
            ->firstOrFail();
    }

    public function test_job_is_queued_with_three_tries(): void
    {
        $job = new SyncCompanyEmployees(1);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(3, $job->tries);
    }

    public function test_creates_employees_and_skips_guests(): void
    {
        $company = $this->company('tenant-a');
        $this->directory['tenant-a'] = [
            $this->graphUser('ana', ['displayName' => 'Ana Pérez', 'mail' => 'Ana.Perez@Segurex.com', 'jobTitle' => 'Contadora', 'department' => 'Contabilidad']),
            $this->graphUser('luis', ['mail' => null, 'userPrincipalName' => 'luis@segurex.com']),
            $this->graphUser('guest', ['userType' => 'Guest']),
        ];

        $summary = $this->sync($company);

        $this->assertSame(['created' => 2, 'updated' => 0, 'deactivated' => 0], $summary);

        $ana = $this->employee($company, 'ana');
        $this->assertSame('Ana Pérez', $ana->name);
        $this->assertSame('ana.perez@segurex.com', $ana->email);
        $this->assertSame('Contadora', $ana->job_title);
        $this->assertSame('Contabilidad', $ana->department);
        $this->assertSame(Employee::SOURCE_GRAPH, $ana->source);
        $this->assertSame(Employee::STATUS_ACTIVE, $ana->status);
        $this->assertNotNull($ana->last_synced_at);

        $this->assertSame('luis@segurex.com', $this->employee($company, 'luis')->email);
        $this->assertFalse(Employee::withoutGlobalScopes()->where('azure_id', 'guest')->exists());
    }

    public function test_updates_existing_employees_and_counts_only_real_changes(): void
    {
        $company = $this->company('tenant-a');
        $this->directory['tenant-a'] = [$this->graphUser('ana'), $this->graphUser('luis')];
        $this->sync($company);

        $this->directory['tenant-a'] = [
            $this->graphUser('ana', ['jobTitle' => 'Jefe de cartera']),
            $this->graphUser('luis'),
        ];

        $this->assertSame(['created' => 0, 'updated' => 1, 'deactivated' => 0], $this->sync($company));
        $this->assertSame('Jefe de cartera', $this->employee($company, 'ana')->job_title);
        $this->assertSame(2, Employee::withoutGlobalScopes()->count());
    }

    public function test_deactivates_disabled_and_missing_graph_employees_but_not_manual_ones(): void
    {
        $company = $this->company('tenant-a');
        $manual = Employee::factory()->for($company)->create();
        $this->directory['tenant-a'] = [$this->graphUser('ana'), $this->graphUser('luis'), $this->graphUser('eva')];
        $this->sync($company);

        $this->directory['tenant-a'] = [
            $this->graphUser('ana', ['accountEnabled' => false]),
            $this->graphUser('luis'),
        ];

        $this->assertSame(['created' => 0, 'updated' => 0, 'deactivated' => 2], $this->sync($company));
        $this->assertSame(Employee::STATUS_INACTIVE, $this->employee($company, 'ana')->status);
        $this->assertSame(Employee::STATUS_INACTIVE, $this->employee($company, 'eva')->status);
        $this->assertSame(Employee::STATUS_ACTIVE, $this->employee($company, 'luis')->status);
        $this->assertSame(Employee::STATUS_ACTIVE, $manual->fresh()->status);

        // Re-enabled in Microsoft: active again.
        $this->directory['tenant-a'] = [$this->graphUser('ana'), $this->graphUser('luis')];
        $this->assertSame(['created' => 0, 'updated' => 1, 'deactivated' => 0], $this->sync($company));
        $this->assertSame(Employee::STATUS_ACTIVE, $this->employee($company, 'ana')->status);
    }

    public function test_empty_directory_does_not_deactivate_everybody(): void
    {
        $company = $this->company('tenant-a');
        $this->directory['tenant-a'] = [$this->graphUser('ana')];
        $this->sync($company);

        $this->directory['tenant-a'] = [];

        $this->assertSame(['created' => 0, 'updated' => 0, 'deactivated' => 0], $this->sync($company));
        $this->assertSame(Employee::STATUS_ACTIVE, $this->employee($company, 'ana')->status);
    }

    public function test_resolves_managers_in_a_second_pass(): void
    {
        $company = $this->company('tenant-a');
        $this->directory['tenant-a'] = [
            // The report comes before the manager in the listing.
            $this->graphUser('luis', ['manager' => ['id' => 'ana']]),
            $this->graphUser('ana', ['manager' => ['id' => 'outside-directory']]),
        ];

        $this->sync($company);

        $ana = $this->employee($company, 'ana');
        $luis = $this->employee($company, 'luis');
        $this->assertSame('ana', $luis->manager_azure_id);
        $this->assertSame($ana->id, $luis->manager_id);
        $this->assertSame('outside-directory', $ana->manager_azure_id);
        $this->assertNull($ana->manager_id);
    }

    public function test_area_comes_from_department_mapping_unless_locked(): void
    {
        $company = $this->company('tenant-a');
        $contabilidad = Area::factory()->for($company)->create(['name' => 'Contabilidad']);
        $ventas = Area::factory()->for($company)->create(['name' => 'Ventas']);
        DepartmentAreaMapping::factory()->forArea($contabilidad)->create(['department' => 'Contabilidad y Tesorería']);

        $this->directory['tenant-a'] = [
            $this->graphUser('ana', ['department' => '  CONTABILIDAD   y tesoreria ']),
            $this->graphUser('luis', ['department' => 'Contabilidad y Tesorería']),
            $this->graphUser('eva', ['department' => 'Sin mapeo']),
        ];
        $this->sync($company);

        $this->assertSame($contabilidad->id, $this->employee($company, 'ana')->area_id);
        $this->assertNull($this->employee($company, 'eva')->area_id);

        $luis = $this->employee($company, 'luis');
        $luis->assignArea($ventas->id);
        $this->assertTrue($luis->fresh()->area_locked);

        $this->sync($company);

        $this->assertSame($ventas->id, $this->employee($company, 'luis')->area_id);
        $this->assertSame($contabilidad->id, $this->employee($company, 'ana')->area_id);
    }

    public function test_links_users_of_the_same_company_by_email(): void
    {
        $company = $this->company('tenant-a');
        $other = $this->company('tenant-b');
        $user = User::factory()->create(['email' => 'ana@segurex.com', 'company_id' => $company->id]);
        User::factory()->create(['email' => 'luis@segurex.com', 'company_id' => $other->id]);

        $this->directory['tenant-a'] = [
            $this->graphUser('ana', ['mail' => 'ANA@segurex.com']),
            $this->graphUser('luis'),
        ];
        $this->sync($company);

        $this->assertSame($user->id, $this->employee($company, 'ana')->user_id);
        $this->assertNull($this->employee($company, 'luis')->user_id);
    }

    public function test_sync_is_isolated_per_company(): void
    {
        $a = $this->company('tenant-a');
        $b = $this->company('tenant-b');
        $c = $this->company('tenant-c');
        $this->directory['tenant-a'] = [$this->graphUser('ana')];
        $this->directory['tenant-b'] = [$this->graphUser('shared'), $this->graphUser('luis')];
        $this->directory['tenant-c'] = [$this->graphUser('shared')];

        $this->sync($a);
        $this->sync($b);
        $this->sync($c);

        // Same Azure id in two tenants: two employees, one per company.
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('company_id', $a->id)->count());
        $this->assertSame(2, Employee::withoutGlobalScopes()->where('company_id', $b->id)->count());
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('company_id', $c->id)->count());

        // A later sync of A never deactivates B or C.
        $this->directory['tenant-a'] = [$this->graphUser('other')];
        $this->sync($a);

        $this->assertSame(Employee::STATUS_INACTIVE, $this->employee($a, 'ana')->status);
        $this->assertSame(Employee::STATUS_ACTIVE, $this->employee($b, 'shared')->status);
        $this->assertSame(Employee::STATUS_ACTIVE, $this->employee($c, 'shared')->status);
        $this->assertSame(Employee::STATUS_ACTIVE, $this->employee($b, 'luis')->status);
    }

    public function test_logs_the_summary_in_the_activity_log(): void
    {
        $company = $this->company('tenant-a');
        $this->directory['tenant-a'] = [$this->graphUser('ana')];

        $this->sync($company);

        $activity = Activity::where('event', 'employees_synced')->sole();
        $this->assertSame('employees', $activity->log_name);
        $this->assertSame($company->id, $activity->subject_id);
        $this->assertSame(['created' => 1, 'updated' => 0, 'deactivated' => 0], $activity->properties->only(['created', 'updated', 'deactivated'])->all());
    }

    public function test_inactive_company_or_without_tenant_is_skipped(): void
    {
        $inactive = Company::factory()->inactive()->create(['tenant_id' => 'tenant-a']);
        $noTenant = Company::factory()->create(['tenant_id' => null]);
        $this->directory['tenant-a'] = [$this->graphUser('ana')];

        $this->assertSame(['created' => 0, 'updated' => 0, 'deactivated' => 0], $this->sync($inactive));
        $this->assertSame(['created' => 0, 'updated' => 0, 'deactivated' => 0], $this->sync($noTenant));
        Http::assertNothingSent();
    }

    public function test_runs_through_the_queue(): void
    {
        $company = $this->company('tenant-a');
        $this->directory['tenant-a'] = [$this->graphUser('ana')];

        SyncCompanyEmployees::dispatch($company->id);

        $this->assertTrue(Employee::withoutGlobalScopes()->where('azure_id', 'ana')->exists());
    }
}
