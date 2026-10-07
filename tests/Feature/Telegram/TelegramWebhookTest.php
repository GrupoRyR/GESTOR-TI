<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Telegram\CallbackRegistry;
use App\Telegram\Contracts\CallbackHandler;
use App\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'hook-secret-123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_username' => 'GestorRyrBot',
            'services.telegram.webhook_secret' => self::SECRET,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        FakeApprovalHandler::$calls = [];
    }

    /**
     * @param  array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function callbackUpdate(string $data, int $chatId = 4242, array $callback = []): array
    {
        return [
            'update_id' => 5,
            'callback_query' => $callback + [
                'id' => 'cb-77',
                'from' => ['id' => $chatId, 'username' => 'ana_p'],
                'data' => $data,
                'message' => ['message_id' => 31, 'chat' => ['id' => $chatId, 'type' => 'private']],
            ],
        ];
    }

    public function test_the_webhook_answers_immediately_and_handles_the_update_after_the_response(): void
    {
        Bus::fake();
        $update = ['update_id' => 1, 'message' => ['message_id' => 1, 'text' => '/start', 'chat' => ['id' => 1]]];

        $this->postJson('/api/telegram/webhook/'.self::SECRET, $update)
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        Bus::assertDispatchedAfterResponse(UpdateHandler::class, fn (UpdateHandler $job) => $job->update === $update);
        Http::assertNothingSent();
    }

    public function test_the_update_can_be_sent_to_the_queue_instead(): void
    {
        config(['gestor.telegram.processing' => 'queue']);
        Queue::fake();

        $this->postJson('/api/telegram/webhook/'.self::SECRET, ['update_id' => 2])->assertOk();

        Queue::assertPushed(UpdateHandler::class);
    }

    public function test_the_webhook_route_is_csrf_free_and_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('telegram.webhook');

        $this->assertNotNull($route);
        $this->assertSame('api/telegram/webhook/{secret}', $route->uri());
        $this->assertContains('api', $route->gatherMiddleware());
        $this->assertNotContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:telegram-webhook', $route->gatherMiddleware());
    }

    public function test_callback_queries_are_routed_through_the_registry(): void
    {
        $user = User::factory()->create();
        TelegramLink::create(['user_id' => $user->id, 'chat_id' => '4242', 'linked_at' => now()]);
        app(CallbackRegistry::class)->register('approval', FakeApprovalHandler::class);

        $this->postJson('/api/telegram/webhook/'.self::SECRET, $this->callbackUpdate('approval:GIA-12:yes'))->assertOk();

        $this->assertCount(1, FakeApprovalHandler::$calls);
        $this->assertSame($user->id, FakeApprovalHandler::$calls[0]['user']);
        $this->assertSame('GIA-12:yes', FakeApprovalHandler::$calls[0]['payload']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && $r['callback_query_id'] === 'cb-77'
            && $r['text'] === 'Aprobado');
    }

    public function test_callback_handlers_can_be_registered_from_the_config(): void
    {
        config(['gestor.telegram.callbacks' => ['approval' => FakeApprovalHandler::class]]);
        $this->app->forgetInstance(CallbackRegistry::class);

        $resolved = app(CallbackRegistry::class)->resolve('approval:1');

        $this->assertInstanceOf(FakeApprovalHandler::class, $resolved[0]);
        $this->assertSame('1', $resolved[1]);
        $this->assertNull(app(CallbackRegistry::class)->resolve('other:1'));
        $this->assertNull(app(CallbackRegistry::class)->resolve('no-separator'));
    }

    public function test_registering_a_class_that_is_not_a_handler_fails(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CallbackRegistry::class)->register('bad', \stdClass::class);
    }

    public function test_callbacks_from_unlinked_chats_are_not_dispatched(): void
    {
        app(CallbackRegistry::class)->register('approval', FakeApprovalHandler::class);

        $this->postJson('/api/telegram/webhook/'.self::SECRET, $this->callbackUpdate('approval:GIA-12:yes', 999))->assertOk();

        $this->assertSame([], FakeApprovalHandler::$calls);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && str_contains((string) $r['text'], 'no está vinculado'));
        $this->assertSame(0, TelegramLink::query()->count());
    }

    public function test_unknown_callback_prefixes_are_answered(): void
    {
        $user = User::factory()->create();
        TelegramLink::create(['user_id' => $user->id, 'chat_id' => '4242', 'linked_at' => now()]);

        $this->postJson('/api/telegram/webhook/'.self::SECRET, $this->callbackUpdate('nope:1'))->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && $r['text'] === 'Esta acción ya no está disponible.');
    }

    public function test_a_redelivered_update_is_handled_once(): void
    {
        $update = ['update_id' => 900, 'message' => ['message_id' => 1, 'text' => '/ayuda', 'chat' => ['id' => 1, 'type' => 'private']]];

        (new UpdateHandler($update))->handle(app(TelegramClient::class), app(CallbackRegistry::class));
        (new UpdateHandler($update))->handle(app(TelegramClient::class), app(CallbackRegistry::class));

        Http::assertSentCount(1);
    }

    public function test_messages_from_group_chats_are_ignored(): void
    {
        $update = ['update_id' => 901, 'message' => ['message_id' => 1, 'text' => '/start abc', 'chat' => ['id' => -100, 'type' => 'group']]];

        $this->postJson('/api/telegram/webhook/'.self::SECRET, $update)->assertOk();

        Http::assertNothingSent();
    }

    public function test_malformed_updates_are_ignored(): void
    {
        $this->postJson('/api/telegram/webhook/'.self::SECRET, ['update_id' => 3, 'edited_message' => ['text' => 'x']])->assertOk();
        $this->postJson('/api/telegram/webhook/'.self::SECRET, ['message' => 'not-an-array'])->assertOk();

        Http::assertNothingSent();
    }
}

class FakeApprovalHandler implements CallbackHandler
{
    /** @var list<array{user: int, payload: string}> */
    public static array $calls = [];

    public function handle(User $user, string $payload, array $callbackQuery): ?string
    {
        self::$calls[] = ['user' => $user->id, 'payload' => $payload];

        return 'Aprobado';
    }
}
