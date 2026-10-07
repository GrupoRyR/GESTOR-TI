<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_has_exact_props(): void
    {
        config(['services.emergency.admin_email' => 'emergencia@example.com']);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login', false)
                ->where('microsoft_url', route('auth.microsoft.redirect'))
                ->where('emergency_enabled', true)
                ->where('status', null)
                ->etc()
            );

        $props = $this->get(route('login'))->viewData('page')['props'];
        $own = array_diff(array_keys($props), ['errors', 'auth', 'flash', 'companies', 'current_company_id', 'notifications_unread', 'modules']);
        $this->assertEqualsCanonicalizing(['microsoft_url', 'emergency_enabled', 'status'], array_values($own));
    }

    public function test_emergency_is_disabled_without_configured_email(): void
    {
        config(['services.emergency.admin_email' => null]);

        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('emergency_enabled', false)->etc());
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_guest_hitting_dashboard_goes_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_logout_logs_out_and_redirects_to_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.reauthenticated_at' => now()->getTimestamp()])
            ->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('auth.reauthenticated_at');

        $this->assertGuest();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));
    }
}
