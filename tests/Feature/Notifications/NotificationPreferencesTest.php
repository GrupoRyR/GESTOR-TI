<?php

namespace Tests\Feature\Notifications;

use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Perfil (`Profile/Edit`): profile data, Telegram state and the event ×
 * channel matrix saved one checkbox at a time.
 */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    /** Small catalog used by the page tests. */
    private const CATALOG = [
        'alert.critical' => [
            'label' => 'Alertas críticas',
            'hint' => 'Fallas que necesitan atención inmediata',
            'channels' => ['mail', 'telegram', 'database'],
            'required_channels' => ['database'],
        ],
        'vault.accessed' => [
            'label' => 'Acceso a la bóveda',
            'hint' => 'Cada vez que alguien abre la bóveda',
            'channels' => ['mail', 'database'],
        ],
    ];

    public function test_the_shipped_catalog_contains_the_known_event_types_with_hints(): void
    {
        $events = config('notifications.events');

        foreach (['alert.critical', 'alert.warning', 'alert.info', 'ai.request.status_changed', 'ai.approval.requested', 'vault.accessed'] as $key) {
            $this->assertArrayHasKey($key, $events);
            $this->assertNotEmpty($events[$key]['label']);
            $this->assertNotEmpty($events[$key]['hint']);
            $this->assertEmpty(array_diff($events[$key]['channels'], ['mail', 'telegram', 'database']));
        }
    }

    public function test_old_routes_are_gone(): void
    {
        $this->assertFalse(Route::has('profile.notifications.edit'));
        $this->assertFalse(Route::has('profile.telegram.link'));
        $this->assertFalse(Route::has('profile.telegram.unlink'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->put(route('profile.notifications.update'))->assertRedirect(route('login'));
    }

    public function test_edit_returns_exact_props(): void
    {
        config(['notifications.events' => self::CATALOG]);
        $company = Company::factory()->create(['name' => 'Segurex Latam']);
        $user = User::factory()->create(['company_id' => $company->id, 'name' => 'Ana Pérez', 'email' => 'ana@segurex.com']);
        Role::create(['name' => 'Usuario final']);
        $user->assignRole('Usuario final');
        $area = Area::factory()->for($company)->create(['name' => 'Ventas']);
        Employee::factory()->for($company)->create(['user_id' => $user->id, 'area_id' => $area->id]);
        NotificationPreference::create(['user_id' => $user->id, 'event_type' => 'vault.accessed', 'channels' => ['telegram']]);
        // A stored choice without the required channel still shows it on.
        NotificationPreference::create(['user_id' => $user->id, 'event_type' => 'alert.critical', 'channels' => ['mail']]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit', false)
                ->where('profile', [
                    'name' => 'Ana Pérez',
                    'email' => 'ana@segurex.com',
                    'company_name' => 'Segurex Latam',
                    'area_name' => 'Ventas',
                    'roles' => ['Usuario final'],
                ])
                ->where('telegram', ['linked' => false])
                ->where('notification_events', [
                    [
                        'key' => 'alert.critical',
                        'label' => 'Alertas críticas',
                        'hint' => 'Fallas que necesitan atención inmediata',
                        'channels' => ['mail' => true, 'telegram' => false, 'database' => true],
                    ],
                    [
                        'key' => 'vault.accessed',
                        'label' => 'Acceso a la bóveda',
                        'hint' => 'Cada vez que alguien abre la bóveda',
                        'channels' => ['mail' => false, 'telegram' => true, 'database' => false],
                    ],
                ])
            );
    }

    public function test_edit_without_company_area_or_roles(): void
    {
        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('profile.company_name', 'Sin empresa')
                ->where('profile.area_name', null)
                ->where('profile.roles', [])
            );
    }

    public function test_edit_reports_a_linked_telegram_chat(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        TelegramLink::create(['user_id' => $user->id, 'chat_id' => '123', 'telegram_username' => 'ana_p', 'linked_at' => now()]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('telegram', ['linked' => true, 'username' => 'ana_p', 'linked_at' => now()->toIso8601String()]));
    }

    public function test_a_pending_token_is_not_a_linked_chat(): void
    {
        $user = User::factory()->create();
        TelegramLink::issueToken($user);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('telegram', ['linked' => false]));
    }

    public function test_update_toggles_one_channel_of_one_event(): void
    {
        config(['notifications.events' => self::CATALOG]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->put(route('profile.notifications.update'), ['event' => 'vault.accessed', 'channel' => 'mail', 'enabled' => false])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        // Starts from the catalog default (mail, database).
        $this->assertSame(['database'], $this->stored($user, 'vault.accessed'));

        $this->put(route('profile.notifications.update'), ['event' => 'vault.accessed', 'channel' => 'mail', 'enabled' => true]);
        $this->assertSame(['mail', 'database'], $this->stored($user, 'vault.accessed'));

        $this->put(route('profile.notifications.update'), ['event' => 'vault.accessed', 'channel' => 'mail', 'enabled' => true]);
        $this->assertSame(['mail', 'database'], $this->stored($user, 'vault.accessed'));
        $this->assertSame(1, NotificationPreference::query()->where('user_id', $user->id)->count());
    }

    public function test_required_channels_cannot_be_disabled(): void
    {
        config(['notifications.events' => self::CATALOG]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.notifications.update'), ['event' => 'alert.critical', 'channel' => 'database', 'enabled' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channel' => 'Este aviso siempre llega a la campana; no se puede desactivar.']);

        $this->assertSame(0, NotificationPreference::query()->count());

        // Turning another channel of the event off still works.
        $this->put(route('profile.notifications.update'), ['event' => 'alert.critical', 'channel' => 'mail', 'enabled' => false])
            ->assertSessionHasNoErrors();
        $this->assertSame(['telegram', 'database'], $this->stored($user, 'alert.critical'));
    }

    public function test_telegram_cannot_be_enabled_without_a_linked_chat(): void
    {
        config(['notifications.events' => self::CATALOG]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.notifications.update'), ['event' => 'vault.accessed', 'channel' => 'telegram', 'enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channel' => 'Vincula Telegram para recibir avisos por ese canal.']);

        // Turning it off is always allowed.
        $this->put(route('profile.notifications.update'), ['event' => 'alert.critical', 'channel' => 'telegram', 'enabled' => false])
            ->assertSessionHasNoErrors();

        TelegramLink::create(['user_id' => $user->id, 'chat_id' => '123', 'linked_at' => now()]);

        $this->put(route('profile.notifications.update'), ['event' => 'vault.accessed', 'channel' => 'telegram', 'enabled' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(['mail', 'telegram', 'database'], $this->stored($user, 'vault.accessed'));
    }

    public function test_update_rejects_unknown_events_channels_and_missing_flags(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.notifications.update'), ['event' => 'nope.event', 'channel' => 'sms'])
            ->assertSessionHasErrors(['event', 'channel', 'enabled']);

        $this->put(route('profile.notifications.update'), ['event' => 'alert.info', 'channel' => 'mail', 'enabled' => 'maybe'])
            ->assertSessionHasErrors(['enabled']);

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    /**
     * @return list<string>|null
     */
    private function stored(User $user, string $event): ?array
    {
        return $user->notificationPreferences()->where('event_type', $event)->first()?->channels;
    }
}
