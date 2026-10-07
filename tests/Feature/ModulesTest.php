<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        return tap(User::factory()->create())->givePermissionTo($permissions);
    }

    public function test_config_declares_active_and_inactive_modules(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertTrue($registry->isActive('ai'));
        $this->assertTrue($registry->isActive('assistant'));
        $this->assertTrue($registry->isActive('admin'));

        foreach (['monitoring', 'tickets', 'inventory', 'automations', 'unknown'] as $key) {
            $this->assertFalse($registry->isActive($key), "{$key} should be inactive");
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
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('modules', 2)
                ->has('modules.0', fn (Assert $module) => $module
                    ->where('key', 'ai')
                    ->where('name', 'Gestor IA')
                    ->where('icon', config('modules.ai.icon'))
                    ->where('route', 'ai.index')
                    ->where('active', true)
                )
                ->where('modules.1.key', 'assistant')
                ->where('modules.1.name', 'Asistente TI')
                ->where('modules.1.route', 'assistant.index')
            );
    }

    public function test_admin_module_requires_its_permission(): void
    {
        $withoutAdmin = $this->userWith(config('modules.ai.permission'));

        $this->actingAs($withoutAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('modules', 1)
                ->where('modules.0.key', 'ai')
            );

        $withAdmin = $this->userWith(config('modules.ai.permission'), config('modules.admin.permission'));

        $this->actingAs($withAdmin)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('modules', 2)
                ->where('modules.1.key', 'admin')
                ->where('modules.1.name', 'Administración')
                ->where('modules.1.route', 'admin.index')
            );
    }

    public function test_inactive_module_never_appears_even_with_permission(): void
    {
        $user = $this->userWith(config('modules.tickets.permission'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('modules', []));
    }

    public function test_active_module_routes_redirect_guests_to_login(): void
    {
        $this->get(route('ai.index'))->assertRedirect(route('login'));
        $this->get(route('assistant.index'))->assertRedirect(route('login'));
        $this->get(route('admin.index'))->assertRedirect(route('login'));
    }

    public function test_active_module_route_renders_its_page_for_authenticated_user(): void
    {
        $user = $this->userWith(config('modules.ai.permission'));

        $this->actingAs($user)
            ->get(route('ai.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('AI/Index'));
    }

    public function test_module_route_is_unreachable_when_module_is_toggled_off(): void
    {
        config(['modules.ai.active' => false]);
        $user = $this->userWith(config('modules.ai.permission'));

        $this->get(route('ai.index'))->assertNotFound();
        $this->actingAs($user)->get(route('ai.index'))->assertNotFound();
    }

    public function test_toggled_off_module_disappears_from_shared_props(): void
    {
        config(['modules.assistant.active' => false]);
        $user = $this->userWith(config('modules.ai.permission'), config('modules.assistant.permission'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('modules', 1)
                ->where('modules.0.key', 'ai')
            );
    }
}
