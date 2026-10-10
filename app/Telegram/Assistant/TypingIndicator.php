<?php

namespace App\Telegram\Assistant;

use App\Services\Telegram\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\StepCompleted;

/**
 * "escribiendo…" while the Asistente TI works (telegram-format.md §2c):
 * `sendChatAction typing` when the work starts and again every 4 s (the
 * action lasts 5 s). PHP runs the answer synchronously, so the repeats
 * happen between steps: after each provider call (`StepCompleted`) and
 * before each tool (`InvokingTool`). Singleton.
 */
final class TypingIndicator
{
    public const EVERY_SECONDS = 4;

    private ?string $chatId = null;

    private ?CarbonImmutable $sentAt = null;

    private bool $listening = false;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly Dispatcher $events,
    ) {}

    public function start(string $chatId): void
    {
        $this->listen();
        $this->chatId = $chatId;
        $this->send();
    }

    /**
     * Sends `typing` again when the last one is about to expire.
     */
    public function pulse(): void
    {
        if ($this->chatId === null || ($this->sentAt !== null && $this->sentAt->addSeconds(self::EVERY_SECONDS)->isFuture())) {
            return;
        }

        $this->send();
    }

    public function stop(): void
    {
        $this->chatId = null;
        $this->sentAt = null;
    }

    private function send(): void
    {
        $this->sentAt = CarbonImmutable::now();
        $this->client->sendChatAction((string) $this->chatId, 'typing');
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;
        $this->events->listen([StepCompleted::class, InvokingTool::class], fn () => $this->pulse());
    }
}
