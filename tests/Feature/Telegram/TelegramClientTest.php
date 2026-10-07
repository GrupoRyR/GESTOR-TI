<?php

namespace Tests\Feature\Telegram;

use App\Jobs\DeleteTelegramMessage;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'test-token']);
    }

    private function client(): TelegramClient
    {
        return app(TelegramClient::class);
    }

    private function fakeOk(mixed $result = ['message_id' => 42]): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => $result])]);
    }

    public function test_send_message_posts_to_the_bot_api_with_an_inline_keyboard(): void
    {
        $this->fakeOk();

        $result = $this->client()->sendMessage('123', 'Hola', [[['text' => 'Sí', 'callback_data' => 'x:1']]]);

        $this->assertSame(['message_id' => 42], $result);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
            && $request['chat_id'] === '123'
            && $request['text'] === 'Hola'
            && $request['reply_markup'] === ['inline_keyboard' => [[['text' => 'Sí', 'callback_data' => 'x:1']]]]);
    }

    public function test_send_message_without_keyboard_omits_reply_markup(): void
    {
        $this->fakeOk();

        $this->client()->sendMessage('123', 'Hola');

        Http::assertSent(fn (Request $request) => ! isset($request['reply_markup']));
    }

    public function test_other_methods_call_their_endpoints(): void
    {
        $this->fakeOk(['message_id' => 1]);

        $this->client()->editMessageText('123', 9, 'Editado');
        $this->client()->answerCallbackQuery('cb-1', 'Listo');
        $this->client()->sendChatAction('123');
        $this->assertTrue($this->client()->deleteMessage('123', 9));
        $this->client()->setWebhook('https://gestor.test/api/telegram/webhook/s', 'header-secret');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && $r['message_id'] === 9 && $r['text'] === 'Editado');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === 'cb-1' && $r['text'] === 'Listo');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendChatAction') && $r['action'] === 'typing');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deleteMessage') && $r['message_id'] === 9);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setWebhook')
            && $r['url'] === 'https://gestor.test/api/telegram/webhook/s'
            && $r['secret_token'] === 'header-secret'
            && $r['allowed_updates'] === ['message', 'callback_query']);
    }

    public function test_it_is_a_logged_no_op_without_token(): void
    {
        config(['services.telegram.bot_token' => null]);
        Http::fake();
        Log::spy();

        $this->assertFalse($this->client()->isConfigured());
        $this->assertNull($this->client()->sendMessage('123', 'Hola'));
        $this->assertFalse($this->client()->deleteMessage('123', 1));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_api_errors_return_null_and_never_log_the_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);
        Log::spy();

        $this->assertNull($this->client()->sendMessage('123', 'Hola'));

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
            return ! str_contains($message.json_encode($context), 'test-token');
        });
    }

    public function test_send_ephemeral_schedules_the_deletion_after_sixty_seconds(): void
    {
        $this->fakeOk(['message_id' => 77]);
        Queue::fake();
        $this->freezeTime();

        $this->client()->sendEphemeral('123', 'Clave: secreta');

        Queue::assertPushed(DeleteTelegramMessage::class, function (DeleteTelegramMessage $job) {
            return $job->chatId === '123'
                && $job->messageId === 77
                && now()->addSeconds(60)->equalTo($job->delay);
        });
    }

    public function test_send_ephemeral_does_not_schedule_when_sending_failed(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        Queue::fake();

        $this->assertNull($this->client()->sendEphemeral('123', 'Clave', 30));

        Queue::assertNothingPushed();
    }

    public function test_the_delete_job_calls_delete_message(): void
    {
        $this->fakeOk(true);

        (new DeleteTelegramMessage('123', 77))->handle($this->client());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deleteMessage') && $r['chat_id'] === '123' && $r['message_id'] === 77);
    }
}
