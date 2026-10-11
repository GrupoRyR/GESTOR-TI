<?php

namespace App\Jobs;

use App\Models\EmployeeAccess;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes a self-destructing Telegram message (`TelegramClient::sendEphemeral`).
 * Dispatched with a delay; the cron-driven database worker runs it on its
 * first pass after the delay (about one minute of precision).
 *
 * When `deleteMessage` fails (older than 48 h, missing rights, network) the
 * message is edited to `$fallbackText` ("Mensaje borrado por seguridad",
 * telegram-format.md §4). If both fail it is retried. With `$accessId`
 * (a delivered password) the outcome is audited: `credentials`,
 * `credential_message_deleted`, outcome deleted | edited | failed.
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
        public ?string $fallbackText = null,
        public ?int $accessId = null,
        public ?int $causerId = null,
    ) {}

    public function handle(TelegramClient $client): void
    {
        $outcome = match (true) {
            $client->deleteMessage($this->chatId, $this->messageId) => 'deleted',
            $this->fallbackText !== null && $client->editMessageText($this->chatId, $this->messageId, $this->fallbackText) => 'edited',
            default => 'failed',
        };

        if ($outcome === 'failed' && $this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 30);

            return;
        }

        $this->audit($outcome);
    }

    private function audit(string $outcome): void
    {
        if ($this->accessId === null) {
            return;
        }

        $log = activity('credentials')
            ->event('credential_message_deleted')
            ->withProperties(['channel' => 'telegram', 'outcome' => $outcome, 'message_id' => $this->messageId]);

        $causer = $this->causerId === null ? null : User::query()->find($this->causerId);
        if ($causer !== null) {
            $log->causedBy($causer);
        }

        $access = EmployeeAccess::query()->find($this->accessId);
        if ($access !== null) {
            $log->performedOn($access);
        }

        $log->log('credential_message_deleted');
    }
}
