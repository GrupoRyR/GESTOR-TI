<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Event catalog (config/notifications.php) and per-user channel choices.
 */
class NotificationPreferences
{
    /**
     * Channels used for event types missing from the catalog.
     */
    public const FALLBACK_CHANNELS = ['database'];

    /**
     * @return array<string, array{label: string, channels: list<string>}>
     */
    public function catalog(): array
    {
        return (array) config('notifications.events', []);
    }

    /**
     * @return list<string>
     */
    public function supportedChannels(): array
    {
        return array_values((array) config('notifications.channels', ['mail', 'telegram', 'database']));
    }

    /**
     * @return list<string>
     */
    public function defaultChannels(string $eventType): array
    {
        $event = $this->catalog()[$eventType] ?? null;

        return $event === null ? self::FALLBACK_CHANNELS : $this->normalize($event['channels'] ?? []);
    }

    /**
     * Channels the user receives for the event: their choice, else the
     * catalog default. Ordered as `supportedChannels()`.
     *
     * @return list<string>
     */
    public function channelsFor(User $user, string $eventType): array
    {
        if (! array_key_exists($eventType, $this->catalog())) {
            return self::FALLBACK_CHANNELS;
        }

        $preference = $user->relationLoaded('notificationPreferences')
            ? $user->notificationPreferences->firstWhere('event_type', $eventType)
            : $user->notificationPreferences()->where('event_type', $eventType)->first();

        return $preference === null
            ? $this->defaultChannels($eventType)
            : $this->normalize($preference->channels ?? []);
    }

    /**
     * Rows of the preferences page.
     *
     * @return list<array{key: string, label: string, channels: list<string>, default_channels: list<string>}>
     */
    public function forUser(User $user): array
    {
        $stored = $user->notificationPreferences()->get()->keyBy('event_type');
        $rows = [];

        foreach ($this->catalog() as $key => $event) {
            $default = $this->normalize($event['channels'] ?? []);
            $preference = $stored->get($key);

            $rows[] = [
                'key' => $key,
                'label' => $event['label'],
                'channels' => $preference === null ? $default : $this->normalize($preference->channels ?? []),
                'default_channels' => $default,
            ];
        }

        return $rows;
    }

    /**
     * Stores the given choices (event type => channels); events not given
     * keep their current choice. Input must be validated beforehand.
     *
     * @param  array<string, list<string>>  $preferences
     */
    public function update(User $user, array $preferences): void
    {
        DB::transaction(function () use ($user, $preferences): void {
            foreach ($preferences as $eventType => $channels) {
                NotificationPreference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'event_type' => $eventType],
                    ['channels' => $this->normalize($channels)],
                );
            }
        });
    }

    /**
     * Supported channels only, without duplicates, in catalog order.
     *
     * @param  array<int, mixed>  $channels
     * @return list<string>
     */
    private function normalize(array $channels): array
    {
        return array_values(array_intersect($this->supportedChannels(), $channels));
    }
}
