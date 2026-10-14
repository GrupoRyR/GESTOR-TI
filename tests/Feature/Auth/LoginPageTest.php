<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\SessionController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED = ['errors', 'auth', 'flash', 'companies', 'current_company_id', 'notifications_unread', 'modules'];

    /**
     * @return list<string>
     */
    private function ownProps(string $uri): array
    {
        $props = $this->get($uri)->viewData('page')['props'];

        return array_values(array_diff(array_keys($props), self::SHARED));
    }

    public function test_login_page_has_exact_props(): void
    {
        $active = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        Company::factory()->create(['name' => 'Inactiva', 'is_active' => false]);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login', false)
                ->has('companies', 1)
                ->where('companies.0', $active->fresh()->toSharedArray())
                ->where('error', null)
                ->etc()
            );

        // Page-level `companies` is the same list as the guest shared one.
        $props = $this->get(route('login'))->viewData('page')['props'];
        $this->assertSame([$active->fresh()->toSharedArray()], $props['companies']);

        $this->assertEqualsCanonicalizing(['error'], $this->ownProps(route('login')));
    }

    public function test_microsoft_error_codes_are_read_once_from_the_session(): void
    {
        foreach (['tenant_not_allowed', 'microsoft_failed'] as $code) {
            $this->withSession([SessionController::LOGIN_ERROR => $code])
                ->get(route('login'))
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Auth/Login', false)
                    ->where('error', $code)
                    ->where('flash.error', null)
                    ->etc()
                );
        }
    }

    public function test_unknown_error_codes_are_ignored(): void
    {
        $this->withSession([SessionController::LOGIN_ERROR => '<script>'])
            ->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('error', null)->etc());
    }

    public function test_company_not_enabled_page_is_rendered_from_the_session(): void
    {
        config(['gestor.support_email' => 'soporte.ti@segurex.com']);

        $this->withSession([SessionController::COMPANY_NOT_ENABLED => 'laura@proveedorx.com'])
            ->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/CompanyNotEnabled', false)
                ->where('email', 'laura@proveedorx.com')
                ->where('support_email', 'soporte.ti@segurex.com')
                ->etc()
            );

        $own = $this->withSession([SessionController::COMPANY_NOT_ENABLED => 'laura@proveedorx.com'])
            ->ownProps(route('login'));
        $this->assertEqualsCanonicalizing(['email', 'support_email'], $own);

        // One-shot: the next visit is the normal login page.
        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login', false)->etc());
    }

    public function test_support_email_defaults_to_the_it_lead(): void
    {
        // GESTOR_SUPPORT_EMAIL default, confirmed by Jhon.
        $this->withSession([SessionController::COMPANY_NOT_ENABLED => 'laura@proveedorx.com'])
            ->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/CompanyNotEnabled', false)
                ->where('support_email', 'jhon.castro@segurex.com')
                ->etc());
    }

    public function test_support_email_is_null_when_not_configured(): void
    {
        config(['gestor.support_email' => null]);

        $this->withSession([SessionController::COMPANY_NOT_ENABLED => 'laura@proveedorx.com'])
            ->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('support_email', null)->etc());
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect(route('home'));
    }

    public function test_guest_hitting_home_goes_to_login(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
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
