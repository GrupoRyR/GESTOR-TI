<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class EmergencyLoginTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_ERROR = 'Las credenciales no son válidas.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.emergency.admin_email' => 'Emergencia@Example.com']);

        $this->admin = User::factory()->create([
            'email' => 'emergencia@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);
    }

    private function attempt(string $username, string $password, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from(route('login.emergency'))
            ->post(route('login.emergency.store'), ['username' => $username, 'password' => $password]);
    }

    /**
     * Follows the redirect back of a failed attempt and returns the page props.
     *
     * @return array<string, mixed>
     */
    private function pageAfter(TestResponse $response): array
    {
        $response->assertRedirect(route('login.emergency'));

        return $this->get(route('login.emergency'))->viewData('page')['props'];
    }

    public function test_page_renders_with_null_lockout_and_attempts(): void
    {
        $this->get(route('login.emergency'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Emergency', false)
                ->where('lockout', null)
                ->where('attempts_left', null)
                ->etc()
            );

        $props = $this->get(route('login.emergency'))->viewData('page')['props'];
        $own = array_diff(array_keys($props), ['errors', 'auth', 'flash', 'companies', 'current_company_id', 'notifications_unread', 'modules']);
        $this->assertEqualsCanonicalizing(['lockout', 'attempts_left'], array_values($own));
    }

    public function test_configured_email_logs_in(): void
    {
        $this->attempt('EMERGENCIA@example.com', 'correct-horse-battery-staple')
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($this->admin);
        $this->assertNotNull($this->admin->fresh()->last_login_at);
        $this->assertTrue(Activity::where('log_name', 'auth')->where('event', 'emergency_login_succeeded')->exists());
    }

    public function test_other_email_gets_generic_error_even_with_valid_password(): void
    {
        $other = User::factory()->create(['email' => 'otro@example.com', 'password' => 'otro-password-123']);

        $this->attempt('otro@example.com', 'otro-password-123')
            ->assertRedirect(route('login.emergency'))
            ->assertSessionHasErrors(['password' => self::GENERIC_ERROR]);

        $this->attempt('nadie@example.com', 'whatever')
            ->assertSessionHasErrors(['password' => self::GENERIC_ERROR]);

        $this->assertGuest();
        $this->assertSame(2, Activity::where('log_name', 'auth')->where('event', 'emergency_login_failed')->count());
        $this->assertSame(0, $other->fresh()->failed_login_attempts);
    }

    public function test_wrong_password_gets_generic_error_counts_and_shows_attempts_left(): void
    {
        $props = $this->pageAfter(
            $this->attempt('emergencia@example.com', 'wrong')->assertSessionHasErrors(['password' => self::GENERIC_ERROR])
        );

        $this->assertGuest();
        $this->assertSame(1, $this->admin->fresh()->failed_login_attempts);
        $this->assertSame(4, $props['attempts_left']);
        $this->assertNull($props['lockout']);

        $props = $this->pageAfter($this->attempt('emergencia@example.com', 'wrong-2'));
        $this->assertSame(3, $props['attempts_left']);

        // One-shot: a fresh visit shows nothing.
        $props = $this->get(route('login.emergency'))->viewData('page')['props'];
        $this->assertNull($props['attempts_left']);
        $this->assertNull($props['lockout']);
    }

    public function test_unknown_username_shows_the_same_attempts_and_lockout_as_the_real_one(): void
    {
        $this->freezeSecond();
        $real = [];
        $unknown = [];

        // Different IPs only to stay under the per-IP `throttle:login` route limit.
        foreach (range(1, 5) as $i) {
            $real[] = $this->pageAfter($this->attempt('emergencia@example.com', 'wrong-'.$i, '10.0.0.1'));
            $unknown[] = $this->pageAfter($this->attempt('nadie@example.com', 'wrong-'.$i, '10.0.0.2'));
        }

        $this->assertSame([4, 3, 2, 1, 0], array_column($real, 'attempts_left'));
        $this->assertSame(array_column($real, 'attempts_left'), array_column($unknown, 'attempts_left'));
        $this->assertNotNull($real[4]['lockout']);
        $this->assertNotNull($unknown[4]['lockout']);
        $this->assertSame($real[4]['lockout'], $unknown[4]['lockout']);
    }

    public function test_five_failures_show_the_lockout_until(): void
    {
        $this->freezeSecond();

        foreach (range(1, 4) as $i) {
            $this->attempt('emergencia@example.com', 'wrong-'.$i);
        }

        $props = $this->pageAfter($this->attempt('emergencia@example.com', 'wrong-5'));

        $this->assertSame(0, $props['attempts_left']);
        $this->assertSame(['locked_until' => now()->addMinutes(15)->toIso8601String()], $props['lockout']);

        // Still locked on the next try, even with the right password.
        $props = $this->pageAfter($this->attempt('emergencia@example.com', 'correct-horse-battery-staple'));
        $this->assertSame(0, $props['attempts_left']);
        $this->assertNotNull($props['lockout']);
        $this->assertGuest();
    }

    public function test_five_failures_lock_the_account_for_fifteen_minutes(): void
    {
        foreach (range(1, 5) as $i) {
            $this->attempt('emergencia@example.com', 'wrong-'.$i);
        }

        $this->assertNotNull($this->admin->fresh()->locked_until);

        $this->attempt('emergencia@example.com', 'correct-horse-battery-staple')
            ->assertSessionHasErrors('password');
        $this->assertGuest();

        $this->travel(14)->minutes();
        $this->attempt('emergencia@example.com', 'correct-horse-battery-staple')
            ->assertSessionHasErrors('password');
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->attempt('emergencia@example.com', 'correct-horse-battery-staple')
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($this->admin);

        $fresh = $this->admin->fresh();
        $this->assertSame(0, $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_persistent_lock_applies_from_another_ip_without_disclosing_it(): void
    {
        foreach (range(1, 5) as $i) {
            $this->attempt('emergencia@example.com', 'wrong-'.$i, '10.0.0.1');
        }

        $props = $this->pageAfter(
            $this->attempt('emergencia@example.com', 'correct-horse-battery-staple', '10.0.0.2')
                ->assertSessionHasErrors(['password' => self::GENERIC_ERROR])
        );

        // Same answer as any first failure from a new IP.
        $this->assertSame(4, $props['attempts_left']);
        $this->assertNull($props['lockout']);
        $this->assertGuest();
        $this->assertTrue(Activity::where('event', 'emergency_login_locked')->exists());
    }

    public function test_rate_limiter_blocks_after_five_failures_per_username_and_ip(): void
    {
        foreach (range(1, 5) as $i) {
            $this->attempt('nadie@example.com', 'wrong-'.$i);
        }

        $this->attempt('nadie@example.com', 'wrong-6')
            ->assertSessionHasErrors('password');

        $this->assertStringContainsString(
            'Demasiados intentos',
            session('errors')->first('password'),
        );
    }

    public function test_successful_login_resets_failed_attempts(): void
    {
        $this->attempt('emergencia@example.com', 'wrong');
        $this->attempt('emergencia@example.com', 'correct-horse-battery-staple');

        $this->assertSame(0, $this->admin->fresh()->failed_login_attempts);
    }

    public function test_disabled_when_no_emergency_email_is_configured(): void
    {
        config(['services.emergency.admin_email' => null]);

        $this->get(route('login.emergency'))->assertNotFound();
        $this->post(route('login.emergency.store'), [
            'username' => 'emergencia@example.com',
            'password' => 'correct-horse-battery-staple',
        ])->assertNotFound();

        $this->assertGuest();
    }

    public function test_validation_requires_username_and_password(): void
    {
        $this->from(route('login.emergency'))
            ->post(route('login.emergency.store'), [])
            ->assertSessionHasErrors(['username', 'password']);

        $this->from(route('login.emergency'))
            ->post(route('login.emergency.store'), ['username' => 'no-es-correo', 'password' => 'x'])
            ->assertSessionHasErrors(['username']);
    }

    public function test_old_route_names_are_gone(): void
    {
        $this->assertFalse(app('router')->has('emergency.login'));
        $this->assertFalse(app('router')->has('emergency.login.store'));
        $this->assertSame(url('/emergency-login'), route('login.emergency'));
    }
}
