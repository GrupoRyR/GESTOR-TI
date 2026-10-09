<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use SocialiteProviders\Microsoft\Provider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Deactivated users (`users.active = false`, Administración › Usuarios)
 * cannot sign in by Microsoft or by the emergency login, and an open
 * session is closed on its next request.
 */
class InactiveUserTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_deactivated_user_cannot_sign_in_with_microsoft(): void
    {
        config([
            'services.microsoft.client_id' => 'test-client',
            'services.microsoft.client_secret' => 'test-secret',
            'services.microsoft.redirect' => 'http://localhost/auth/microsoft/callback',
        ]);

        $company = Company::factory()->create(['tenant_id' => self::TENANT, 'domains' => ['segurex.com']]);
        $ana = User::factory()->inactive()->create(['email' => 'ana@segurex.com', 'company_id' => $company->id]);
        $ana->forceFill(['azure_id' => 'azure-ana'])->save();

        $raw = ['id' => 'azure-ana', 'displayName' => 'Ana Pérez', 'userPrincipalName' => 'ana@segurex.com', 'mail' => 'ana@segurex.com'];
        $socialUser = (new SocialiteUser)->setRaw($raw)->map(['id' => 'azure-ana', 'name' => 'Ana Pérez', 'email' => 'ana@segurex.com']);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialUser);
        $provider->shouldReceive('getClaims')->andReturn((object) ['tid' => self::TENANT]);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->get(route('auth.microsoft.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', EnsureUserIsActive::MESSAGE);

        $this->assertGuest();
        $this->assertNull($ana->fresh()->last_login_at);
    }

    public function test_deactivated_emergency_account_cannot_sign_in(): void
    {
        config(['services.emergency.admin_email' => 'emergencia@example.com']);
        User::factory()->inactive()->create(['email' => 'emergencia@example.com', 'password' => 'correct-horse-battery-staple']);

        $this->from(route('login.emergency'))
            ->post(route('login.emergency.store'), ['username' => 'emergencia@example.com', 'password' => 'correct-horse-battery-staple'])
            ->assertRedirect(route('login.emergency'))
            ->assertSessionHasErrors(['password' => 'La cuenta de emergencia está desactivada.']);

        $this->assertGuest();
        $this->assertTrue(Activity::query()->where('event', 'emergency_login_inactive')->exists());
    }

    public function test_wrong_password_on_a_deactivated_emergency_account_shows_the_generic_error(): void
    {
        config(['services.emergency.admin_email' => 'emergencia@example.com']);
        User::factory()->inactive()->create(['email' => 'emergencia@example.com', 'password' => 'correct-horse-battery-staple']);

        $this->from(route('login.emergency'))
            ->post(route('login.emergency.store'), ['username' => 'emergencia@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['password' => 'Las credenciales no son válidas.']);
    }

    public function test_open_session_of_a_deactivated_user_is_closed_on_the_next_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('home'))->assertOk();

        $user->forceFill(['active' => false])->save();

        $this->get(route('home'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', EnsureUserIsActive::MESSAGE);

        $this->assertGuest();
    }

    public function test_json_requests_of_a_deactivated_user_get_401(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->getJson(route('home'))->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_deactivated_users_are_not_routed_to_telegram_or_mail(): void
    {
        $user = User::factory()->inactive()->create();

        $this->assertNull($user->routeNotificationFor('mail'));
        $this->assertNull($user->routeNotificationFor('telegram'));
    }
}
