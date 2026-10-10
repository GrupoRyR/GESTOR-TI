<?php

namespace Tests\Feature;

use App\Http\Controllers\SearchController;
use App\Models\Role;
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

    /**
     * Route name => parameters, as used by `types/pages/home.ts` and the
     * GestorLayout tabs. The requester routes are real since B3.
     */
    private const STAGE_B_ROUTES = [
        'ai.vault.index' => [],
        'ai.value.index' => [],
    ];

    /**
     * POST placeholders the pages already call (weekly review): they go back
     * with the notice.
     */
    private const STAGE_B_ACTIONS = [
        'ai.reviews.store' => [],
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

    public function test_stage_b_action_placeholders_go_back_with_a_notice(): void
    {
        $user = tap(User::factory()->create())->assignRole('Líder IA');

        foreach (self::STAGE_B_ACTIONS as $name => $parameters) {
            $this->actingAs($user)
                ->from(route('home'))
                ->post(route($name, $parameters))
                ->assertRedirect(route('home'))
                ->assertSessionHas('error', 'Disponible en la etapa B.');
        }
    }

    public function test_stage_b_placeholders_require_login_and_the_ai_module(): void
    {
        $this->get(route('ai.vault.index'))->assertRedirect(route('login'));

        config(['modules.ai.active' => false]);

        $this->get(route('ai.vault.index'))->assertNotFound();
    }

    public function test_search_sends_gestor_users_to_the_gestor_search(): void
    {
        $admin = tap(User::factory()->create())->assignRole('Súper Admin');

        $this->actingAs($admin)
            ->get(route('search', ['q' => 'castro']))
            ->assertRedirect(route('ai.search', ['q' => 'castro']));

        $this->actingAs($admin)
            ->get(route('search'))
            ->assertRedirect(route('ai.search'));
    }

    public function test_search_sends_employee_viewers_without_the_gestor_to_the_employee_list(): void
    {
        $role = Role::create(['name' => 'Solo colaboradores', 'guard_name' => 'web']);
        $role->givePermissionTo('employees.view');
        $viewer = tap(User::factory()->create())->assignRole('Solo colaboradores');

        $this->actingAs($viewer)
            ->get(route('search', ['q' => 'castro']))
            ->assertRedirect(route('employees.index', ['q' => 'castro']));

        $this->actingAs($viewer)
            ->get(route('search'))
            ->assertRedirect(route('employees.index'));
    }

    public function test_search_goes_back_with_a_notice_for_everybody_else(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('home'))
            ->get(route('search', ['q' => 'factura']))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', SearchController::NOTICE);
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

    public function test_every_admin_tab_renders_its_page(): void
    {
        $admin = tap(User::factory()->create())->assignRole('Súper Admin');
        $user = tap(User::factory()->create())->assignRole('Usuario final');

        foreach (['admin.integrations.index'] as $name) {
            $this->actingAs($admin)->get(route($name))->assertOk();

            $this->actingAs($user)->get(route($name))->assertForbidden();
        }
    }
}
