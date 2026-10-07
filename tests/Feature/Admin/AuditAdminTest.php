<?php

namespace Tests\Feature\Admin;

use App\Models\Area;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

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

        foreach (['audit.view', 'companies.view_all'] as $permission) {
            Permission::create(['name' => $permission]);
        }

        $this->segurex = Company::factory()->create();
        $this->derivados = Company::factory()->create();
        $this->ana = User::factory()->create(['name' => 'Ana Segurex', 'company_id' => $this->segurex->id]);
        $this->eva = User::factory()->create(['name' => 'Eva Derivados', 'company_id' => $this->derivados->id]);
        $this->ventas = Area::factory()->for($this->segurex)->create(['name' => 'Ventas']);

        // Only the activities created below.
        Activity::query()->delete();

        Carbon::setTestNow('2026-10-01 10:00:00');
        activity('areas')->causedBy($this->ana)->performedOn($this->ventas)->event('updated')
            ->withProperties(['attributes' => ['name' => 'Ventas', 'password' => 'leak'], 'old' => ['name' => 'Comercial']])
            ->log('updated');

        Carbon::setTestNow('2026-10-05 10:00:00');
        activity('companies')->causedBy($this->eva)->performedOn($this->derivados)->event('updated')
            ->withProperties(['attributes' => ['sender_email' => 'x@derivadoscol.com']])
            ->log('updated');

        Carbon::setTestNow('2026-10-06 10:00:00');
        activity('employees')->event('synced')->withProperties(['created' => 3])->log('employees_synced');

        Carbon::setTestNow();
    }

    private function auditor(Company $company, bool $global = false): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo($global ? ['audit.view', 'companies.view_all'] : ['audit.view']);

        return $user;
    }

    public function test_guests_are_redirected_and_permission_is_required(): void
    {
        $this->get(route('admin.audit.index'))->assertRedirect(route('login'));

        $this->actingAs($this->ana)->get(route('admin.audit.index'))->assertForbidden();
    }

    public function test_global_user_sees_every_activity_with_sanitized_properties(): void
    {
        $this->actingAs($this->auditor($this->segurex, global: true))
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Audit/Index', false)
                ->has('activities.data', 3)
                ->where('activities.meta.total', 3)
                ->where('activities.meta.per_page', 25)
                ->has('activities.links')
                ->where('activities.data.0.log_name', 'employees')
                ->where('activities.data.0.causer', null)
                ->where('activities.data.0.subject_type', null)
                ->where('activities.data.2', fn ($activity) => $activity['log_name'] === 'areas'
                    && $activity['description'] === 'updated'
                    && $activity['event'] === 'updated'
                    && $activity['subject_type'] === 'Area'
                    && $activity['subject_id'] === $this->ventas->id
                    && $activity['causer'] === ['id' => $this->ana->id, 'name' => 'Ana Segurex']
                    && $activity['properties'] === ['attributes' => ['name' => 'Ventas'], 'old' => ['name' => 'Comercial']]
                    && str_starts_with($activity['created_at'], '2026-10-01T10:00:00'))
                ->has('activities.data.0', fn (Assert $activity) => $activity->hasAll([
                    'id', 'log_name', 'description', 'event', 'subject_type', 'subject_id', 'causer', 'properties', 'created_at',
                ]))
                ->where('filters', [
                    'search' => null, 'causer_id' => null, 'subject_type' => null, 'log_name' => null, 'from' => null, 'to' => null,
                ])
                ->where('options.log_names', ['areas', 'companies', 'employees'])
                ->where('options.subject_types', ['Area', 'Company'])
                ->where('options.causers', [
                    ['id' => $this->ana->id, 'name' => 'Ana Segurex'],
                    ['id' => $this->eva->id, 'name' => 'Eva Derivados'],
                ])
            );
    }

    public function test_restricted_user_only_sees_activities_caused_by_users_of_their_company(): void
    {
        $this->actingAs($this->auditor($this->derivados))
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('activities.data', 1)
                ->where('activities.data.0.log_name', 'companies')
                ->where('options.log_names', ['companies'])
                ->where('options.subject_types', ['Company'])
                ->where('options.causers', [['id' => $this->eva->id, 'name' => 'Eva Derivados']])
            );
    }

    public function test_global_user_with_a_selected_company_sees_that_company_only(): void
    {
        $this->actingAs($this->auditor($this->segurex, global: true))
            ->withSession([CurrentCompany::SESSION_KEY => $this->segurex->id])
            ->get(route('admin.audit.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('activities.data', 1)
                ->where('activities.data.0.log_name', 'areas'));
    }

    public function test_restricted_user_cannot_reach_other_companies_through_filters(): void
    {
        $this->actingAs($this->auditor($this->segurex))
            ->get(route('admin.audit.index', ['causer_id' => $this->eva->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('activities.data', 0)
                ->where('filters.causer_id', $this->eva->id));
    }

    public function test_filters_narrow_the_listing(): void
    {
        $auditor = $this->auditor($this->segurex, global: true);

        $cases = [
            [['log_name' => 'companies'], ['companies']],
            [['subject_type' => 'Area'], ['areas']],
            [['causer_id' => $this->eva->id], ['companies']],
            [['search' => 'synced'], ['employees']],
            [['from' => '2026-10-05'], ['employees', 'companies']],
            [['to' => '2026-10-01'], ['areas']],
            [['from' => '2026-10-02', 'to' => '2026-10-05'], ['companies']],
        ];

        foreach ($cases as [$query, $expected]) {
            $this->actingAs($auditor)
                ->get(route('admin.audit.index', $query))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('activities.data', fn ($data) => collect($data)->pluck('log_name')->all() === $expected));
        }
    }

    public function test_unknown_filter_values_are_ignored(): void
    {
        $this->actingAs($this->auditor($this->segurex, global: true))
            ->get(route('admin.audit.index', [
                'log_name' => 'nope',
                'subject_type' => 'Secret',
                'from' => '2026-02-31',
                'to' => 'yesterday',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('activities.data', 3)
                ->where('filters.log_name', null)
                ->where('filters.subject_type', null)
                ->where('filters.from', null)
                ->where('filters.to', null));
    }
}
