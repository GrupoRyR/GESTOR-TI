<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes a self-destructing Telegram message (`TelegramClient::sendEphemeral`).
 * Dispatched with a delay; the cron-driven database worker runs it on its
 * first pass after the delay (about one minute of precision).
 */
class DeleteTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30];

    public function __construct(
        public string $chatId,
        public int $messageId,
    ) {}

    public function handle(TelegramClient $client): void
    {
        $client->deleteMessage($this->chatId, $this->messageId);
    }
}
