<?php

namespace Tests\Feature\Auth;

use App\Models\Alert;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\AlertRaised;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Locking the emergency account raises a critical `auth` alert, so the
 * alert recipients hear about it by Telegram, mail and the bell.
 */
class EmergencyLockoutAlertTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private const WRONG = 'Wr0ng-Guess-Visible?';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.emergency.admin_email' => 'emergencia@example.com']);

        User::factory()->create(['email' => 'emergencia@example.com', 'password' => self::PASSWORD]);

        Permission::create(['name' => 'ai.manage']);
        $this->manager = User::factory()->create(['company_id' => null, 'is_global' => true]);
        $this->manager->givePermissionTo('ai.manage');
    }

    private function failAttempts(string $username, string $ip, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post(route('login.emergency.store'), ['username' => $username, 'password' => self::WRONG]);
        }
    }

    public function test_locking_the_emergency_account_raises_one_critical_alert(): void
    {
        $this->failAttempts('emergencia@example.com', '203.0.113.7', 4);
        $this->assertSame(0, Alert::query()->count());

        $this->failAttempts('emergencia@example.com', '203.0.113.7');

        $alert = Alert::query()->sole();
        $this->assertSame(Alert::LEVEL_CRITICAL, $alert->level);
        $this->assertSame('auth', $alert->source);
        $this->assertSame('auth.emergency_lockout', $alert->group_key);
        $this->assertNull($alert->company_id);
        $this->assertSame(1, $alert->occurrences);
        $this->assertSame('Cuenta de emergencia bloqueada', $alert->title);
        $this->assertStringContainsString('203.0.113.7', (string) $alert->body);
        $this->assertStringContainsString('15 minutos', (string) $alert->body);
        $this->assertStringNotContainsString(self::WRONG, (string) $alert->body);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $alert->body);

        Notification::assertSentTo($this->manager, AlertRaised::class);
    }

    public function test_attempts_while_locked_do_not_raise_again(): void
    {
        $this->failAttempts('emergencia@example.com', '203.0.113.7', 8);

        $this->assertSame(1, Alert::query()->sole()->occurrences);
    }

    public function test_a_later_lockout_is_grouped_in_the_same_alert(): void
    {
        $this->failAttempts('emergencia@example.com', '203.0.113.7', 5);
        $this->travel(16)->minutes();
        $this->failAttempts('emergencia@example.com', '198.51.100.9', 5);

        $alert = Alert::query()->sole();
        $this->assertSame(2, $alert->occurrences);
        $this->assertStringContainsString('198.51.100.9', (string) $alert->body);
    }

    public function test_other_usernames_never_raise_alerts(): void
    {
        $this->failAttempts('nadie@example.com', '203.0.113.7', 6);

        $this->assertSame(0, Alert::query()->count());
        Notification::assertNothingSent();
    }
}
