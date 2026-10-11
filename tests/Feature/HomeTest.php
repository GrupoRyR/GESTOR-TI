<?php

namespace Tests\Feature;

use App\Models\MonitoredApp;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Home (`types/pages/home.ts`): `it` view for `ai.manage`, `requester` for
 * everybody else. Stage B data ships empty in Stage A.
 */
class HomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_it_view_has_exactly_the_contract_props(): void
    {
        $leader = tap(User::factory()->create(['name' => 'Jhon Castro']))->assignRole('Líder IA');

        $this->actingAs($leader)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home', false)
                ->where('view', 'it')
                ->where('greeting_name', 'Jhon')
                ->has('attention', fn (Assert $attention) => $attention
                    ->where('unanswered_requests', [])
                    ->where('overdue_tasks', [])
                    ->where('waiting_requirements', [])
                    ->where('expiring_credentials', [])
                )
                ->where('pending_approvals', [])
                ->has('monitoring', fn (Assert $monitoring) => $monitoring
                    ->where('enabled', false)
                    ->where('apps', [])
                )
                ->missing('requests')
            );
    }

    public function test_it_view_lists_monitored_apps_as_placeholders(): void
    {
        MonitoredApp::create(['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP', 'type' => 'web']);
        MonitoredApp::create(['slug' => 'robotcamara', 'name' => 'robotcamara', 'type' => 'local', 'last_status' => 'up']);

        $admin = tap(User::factory()->create(['name' => 'Leonardo Herrera']))->assignRole('Súper Admin');

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'it')
                ->where('monitoring', [
                    'enabled' => false,
                    'apps' => [
                        ['name' => 'Toma Pedidos SAP', 'kind' => 'web'],
                        ['name' => 'robotcamara', 'kind' => 'local'],
                    ],
                ])
            );
    }

    public function test_requester_view_has_exactly_the_contract_props(): void
    {
        $user = tap(User::factory()->create(['name' => 'Andrés Méndez']))->assignRole('Usuario final');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home', false)
                ->where('view', 'requester')
                ->where('greeting_name', 'Andrés')
                ->where('requests', [])
                ->missing('attention')
                ->missing('pending_approvals')
                ->missing('monitoring')
            );
    }

    public function test_home_page_props_have_no_extra_keys(): void
    {
        $shared = ['auth', 'companies', 'current_company_id', 'flash', 'notifications_unread', 'modules', 'errors'];
        $user = tap(User::factory()->create())->assignRole('Usuario final');

        $props = $this->actingAs($user)->get(route('home'))->viewData('page')['props'];
        $this->assertEqualsCanonicalizing([...$shared, 'view', 'greeting_name', 'requests'], array_keys($props));

        $leader = tap(User::factory()->create())->assignRole('Líder IA');

        $props = $this->actingAs($leader)->get(route('home'))->viewData('page')['props'];
        $this->assertEqualsCanonicalizing(
            [...$shared, 'view', 'greeting_name', 'attention', 'pending_approvals', 'monitoring'],
            array_keys($props),
        );
    }
}
