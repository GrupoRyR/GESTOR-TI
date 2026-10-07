<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequiredChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_alerts_always_reach_the_bell_even_with_every_channel_disabled(): void
    {
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'event_type' => 'alert.critical', 'channels' => []]);
        NotificationPreference::create(['user_id' => $user->id, 'event_type' => 'alert.warning', 'channels' => []]);

        $preferences = app(NotificationPreferences::class);

        $this->assertSame(['database'], $preferences->channelsFor($user->fresh(), 'alert.critical'));
        $this->assertSame([], $preferences->channelsFor($user->fresh(), 'alert.warning'));
    }

    public function test_required_channels_are_added_to_the_user_choice(): void
    {
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'event_type' => 'alert.critical', 'channels' => ['mail']]);

        $this->assertSame(['mail', 'database'], app(NotificationPreferences::class)->channelsFor($user->fresh(), 'alert.critical'));
    }
}
