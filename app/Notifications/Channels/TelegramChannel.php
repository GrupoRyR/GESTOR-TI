<?php

namespace App\Notifications\Channels;

use App\Notifications\Messages\TelegramMessage;
use App\Services\Telegram\TelegramClient;
use Illuminate\Notifications\Notification;

/**
 * `telegram` notification channel (registered in AppServiceProvider).
 * The notification must implement `toTelegram($notifiable): TelegramMessage`;
 * the notifiable routes it with `routeNotificationForTelegram()` (chat id).
 */
class TelegramChannel
{
    public function __construct(private readonly TelegramClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $chatId = $notifiable->routeNotificationFor('telegram', $notification);

        if ($chatId === null || $chatId === '' || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        $message = $notification->toTelegram($notifiable);

        if (! $message instanceof TelegramMessage || $message->text === '') {
            return;
        }

        $this->client->sendMessage((string) $chatId, $message->text, $message->keyboard);
    }
}
