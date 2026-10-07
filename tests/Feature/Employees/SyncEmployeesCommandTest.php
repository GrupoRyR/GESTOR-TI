<?php

namespace Tests\Feature\Employees;

use App\Jobs\SyncCompanyEmployees;
use App\Models\Company;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncEmployeesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_only_active_companies_with_tenant(): void
    {
        Queue::fake();

        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $c = Company::factory()->create();
        $inactive = Company::factory()->inactive()->create();
        $noTenant = Company::factory()->create(['tenant_id' => null]);

        $this->artisan('gestor:sync-employees')->assertSuccessful();

        Queue::assertPushed(SyncCompanyEmployees::class, 3);

        foreach ([$a, $b, $c] as $company) {
            Queue::assertPushed(SyncCompanyEmployees::class, fn (SyncCompanyEmployees $job) => $job->companyId === $company->id);
        }

        foreach ([$inactive, $noTenant] as $company) {
            Queue::assertNotPushed(SyncCompanyEmployees::class, fn (SyncCompanyEmployees $job) => $job->companyId === $company->id);
        }
    }

    public function test_dispatches_one_company_by_id_or_slug(): void
    {
        Queue::fake();

        $a = Company::factory()->create(['slug' => 'segurex']);
        Company::factory()->create();

        $this->artisan('gestor:sync-employees', ['company' => 'segurex'])->assertSuccessful();
        $this->artisan('gestor:sync-employees', ['company' => (string) $a->id])->assertSuccessful();

        Queue::assertPushed(SyncCompanyEmployees::class, 2);
        Queue::assertPushed(SyncCompanyEmployees::class, fn (SyncCompanyEmployees $job) => $job->companyId === $a->id);
    }

    public function test_rejects_unknown_inactive_or_tenantless_company(): void
    {
        Queue::fake();

        Company::factory()->inactive()->create(['slug' => 'apagada']);
        Company::factory()->create(['slug' => 'sin-tenant', 'tenant_id' => null]);

        $this->artisan('gestor:sync-employees', ['company' => 'no-existe'])->assertFailed();
        $this->artisan('gestor:sync-employees', ['company' => 'apagada'])->assertFailed();
        $this->artisan('gestor:sync-employees', ['company' => 'sin-tenant'])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_is_scheduled_daily_at_five_bogota_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'gestor:sync-employees'));

        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertSame('0 5 * * *', $event->expression);
        $this->assertSame('America/Bogota', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}
