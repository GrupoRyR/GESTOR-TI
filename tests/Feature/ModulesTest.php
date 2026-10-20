<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    use RefreshDatabase;

    /** Inactive (reserved) modules, always shared with `active: false`. */
    private const INACTIVE = [
        ['key' => 'monitoring', 'name' => 'Monitoreo', 'icon' => 'monitoring', 'route' => 'monitoring.index', 'active' => false],
        ['key' => 'tickets', 'name' => 'Tickets', 'icon' => 'tickets', 'route' => 'tickets.index', 'active' => false],
        ['key' => 'inventory', 'name' => 'Inventario', 'icon' => 'inventory', 'route' => 'inventory.index', 'active' => false],
        ['key' => 'operations', 'name' => 'Operación', 'icon' => 'operations', 'route' => 'operations.index', 'active' => false],
    ];

    private function userWith(string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        return tap(User::factory()->create())->givePermissionTo($permissions);
    }

    /**
     * @return list<string>
     */
    private function activeKeys(array $modules): array
    {
        return collect($modules)->where('active', true)->pluck('key')->values()->all();
    }

    public function test_config_declares_active_and_inactive_modules(): void
    {
        $registry = app(ModuleRegistry::class);

        foreach (['home', 'ai', 'assistant', 'employees', 'admin'] as $key) {
            $this->assertTrue($registry->isActive($key), "{$key} should be active");
        }

        foreach (['monitoring', 'tickets', 'inventory', 'operations', 'automations', 'unknown'] as $key) {
            $this->assertFalse($registry->isActive($key), "{$key} should be inactive");
        }
    }

    public function test_super_admin_receives_the_design_module_list(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = tap(User::factory()->create())->assignRole('Súper Admin');

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('modules', [
                ['key' => 'home', 'name' => 'Inicio', 'icon' => 'home', 'route' => 'home', 'active' => true],
                ['key' => 'ai', 'name' => 'Gestor IA', 'icon' => 'ai', 'route' => 'ai.index', 'active' => true],
                ['key' => 'assistant', 'name' => 'Asistente', 'icon' => 'assistant', 'route' => 'assistant.index', 'active' => true],
                ['key' => 'employees', 'name' => 'Colaboradores', 'icon' => 'employees', 'route' => 'employees.index', 'active' => true],
                ['key' => 'admin', 'name' => 'Administración', 'icon' => 'admin', 'route' => 'admin.companies.index', 'active' => true],
                ...self::INACTIVE,
            ]));
    }

    public function test_end_user_sees_home_ai_and_the_inactive_modules(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = tap(User::factory()->create())->assignRole('Usuario final');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', fn ($modules) => $this->activeKeys($modules->all()) === ['home', 'ai'])
                ->where('modules', fn ($modules) => collect($modules)->where('active', false)->values()->all() === self::INACTIVE)
            );
    }

    public function test_every_active_module_route_exists(): void
    {
        foreach (config('modules') as $key => $module) {
            if ($module['active']) {
                $this->assertTrue(Route::has($module['route']), "Route [{$module['route']}] of module [{$key}] is missing.");
            }
        }
    }

    public function test_user_sees_only_active_modules_they_are_allowed_to(): void
    {
        $user = $this->userWith(
            config('modules.ai.permission'),
            config('modules.assistant.permission'),
            config('modules.tickets.permission'),
        );

        $this->actingAs($user)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', fn ($modules) => $this->activeKeys($modules->all()) === ['home', 'ai', 'assistant'])
                ->where('modules.3.key', 'monitoring')
                ->where('modules.4.key', 'tickets')
                ->where('modules.4.active', false)
            );
    }

    public function test_admin_and_employees_modules_require_their_permission(): void
    {
        $withoutAdmin = $this->userWith(config('modules.ai.permission'));

        $this->actingAs($withoutAdmin)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', fn ($modules) => $this->activeKeys($modules->all()) === ['home', 'ai'])
            );

        $withAdmin = $this->userWith(
            config('modules.ai.permission'),
            config('modules.employees.permission'),
            config('modules.admin.permission'),
        );

        $this->actingAs($withAdmin)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', fn ($modules) => $this->activeKeys($modules->all()) === ['home', 'ai', 'employees', 'admin'])
            );
    }

    public function test_active_module_routes_redirect_guests_to_login(): void
    {
        $this->get(route('ai.index'))->assertRedirect(route('login'));
        $this->get(route('assistant.index'))->assertRedirect(route('login'));
        $this->get(route('admin.index'))->assertRedirect(route('login'));
        $this->get(route('employees.index'))->assertRedirect(route('login'));
    }

    public function test_gestor_ia_entry_sends_requesters_to_their_requests(): void
    {
        $user = $this->userWith(config('modules.ai.permission'));

        $this->actingAs($user)->get(route('ai.index'))->assertRedirect(route('ai.requests.mine'));
    }

    public function test_gestor_ia_entry_sends_it_staff_to_the_inbox(): void
    {
        $user = $this->userWith('ai.manage');

        $this->actingAs($user)->get(route('ai.index'))->assertRedirect(route('ai.inbox.index'));
    }

    public function test_admin_entry_redirects_to_companies(): void
    {
        $user = $this->userWith('admin.access');

        $this->actingAs($user)->get(route('admin.index'))->assertRedirect(route('admin.companies.index'));
    }

    public function test_gestor_ia_entry_without_request_permission_shows_the_roadmap(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('ai.index'))->assertRedirect(route('ai.roadmap.index'));
    }

    public function test_module_route_is_unreachable_when_module_is_toggled_off(): void
    {
        config(['modules.ai.active' => false]);
        $user = $this->userWith(config('modules.ai.permission'));

        $this->get(route('ai.index'))->assertNotFound();
        $this->actingAs($user)->get(route('ai.index'))->assertNotFound();
    }

    public function test_toggled_off_module_is_shared_as_inactive(): void
    {
        config(['modules.assistant.active' => false]);
        $user = $this->userWith(config('modules.ai.permission'), config('modules.assistant.permission'));

        $this->actingAs($user)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', fn ($modules) => $this->activeKeys($modules->all()) === ['home', 'ai'])
                ->where('modules.2.key', 'assistant')
                ->where('modules.2.active', false)
            );
    }
}
