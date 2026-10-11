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
     * @return array<string, array{label: string, hint?: string, channels: list<string>, required_channels?: list<string>}>
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
     * catalog default, plus the event's required channels. Ordered as
     * `supportedChannels()`.
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

        $channels = $preference === null
            ? $this->defaultChannels($eventType)
            : $this->normalize($preference->channels ?? []);

        return $this->normalize(array_merge($channels, $this->requiredChannels($eventType)));
    }

    /**
     * Channels that are always used for the event, whatever the user chose
     * (e.g. critical alerts always reach the bell).
     *
     * @return list<string>
     */
    public function requiredChannels(string $eventType): array
    {
        return $this->normalize((array) ($this->catalog()[$eventType]['required_channels'] ?? []));
    }

    /**
     * Rows of `Profile/Edit` (`NotificationEvent[]`): what the user
     * receives per channel (their choice, else the default, plus the
     * required channels), in catalog order.
     *
     * @return list<array{key: string, label: string, hint: string, channels: array<string, bool>}>
     */
    public function matrixFor(User $user): array
    {
        $user->loadMissing('notificationPreferences');
        $rows = [];

        foreach ($this->catalog() as $key => $event) {
            $enabled = $this->channelsFor($user, $key);
            $channels = [];

            foreach ($this->supportedChannels() as $channel) {
                $channels[$channel] = in_array($channel, $enabled, true);
            }

            $rows[] = [
                'key' => $key,
                'label' => $event['label'],
                'hint' => (string) ($event['hint'] ?? ''),
                'channels' => $channels,
            ];
        }

        return $rows;
    }

    /**
     * Turns one channel of one event on or off, starting from what the user
     * currently receives. Input must be validated beforehand (catalog event,
     * supported channel, not disabling a required channel).
     */
    public function toggle(User $user, string $eventType, string $channel, bool $enabled): void
    {
        DB::transaction(function () use ($user, $eventType, $channel, $enabled): void {
            $preference = NotificationPreference::query()
                ->where('user_id', $user->id)
                ->where('event_type', $eventType)
                ->lockForUpdate()
                ->first();

            $current = $preference === null
                ? $this->defaultChannels($eventType)
                : $this->normalize($preference->channels ?? []);

            $channels = $enabled
                ? array_merge($current, [$channel])
                : array_diff($current, [$channel]);

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'event_type' => $eventType],
                ['channels' => $this->normalize($channels)],
            );
        });

        $user->unsetRelation('notificationPreferences');
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
