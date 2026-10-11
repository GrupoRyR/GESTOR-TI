<?php

namespace Tests\Feature\Assistant\Telegram;

use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Telegram\CallbackRegistry;
use App\Telegram\UpdateHandler;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Assistant\Chat\ChatTestCase;

/**
 * Asistente TI on Telegram (docs/design/telegram-format.md). Telegram and
 * the AI provider are ALWAYS faked: every Bot API call answers ok with a
 * new `message_id`; the provider answers from `provider()`.
 */
abstract class TelegramAssistantTestCase extends ChatTestCase
{
    protected const CHAT = '4242';

    protected int $updateId = 7000;

    protected int $nextMessageId = 500;

    /** @var list<string> Bot API methods that answer 400. */
    protected array $failing = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'hook-secret',
        ]);

        Http::fake(['api.telegram.org/*' => function (HttpRequest $request) {
            if (in_array((string) last(explode('/', $request->url())), $this->failing, true)) {
                return Http::response(['ok' => false, 'description' => 'Bad Request'], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => ++$this->nextMessageId]]);
        }]);
    }

    protected function link(User $user, string $chatId = self::CHAT): void
    {
        TelegramLink::query()->create(['user_id' => $user->id, 'chat_id' => $chatId, 'linked_at' => now()]);
    }

    protected function send(string $text, string $chatId = self::CHAT): void
    {
        $this->updateId++;

        (new UpdateHandler([
            'update_id' => $this->updateId,
            'message' => [
                'message_id' => $this->updateId,
                'chat' => ['id' => (int) $chatId, 'type' => 'private'],
                'from' => ['id' => (int) $chatId],
                'text' => $text,
            ],
        ]))->handle(app(TelegramClient::class), app(CallbackRegistry::class));
    }

    protected function press(string $data, int $messageId = 900, string $chatId = self::CHAT): void
    {
        $this->updateId++;

        (new UpdateHandler([
            'update_id' => $this->updateId,
            'callback_query' => [
                'id' => 'cb-'.$this->updateId,
                'from' => ['id' => (int) $chatId],
                'data' => $data,
                'message' => ['message_id' => $messageId, 'chat' => ['id' => (int) $chatId, 'type' => 'private'], 'text' => 'texto'],
            ],
        ]))->handle(app(TelegramClient::class), app(CallbackRegistry::class));
    }

    /**
     * Bot API calls of one method, in order (payload arrays).
     *
     * @return list<array<string, mixed>>
     */
    protected function telegram(string $method): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/'.$method))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    /**
     * Bot API methods called, in order.
     *
     * @return list<string>
     */
    protected function telegramMethods(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.telegram.org'))
            ->map(fn (array $pair) => (string) last(explode('/', $pair[0]->url())))
            ->values()
            ->all();
    }

    /**
     * The last sendMessage payload.
     *
     * @return array<string, mixed>
     */
    protected function lastSent(): array
    {
        $sent = $this->telegram('sendMessage');
        $this->assertNotEmpty($sent, 'No message was sent to Telegram.');

        return $sent[count($sent) - 1];
    }

    /**
     * The last answerCallbackQuery text.
     */
    protected function lastCallbackAnswer(): ?string
    {
        $answers = $this->telegram('answerCallbackQuery');
        $this->assertNotEmpty($answers);

        return $answers[count($answers) - 1]['text'] ?? null;
    }

    /**
     * Raw bodies sent to the AI provider only.
     */
    protected function providerRaw(): string
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.deepseek.com'))
            ->map(fn (array $pair) => $pair[0]->body())
            ->implode("\n");
    }

    protected function providerCalls(): int
    {
        return Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.deepseek.com'))->count();
    }

    /**
     * Callback data of every button of a sent message, by label.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, string>>
     */
    protected function buttons(array $payload): array
    {
        $buttons = [];
        foreach ((array) data_get($payload, 'reply_markup.inline_keyboard', []) as $row) {
            foreach ($row as $button) {
                $buttons[$button['text']] = $button;
            }
        }

        return $buttons;
    }

    protected function assertNoProviderCall(): void
    {
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'api.deepseek.com'));
    }
}
