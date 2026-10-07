<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function attempt(string $email, string $password, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from(route('emergency.login'))
            ->post(route('emergency.login.store'), ['email' => $email, 'password' => $password]);
    }

    public function test_page_renders_with_empty_props(): void
    {
        $this->get(route('emergency.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/EmergencyLogin', false));
    }

    public function test_configured_email_logs_in(): void
    {
        $this->attempt('EMERGENCIA@example.com', 'correct-horse-battery-staple')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->admin);
        $this->assertNotNull($this->admin->fresh()->last_login_at);
        $this->assertTrue(Activity::where('log_name', 'auth')->where('event', 'emergency_login_succeeded')->exists());
    }

    public function test_other_email_gets_generic_error_even_with_valid_password(): void
    {
        $other = User::factory()->create(['email' => 'otro@example.com', 'password' => 'otro-password-123']);

        $this->attempt('otro@example.com', 'otro-password-123')
            ->assertRedirect(route('emergency.login'))
            ->assertSessionHasErrors(['email' => self::GENERIC_ERROR]);

        $this->attempt('nadie@example.com', 'whatever')
            ->assertSessionHasErrors(['email' => self::GENERIC_ERROR]);

        $this->assertGuest();
        $this->assertSame(2, Activity::where('log_name', 'auth')->where('event', 'emergency_login_failed')->count());
        $this->assertSame(0, $other->fresh()->failed_login_attempts);
    }

    public function test_wrong_password_gets_generic_error_and_counts(): void
    {
        $this->attempt('emergencia@example.com', 'wrong')
            ->assertSessionHasErrors(['email' => self::GENERIC_ERROR]);

        $this->assertGuest();
        $this->assertSame(1, $this->admin->fresh()->failed_login_attempts);
    }

    public function test_five_failures_lock_the_account_for_fifteen_minutes(): void
    {
        foreach (range(1, 5) as $i) {
            $this->attempt('emergencia@example.com', 'wrong-'.$i);
        }

        $this->assertNotNull($this->admin->fresh()->locked_until);

        $this->attempt('emergencia@example.com', 'correct-horse-battery-staple')
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->travel(14)->minutes();
        $this->attempt('emergencia@example.com', 'correct-horse-battery-staple')
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->attempt('emergencia@example.com', 'correct-horse-battery-staple')
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->admin);

        $fresh = $this->admin->fresh();
        $this->assertSame(0, $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
    }

    public function test_persistent_lock_applies_from_another_ip(): void
    {
        foreach (range(1, 5) as $i) {
            $this->attempt('emergencia@example.com', 'wrong-'.$i, '10.0.0.1');
        }

        $this->attempt('emergencia@example.com', 'correct-horse-battery-staple', '10.0.0.2')
            ->assertSessionHasErrors(['email' => self::GENERIC_ERROR]);

        $this->assertGuest();
        $this->assertTrue(Activity::where('event', 'emergency_login_locked')->exists());
    }

    public function test_rate_limiter_blocks_after_five_failures_per_email_and_ip(): void
    {
        foreach (range(1, 5) as $i) {
            $this->attempt('nadie@example.com', 'wrong-'.$i);
        }

        $this->attempt('nadie@example.com', 'wrong-6')
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Demasiados intentos',
            session('errors')->first('email'),
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

        $this->get(route('emergency.login'))->assertNotFound();
        $this->post(route('emergency.login.store'), [
            'email' => 'emergencia@example.com',
            'password' => 'correct-horse-battery-staple',
        ])->assertNotFound();

        $this->assertGuest();
    }

    public function test_validation_requires_email_and_password(): void
    {
        $this->from(route('emergency.login'))
            ->post(route('emergency.login.store'), [])
            ->assertSessionHasErrors(['email', 'password']);
    }
}
