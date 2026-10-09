<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Routes the design frontend resolves with Ziggy in Stage A: Stage B
 * placeholders (TEMPORARY) and the top-bar search.
 */
class StageAShellRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** Route name => parameters, as used by `types/pages/home.ts`. */
    private const STAGE_B_ROUTES = [
        'ai.requests.index' => [],
        'ai.requests.create' => [],
        'ai.requests.show' => ['request' => 'GIA-148'],
        'ai.tasks.show' => ['request' => 'GIA-131', 'task' => 'T2'],
        'ai.requirements.show' => ['requirement' => 57],
        'ai.vault.index' => [],
        'ai.ratings.create' => ['request' => 'GIA-118'],
        'ai.approvals.show' => ['request' => 'GIA-146'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_layout_routes_exist(): void
    {
        foreach (['home', 'search', 'company.switch', 'notifications.index', 'profile.edit', 'logout', 'employees.index'] as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] is missing.");
        }
    }

    public function test_stage_b_placeholders_redirect_home_with_a_notice(): void
    {
        $user = tap(User::factory()->create())->assignRole('Usuario final');

        foreach (self::STAGE_B_ROUTES as $name => $parameters) {
            $this->actingAs($user)
                ->get(route($name, $parameters))
                ->assertRedirect(route('home'))
                ->assertSessionHas('error', 'Disponible en la etapa B.');
        }
    }

    public function test_stage_b_placeholders_require_login_and_the_ai_module(): void
    {
        $this->get(route('ai.requests.index'))->assertRedirect(route('login'));

        config(['modules.ai.active' => false]);

        $this->get(route('ai.requests.index'))->assertNotFound();
    }

    public function test_search_sends_employee_viewers_to_the_employee_list(): void
    {
        $admin = tap(User::factory()->create())->assignRole('Súper Admin');

        $this->actingAs($admin)
            ->get(route('search', ['q' => 'castro']))
            ->assertRedirect(route('employees.index', ['q' => 'castro']));

        $this->actingAs($admin)
            ->get(route('search'))
            ->assertRedirect(route('employees.index'));
    }

    public function test_search_goes_back_with_a_notice_for_everybody_else(): void
    {
        $user = tap(User::factory()->create())->assignRole('Usuario final');

        $this->actingAs($user)
            ->from(route('ai.index'))
            ->get(route('search', ['q' => 'factura']))
            ->assertRedirect(route('ai.index'))
            ->assertSessionHas('error', 'La búsqueda llega en la etapa B.');
    }

    public function test_search_requires_login(): void
    {
        $this->get(route('search'))->assertRedirect(route('login'));
    }

    public function test_employees_index_is_reachable_outside_admin(): void
    {
        $admin = tap(User::factory()->create())->assignRole('Súper Admin');
        $user = tap(User::factory()->create())->assignRole('Usuario final');

        $this->actingAs($admin)->get(route('employees.index'))->assertOk();
        $this->actingAs($user)->get(route('employees.index'))->assertForbidden();
    }

    public function test_every_admin_tab_route_exists(): void
    {
        foreach (['admin.companies.index', 'admin.areas.index', 'admin.users.index', 'admin.roles.index', 'admin.integrations.index', 'admin.audit.index'] as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] is missing.");
        }

        $this->assertFalse(Route::has('admin.department-mappings.index'));
    }

    public function test_admin_tabs_not_wired_yet_redirect_to_companies_with_a_notice(): void
    {
        $admin = tap(User::factory()->create())->assignRole('Súper Admin');
        $user = tap(User::factory()->create())->assignRole('Usuario final');

        foreach (['admin.users.index', 'admin.integrations.index'] as $name) {
            $this->actingAs($admin)
                ->get(route($name))
                ->assertRedirect(route('admin.companies.index'))
                ->assertSessionHas('error', 'Disponible pronto.');

            $this->actingAs($user)->get(route($name))->assertForbidden();
        }
    }
}
