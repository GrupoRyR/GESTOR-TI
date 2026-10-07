<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shipped_catalog_contains_the_known_event_types(): void
    {
        $events = config('notifications.events');

        foreach (['alert.critical', 'alert.warning', 'alert.info', 'ai.request.status_changed', 'ai.approval.requested', 'vault.accessed'] as $key) {
            $this->assertArrayHasKey($key, $events);
            $this->assertNotEmpty($events[$key]['label']);
            $this->assertEmpty(array_diff($events[$key]['channels'], ['mail', 'telegram', 'database']));
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('profile.notifications.edit'))->assertRedirect(route('login'));
        $this->put(route('profile.notifications.update'))->assertRedirect(route('login'));
    }

    public function test_edit_returns_the_events_with_defaults_and_preferences(): void
    {
        config(['notifications.events' => [
            'alert.critical' => ['label' => 'Alertas críticas', 'channels' => ['mail', 'telegram', 'database']],
            'vault.accessed' => ['label' => 'Acceso a la bóveda', 'channels' => ['mail', 'database']],
        ]]);
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'event_type' => 'vault.accessed', 'channels' => ['telegram']]);

        $this->actingAs($user)
            ->get(route('profile.notifications.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Notifications')
                ->has('events', 2)
                ->where('events.0', [
                    'key' => 'alert.critical',
                    'label' => 'Alertas críticas',
                    'channels' => ['mail', 'telegram', 'database'],
                    'default_channels' => ['mail', 'telegram', 'database'],
                ])
                ->where('events.1', [
                    'key' => 'vault.accessed',
                    'label' => 'Acceso a la bóveda',
                    'channels' => ['telegram'],
                    'default_channels' => ['mail', 'database'],
                ])
                ->where('telegram', ['linked' => false, 'username' => null, 'link_url' => null]));
    }

    public function test_edit_reports_a_linked_telegram_chat(): void
    {
        $user = User::factory()->create();
        TelegramLink::create(['user_id' => $user->id, 'chat_id' => '123', 'telegram_username' => 'ana_p', 'linked_at' => now()]);

        $this->actingAs($user)
            ->get(route('profile.notifications.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('telegram', ['linked' => true, 'username' => 'ana_p', 'link_url' => null]));
    }

    public function test_update_stores_the_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('profile.notifications.edit'))
            ->put(route('profile.notifications.update'), ['preferences' => [
                'alert.critical' => ['telegram', 'database'],
                'alert.info' => [],
            ]])
            ->assertRedirect(route('profile.notifications.edit'))
            ->assertSessionHas('success', 'Preferencias de notificación guardadas.');

        $this->assertSame(['telegram', 'database'], $user->notificationPreferences()->where('event_type', 'alert.critical')->first()->channels);
        $this->assertSame([], $user->notificationPreferences()->where('event_type', 'alert.info')->first()->channels);

        // Updating again replaces the stored channels (unique user + event).
        $this->actingAs($user)->put(route('profile.notifications.update'), ['preferences' => ['alert.critical' => ['mail']]]);
        $this->assertSame(1, NotificationPreference::query()->where('user_id', $user->id)->where('event_type', 'alert.critical')->count());
        $this->assertSame(['mail'], $user->notificationPreferences()->where('event_type', 'alert.critical')->first()->channels);
    }

    public function test_update_rejects_unknown_events_and_channels(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.notifications.update'), ['preferences' => ['nope.event' => ['mail']]])
            ->assertSessionHasErrors('preferences');

        $this->actingAs($user)
            ->put(route('profile.notifications.update'), ['preferences' => ['alert.critical' => ['sms']]])
            ->assertSessionHasErrors('preferences.alert.critical.0');

        $this->assertSame(0, NotificationPreference::query()->count());
    }
}
