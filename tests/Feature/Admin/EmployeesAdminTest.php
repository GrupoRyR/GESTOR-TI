<?php

namespace Tests\Feature\Admin;

use App\Jobs\SyncCompanyEmployees;
use App\Models\AccessSystem;
use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAccess;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class EmployeesAdminTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'Pl@in-Secret-123';

    private Company $segurex;

    private Company $derivados;

    private Area $ventas;

    private Employee $ana;

    private Employee $foreign;

    private AccessSystem $office;

    private EmployeeAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['employees.view', 'employees.manage', 'credentials.view', 'companies.view_all'] as $permission) {
            Permission::create(['name' => $permission]);
        }

        $this->segurex = Company::factory()->create();
        $this->derivados = Company::factory()->create();
        $this->ventas = Area::factory()->for($this->segurex)->create(['name' => 'Ventas']);
        Area::factory()->for($this->derivados)->create(['name' => 'Cartera']);

        $this->ana = Employee::factory()->for($this->segurex)->fromGraph('azure-ana')->create([
            'name' => 'Ana Pérez',
            'email' => 'ana@segurex.com',
            'area_id' => $this->ventas->id,
        ]);
        Employee::factory()->for($this->segurex)->inactive()->create(['name' => 'Luis Inactivo']);
        $this->foreign = Employee::factory()->for($this->derivados)->create(['name' => 'Eva Derivados']);

        $this->office = AccessSystem::factory()->create(['name' => 'Office 365']);
        $this->access = EmployeeAccess::create([
            'employee_id' => $this->ana->id,
            'access_system_id' => $this->office->id,
            'username' => 'ana@segurex.com',
            'password' => self::SECRET,
            'notes' => 'Licencia E3',
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function user(array $permissions, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => ($company ?? $this->segurex)->id]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function viewer(): User
    {
        return $this->user(['employees.view']);
    }

    private function manager(): User
    {
        return $this->user(['employees.view', 'employees.manage']);
    }

    // ---- index ------------------------------------------------------------

    public function test_index_requires_employees_view(): void
    {
        $this->actingAs($this->user([]))
            ->get(route('admin.employees.index'))
            ->assertForbidden();
    }

    public function test_index_returns_exact_props_and_honours_company_scope(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('admin.employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Employees/Index', false)
                ->has('employees', fn (Assert $employees) => $employees
                    ->has('data', 2)
                    ->has('data.0', fn (Assert $employee) => $employee
                        ->where('id', $this->ana->id)
                        ->where('name', 'Ana Pérez')
                        ->where('email', 'ana@segurex.com')
                        ->where('area', ['id' => $this->ventas->id, 'name' => 'Ventas'])
                        ->where('status', 'active')
                        ->where('source', 'graph')
                        ->where('user_id', null)
                        ->hasAll(['job_title', 'department', 'last_synced_at'])
                    )
                    ->has('links')
                    ->has('meta', fn (Assert $meta) => $meta
                        ->where('current_page', 1)
                        ->where('last_page', 1)
                        ->where('per_page', 25)
                        ->where('total', 2)
                    )
                )
                ->where('filters', ['search' => null, 'area_id' => null, 'status' => null])
                ->where('areas', [['id' => $this->ventas->id, 'name' => 'Ventas']])
            );
    }

    public function test_index_filters_by_search_area_and_status(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('admin.employees.index', ['search' => 'luis']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.name', 'Luis Inactivo')
                ->where('filters.search', 'luis')
            );

        $this->get(route('admin.employees.index', ['area_id' => $this->ventas->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $this->ana->id)
                ->where('filters.area_id', $this->ventas->id)
            );

        $this->get(route('admin.employees.index', ['status' => 'inactive']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.status', 'inactive')
                ->where('filters.status', 'inactive')
            );
    }

    public function test_global_user_sees_every_company(): void
    {
        $this->actingAs($this->user(['employees.view', 'companies.view_all']))
            ->get(route('admin.employees.index'))
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 3)->has('areas', 2));
    }

    public function test_index_and_show_never_expose_passwords(): void
    {
        $raw = DB::table('employee_accesses')->value('password');
        $user = $this->user(['employees.view', 'employees.manage', 'credentials.view']);

        foreach ([route('admin.employees.index'), route('admin.employees.show', $this->ana)] as $url) {
            $content = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(self::SECRET, $content);
            $this->assertStringNotContainsString($raw, $content);
            $this->assertStringNotContainsString('password', $content);
        }
    }

    // ---- show -------------------------------------------------------------

    public function test_show_returns_exact_props(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('admin.employees.show', $this->ana))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Employees/Show', false)
                ->has('employee', fn (Assert $employee) => $employee
                    ->where('id', $this->ana->id)
                    ->where('area_locked', false)
                    ->where('manager', null)
                    ->hasAll(['name', 'email', 'job_title', 'department', 'area', 'status', 'user_id', 'source', 'last_synced_at'])
                )
                ->has('accesses', 1)
                ->where('accesses.0', fn ($access) => collect($access)->keys()->sort()->values()->all() === ['id', 'last_rotated_at', 'notes', 'system', 'username'])
                ->where('accesses.0.system', ['id' => $this->office->id, 'name' => 'Office 365'])
                ->where('accesses.0.username', 'ana@segurex.com')
                ->where('can_reveal', false)
                ->where('can_manage', false)
                ->where('areas', [['id' => $this->ventas->id, 'name' => 'Ventas']])
                ->where('access_systems', [['id' => $this->office->id, 'name' => 'Office 365']])
            );
    }

    public function test_show_can_reveal_with_credentials_view(): void
    {
        $this->actingAs($this->user(['employees.view', 'credentials.view']))
            ->get(route('admin.employees.show', $this->ana))
            ->assertInertia(fn (Assert $page) => $page->where('can_reveal', true));
    }

    public function test_show_of_another_company_is_not_found(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('admin.employees.show', $this->foreign))
            ->assertNotFound();
    }

    // ---- update -----------------------------------------------------------

    public function test_update_requires_employees_manage(): void
    {
        $this->actingAs($this->viewer())
            ->put(route('admin.employees.update', $this->ana), ['status' => 'inactive'])
            ->assertForbidden();
    }

    public function test_manual_area_assignment_locks_the_area(): void
    {
        $tesoreria = Area::factory()->for($this->segurex)->create(['name' => 'Tesorería']);

        $this->actingAs($this->manager())
            ->put(route('admin.employees.update', $this->ana), ['area_id' => $tesoreria->id, 'status' => 'inactive'])
            ->assertSessionHasNoErrors()
            ->assertRedirect()
            ->assertSessionHas('success', 'Colaborador actualizado.');

        $this->ana->refresh();
        $this->assertSame($tesoreria->id, $this->ana->area_id);
        $this->assertTrue($this->ana->area_locked);
        $this->assertSame('inactive', $this->ana->status);
    }

    public function test_clearing_the_area_unlocks_it(): void
    {
        $this->ana->assignArea($this->ventas->id);

        $this->actingAs($this->manager())
            ->put(route('admin.employees.update', $this->ana), ['area_id' => null, 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->ana->refresh();
        $this->assertFalse($this->ana->area_locked);
        $this->assertNull($this->ana->area_id);
    }

    public function test_update_rejects_area_of_another_company_and_bad_status(): void
    {
        $cartera = Area::withoutGlobalScopes()->where('company_id', $this->derivados->id)->firstOrFail();

        $this->actingAs($this->manager())
            ->put(route('admin.employees.update', $this->ana), ['area_id' => $cartera->id, 'status' => 'retired'])
            ->assertSessionHasErrors(['area_id', 'status']);
    }

    public function test_update_of_another_company_is_not_found(): void
    {
        $this->actingAs($this->manager())
            ->put(route('admin.employees.update', $this->foreign), ['status' => 'inactive'])
            ->assertNotFound();
    }

    // ---- accesses ---------------------------------------------------------

    public function test_store_access_encrypts_the_password(): void
    {
        $vpn = AccessSystem::factory()->create(['name' => 'VPN']);

        $this->actingAs($this->manager())
            ->post(route('admin.employees.accesses.store', $this->ana), [
                'access_system_id' => $vpn->id,
                'username' => 'aperez',
                'password' => 'Vpn-Pass-1',
                'notes' => null,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Acceso agregado.');

        $access = EmployeeAccess::where('username', 'aperez')->sole();
        $this->assertSame('Vpn-Pass-1', $access->password);
        $this->assertNotSame('Vpn-Pass-1', DB::table('employee_accesses')->where('id', $access->id)->value('password'));
    }

    public function test_store_access_validates_input_and_uniqueness(): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.employees.accesses.store', $this->ana), [
                'access_system_id' => $this->office->id,
                'username' => 'ana@segurex.com',
                'password' => 'x',
            ])
            ->assertSessionHasErrors('username');

        $this->post(route('admin.employees.accesses.store', $this->ana), ['access_system_id' => 999])
            ->assertSessionHasErrors(['access_system_id', 'username', 'password']);
    }

    public function test_update_access_keeps_password_when_blank(): void
    {
        $this->actingAs($this->manager())
            ->put(route('admin.employees.accesses.update', [$this->ana, $this->access]), [
                'access_system_id' => $this->office->id,
                'username' => 'ana.perez@segurex.com',
                'password' => '',
                'notes' => 'Nueva nota',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Acceso actualizado.');

        $this->access->refresh();
        $this->assertSame('ana.perez@segurex.com', $this->access->username);
        $this->assertSame(self::SECRET, $this->access->password);

        $this->put(route('admin.employees.accesses.update', [$this->ana, $this->access]), [
            'access_system_id' => $this->office->id,
            'username' => 'ana.perez@segurex.com',
            'password' => 'Rotated-Value-9',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Rotated-Value-9', $this->access->fresh()->password);
    }

    public function test_destroy_access(): void
    {
        $this->actingAs($this->manager())
            ->delete(route('admin.employees.accesses.destroy', [$this->ana, $this->access]))
            ->assertSessionHas('success', 'Acceso eliminado.');

        $this->assertModelMissing($this->access);
    }

    public function test_access_routes_require_manage_and_matching_employee(): void
    {
        $this->actingAs($this->viewer())
            ->delete(route('admin.employees.accesses.destroy', [$this->ana, $this->access]))
            ->assertForbidden();

        $other = Employee::factory()->for($this->segurex)->create();

        $this->actingAs($this->manager())
            ->delete(route('admin.employees.accesses.destroy', [$other, $this->access]))
            ->assertNotFound();

        $this->post(route('admin.employees.accesses.store', $this->foreign), [
            'access_system_id' => $this->office->id,
            'username' => 'eva',
            'password' => 'x',
        ])->assertNotFound();
    }

    // ---- reveal -----------------------------------------------------------

    public function test_reveal_requires_credentials_view(): void
    {
        $this->actingAs($this->manager())
            ->postJson(route('admin.employees.accesses.reveal', [$this->ana, $this->access]))
            ->assertForbidden();

        $this->assertSame(0, Activity::where('event', 'credential_revealed')->count());
    }

    public function test_reveal_returns_value_with_no_store_and_logs_the_web_channel(): void
    {
        $user = $this->user(['employees.view', 'credentials.view']);

        $response = $this->actingAs($user)
            ->postJson(route('admin.employees.accesses.reveal', [$this->ana, $this->access]))
            ->assertOk()
            ->assertExactJson(['value' => self::SECRET, 'expires_in' => 30]);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $activity = Activity::where('event', 'credential_revealed')->sole();
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('web', $activity->properties['channel']);
    }

    public function test_reveal_across_companies_is_not_found(): void
    {
        $foreignAccess = EmployeeAccess::create([
            'employee_id' => $this->foreign->id,
            'access_system_id' => $this->office->id,
            'username' => 'eva',
            'password' => 'x',
        ]);

        $this->actingAs($this->user(['employees.view', 'credentials.view']))
            ->postJson(route('admin.employees.accesses.reveal', [$this->foreign, $foreignAccess]))
            ->assertNotFound();
    }

    public function test_reveal_is_throttled_to_ten_per_minute(): void
    {
        $this->actingAs($this->user(['employees.view', 'credentials.view']));

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('admin.employees.accesses.reveal', [$this->ana, $this->access]))->assertOk();
        }

        $this->postJson(route('admin.employees.accesses.reveal', [$this->ana, $this->access]))->assertStatus(429);
        $this->assertSame(10, Activity::where('event', 'credential_revealed')->count());
    }

    // ---- sync -------------------------------------------------------------

    public function test_sync_dispatches_the_job_for_the_current_company(): void
    {
        Queue::fake();

        $this->actingAs($this->manager())
            ->post(route('admin.employees.sync'))
            ->assertRedirect()
            ->assertSessionHas('success', 'Sincronización de colaboradores en cola.');

        Queue::assertPushed(SyncCompanyEmployees::class, 1);
        Queue::assertPushed(SyncCompanyEmployees::class, fn ($job) => $job->companyId === $this->segurex->id);
    }

    public function test_sync_in_all_context_dispatches_every_active_company_with_tenant(): void
    {
        Queue::fake();
        Company::factory()->inactive()->create();
        Company::factory()->create(['tenant_id' => null]);

        $this->actingAs($this->user(['employees.manage', 'companies.view_all']))
            ->post(route('admin.employees.sync'))
            ->assertSessionHas('success');

        Queue::assertPushed(SyncCompanyEmployees::class, 2);
    }

    public function test_sync_without_tenant_reports_an_error(): void
    {
        Queue::fake();
        $this->segurex->update(['tenant_id' => null]);

        $this->actingAs($this->manager())
            ->post(route('admin.employees.sync'))
            ->assertSessionHas('error', 'La empresa no tiene un inquilino de Microsoft configurado.');

        Queue::assertNothingPushed();
    }

    public function test_sync_requires_employees_manage(): void
    {
        Queue::fake();

        $this->actingAs($this->viewer())->post(route('admin.employees.sync'))->assertForbidden();

        Queue::assertNothingPushed();
    }
}
