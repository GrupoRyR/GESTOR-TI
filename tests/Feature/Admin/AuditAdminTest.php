<?php

namespace Tests\Feature\Admin;

use App\Models\Area;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Support\Audit\AuditOrigin;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Administración › Auditoría (`Admin/Audit/Index`, types/pages/admin.ts).
 */
class AuditAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $ana;

    private User $eva;

    private Area $ventas;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['audit.view', 'companies.view_all', 'admin.access', 'users.manage'] as $permission) {
            Permission::create(['name' => $permission]);
        }

        Carbon::setTestNow('2026-10-07 12:00:00');

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
        $this->ana = User::factory()->create(['name' => 'Ana Segurex', 'company_id' => $this->segurex->id]);
        $this->eva = User::factory()->create(['name' => 'Eva Derivados', 'company_id' => $this->derivados->id]);
        $this->ventas = Area::factory()->for($this->segurex)->create(['name' => 'Ventas']);

        // Only the activities created below.
        Activity::query()->delete();

        Carbon::setTestNow('2026-09-01 10:00:00');
        activity('areas')->causedBy($this->ana)->performedOn($this->ventas)->event('updated')
            ->withProperties(['attributes' => ['name' => 'Ventas', 'password' => 'leak'], 'old' => ['name' => 'Comercial']])
            ->log('updated');

        Carbon::setTestNow('2026-10-05 10:00:00');
        activity('companies')->causedBy($this->eva)->performedOn($this->derivados)->event('updated')
            ->withProperties(['attributes' => ['sender_email' => 'derivados.info@derivadoscol.com'], 'old' => ['sender_email' => 'info@derivadoscol.com']])
            ->log('updated');

        Carbon::setTestNow('2026-10-07 09:00:00');
        activity('auth')->event('emergency_login_locked')->withProperties(['email' => 'emergencia@example.com', 'ip' => '181.52.0.9'])
            ->log('Ingreso de emergencia rechazado: cuenta bloqueada');

        Carbon::setTestNow('2026-10-07 11:00:00');
        activity('companies')->causedBy($this->eva)->performedOn($this->derivados)->event('updated')
            ->withProperties(['attributes' => ['tenant_id' => '22222222-2222-2222-2222-222222222222'], 'old' => ['tenant_id' => null]])
            ->log('updated');

        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function auditor(Company $company, bool $global = false): User
    {
        $user = User::factory()->create(['name' => 'Auditor', 'company_id' => $company->id]);
        $user->givePermissionTo($global ? ['audit.view', 'companies.view_all'] : ['audit.view']);

        return $user;
    }

    public function test_guests_are_redirected_and_permission_is_required(): void
    {
        $this->get(route('admin.audit.index'))->assertRedirect(route('login'));
        $this->actingAs($this->ana)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($this->ana)->get(route('admin.audit.export'))->assertForbidden();
    }

    public function test_index_returns_exact_props_for_the_last_seven_days(): void
    {
        $response = $this->actingAs($this->auditor($this->segurex, global: true))
            ->get(route('admin.audit.index'))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Audit/Index', false)
            ->has('entries.data', 3)
            ->where('entries.meta.total', 3)
            ->where('entries.meta.per_page', 25)
            ->has('entries.links')
            ->where('entries.data.0', fn ($entry) => $entry['causer'] === ['id' => $this->eva->id, 'name' => 'Eva Derivados']
                && $entry['description'] === 'Cambió el inquilino de Microsoft de Derivados'
                && $entry['origin'] === 'system'
                && $entry['ip'] === null
                && $entry['sensitive'] === true
                && $entry['alert'] === false
                && $entry['changes'] === [['field' => 'ID de inquilino', 'old' => null, 'new' => '22222222-2222-2222-2222-222222222222']]
                && str_starts_with($entry['at'], '2026-10-07T11:00:00'))
            ->where('entries.data.1', fn ($entry) => $entry['causer'] === null
                && $entry['description'] === 'Intento en la cuenta de emergencia bloqueada'
                && $entry['ip'] === '181.52.0.9'
                && $entry['sensitive'] === true
                && $entry['alert'] === true
                && $entry['changes'] === null)
            ->where('entries.data.2', fn ($entry) => $entry['description'] === 'Cambió el correo emisor de Derivados'
                && $entry['sensitive'] === false
                && $entry['changes'] === [['field' => 'Correo emisor', 'old' => 'info@derivadoscol.com', 'new' => 'derivados.info@derivadoscol.com']])
            ->has('entries.data.0', 9)
            ->where('filters', ['causer_id' => null, 'module' => null, 'sensitive_only' => false, 'range' => '7d'])
            ->where('causer_options', [
                ['id' => $this->ana->id, 'name' => 'Ana Segurex'],
                ['id' => $this->eva->id, 'name' => 'Eva Derivados'],
            ])
            ->where('module_options.0', ['value' => 'companies', 'label' => 'Empresas'])
            ->where('module_options', fn ($options) => collect($options)->pluck('value')->contains('auth'))
        );

        $props = $response->viewData('page')['props'];
        $own = array_diff(array_keys($props), ['errors', 'auth', 'flash', 'companies', 'current_company_id', 'notifications_unread', 'modules']);
        $this->assertEqualsCanonicalizing(['entries', 'filters', 'causer_options', 'module_options'], array_values($own));
    }

    public function test_changes_are_sanitized_and_rendered(): void
    {
        $this->actingAs($this->auditor($this->segurex, global: true))
            ->get(route('admin.audit.index', ['range' => 'all']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 4)
                ->where('entries.data.3.description', 'Editó el área Ventas')
                ->where('entries.data.3.changes', [['field' => 'Nombre', 'old' => 'Comercial', 'new' => 'Ventas']]));
    }

    public function test_range_filter(): void
    {
        $auditor = $this->auditor($this->segurex, global: true);

        foreach (['24h' => 2, '7d' => 3, '30d' => 3, 'all' => 4, 'nope' => 3] as $range => $count) {
            $this->actingAs($auditor)
                ->get(route('admin.audit.index', ['range' => $range]))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('entries.data', $count)
                    ->where('filters.range', $range === 'nope' ? '7d' : $range));
        }

        $this->travel(40)->days();

        $this->actingAs($auditor)
            ->get(route('admin.audit.index', ['range' => '30d']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
    }

    public function test_module_sensitive_and_causer_filters(): void
    {
        $auditor = $this->auditor($this->segurex, global: true);

        $cases = [
            [['module' => 'companies', 'range' => 'all'], 2, ['module' => 'companies']],
            [['module' => 'auth'], 1, ['module' => 'auth']],
            [['module' => 'areas', 'range' => 'all'], 1, ['module' => 'areas']],
            [['module' => 'nope'], 3, ['module' => null]],
            [['sensitive_only' => '1'], 2, ['sensitive_only' => true]],
            [['causer_id' => $this->eva->id], 2, ['causer_id' => $this->eva->id]],
        ];

        foreach ($cases as [$query, $count, $filters]) {
            $this->actingAs($auditor)
                ->get(route('admin.audit.index', $query))
                ->assertInertia(function (Assert $page) use ($count, $filters) {
                    $page->has('entries.data', $count);

                    foreach ($filters as $key => $value) {
                        $page->where('filters.'.$key, $value);
                    }
                });
        }
    }

    public function test_restricted_user_only_sees_activities_caused_by_users_of_their_company(): void
    {
        $this->actingAs($this->auditor($this->derivados))
            ->get(route('admin.audit.index', ['range' => 'all']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 2)
                ->where('causer_options', [['id' => $this->eva->id, 'name' => 'Eva Derivados']]));

        $this->actingAs($this->auditor($this->segurex))
            ->get(route('admin.audit.index', ['causer_id' => $this->eva->id, 'range' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
    }

    public function test_global_user_with_a_selected_company_sees_that_company_only(): void
    {
        $this->actingAs($this->auditor($this->segurex, global: true))
            ->withSession([CurrentCompany::SESSION_KEY => $this->segurex->id])
            ->get(route('admin.audit.index', ['range' => 'all']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.description', 'Editó el área Ventas'));
    }

    public function test_http_actions_record_web_origin_and_ip(): void
    {
        $admin = User::factory()->create(['name' => 'Jhon Castro', 'company_id' => $this->segurex->id]);
        $admin->givePermissionTo(['admin.access', 'users.manage', 'audit.view', 'companies.view_all']);
        $paola = User::factory()->create(['name' => 'Paola Ríos', 'company_id' => $this->segurex->id]);

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '190.24.118.4'])
            ->patch(route('admin.users.deactivate', $paola))
            ->assertSessionHasNoErrors();

        $activity = Activity::query()->where('event', 'user_deactivated')->sole();
        $this->assertSame('web', $activity->properties['origin']);
        $this->assertSame('190.24.118.4', $activity->properties['ip']);

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.description', 'Desactivó a Paola Ríos')
                ->where('entries.data.0.origin', 'web')
                ->where('entries.data.0.ip', '190.24.118.4')
                ->where('entries.data.0.changes', [['field' => 'Estado', 'old' => 'Activo', 'new' => 'Inactivo']]));
    }

    public function test_console_activities_record_system_origin_and_telegram_context_wins(): void
    {
        activity('employees')->event('employees_synced')->withProperties(['company' => 'Segurex'])->log('employees_synced');
        $this->assertSame('system', Activity::query()->latest('id')->first()->properties['origin']);

        AuditOrigin::runAs(AuditOrigin::TELEGRAM, fn () => activity('credentials')->event('credential_revealed')
            ->withProperties(['channel' => 'telegram', 'employee_name' => 'Mónica Salazar', 'access_system' => 'Office'])
            ->log('credential_revealed'));

        $telegram = Activity::query()->latest('id')->first();
        $this->assertSame('telegram', $telegram->properties['origin']);
        $this->assertNull($telegram->properties['ip']);

        $this->actingAs($this->auditor($this->segurex, global: true))
            ->get(route('admin.audit.index', ['module' => 'credentials']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.description', 'Recibió la contraseña de Office de Mónica Salazar por el Asistente')
                ->where('entries.data.0.origin', 'telegram')
                ->where('entries.data.0.sensitive', true));
    }

    public function test_export_streams_a_sanitized_csv_with_the_same_filters(): void
    {
        $evil = User::factory()->create(['name' => '=SUM(1+1)', 'company_id' => $this->derivados->id]);
        activity('companies')->causedBy($evil)->performedOn($this->derivados)->event('updated')
            ->withProperties(['attributes' => ['name' => 'Derivados SAS', 'secret_token' => 'leak'], 'old' => ['name' => 'Derivados']])
            ->log('updated');

        $response = $this->actingAs($this->auditor($this->segurex, global: true))
            ->get(route('admin.audit.export', ['module' => 'companies']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('attachment; filename=auditoria-', (string) $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));

        $this->assertSame('Fecha;Usuario;Acción;Módulo;Origen;IP;Sensible;Alerta;Cambios', $lines[0]);
        $this->assertCount(4, $lines);
        $this->assertStringContainsString(";'=SUM(1+1);\"Editó la empresa Derivados\";Empresas;Sistema;;No;No;\"Nombre: Derivados → Derivados SAS\"", $lines[1]);
        $this->assertStringNotContainsString('leak', $csv);
        $this->assertStringContainsString('Cambió el inquilino de Microsoft de Derivados', $lines[2]);
        $this->assertStringContainsString(';Sí;No;', $lines[2]);
    }
}
