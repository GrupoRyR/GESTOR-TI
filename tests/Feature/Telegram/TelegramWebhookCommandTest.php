<?php

namespace Tests\Feature\Telegram;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://gestor.example.com',
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'hook_secret-123',
        ]);
    }

    public function test_it_registers_the_webhook_with_the_secret_in_the_url_and_header(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->artisan('gestor:telegram-webhook')
            ->expectsOutputToContain('Webhook de Telegram registrado')
            ->doesntExpectOutputToContain('hook_secret-123')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setWebhook')
            && $r['url'] === 'https://gestor.example.com/api/telegram/webhook/hook_secret-123'
            && $r['secret_token'] === 'hook_secret-123');
    }

    public function test_the_header_secret_is_omitted_when_the_secret_has_unsupported_characters(): void
    {
        config(['services.telegram.webhook_secret' => 'with spaces!']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->artisan('gestor:telegram-webhook')->assertSuccessful();

        Http::assertSent(fn (Request $r) => ! isset($r['secret_token']));
    }

    public function test_it_fails_without_token_or_secret(): void
    {
        Http::fake();
        config(['services.telegram.webhook_secret' => null]);

        $this->artisan('gestor:telegram-webhook')
            ->expectsOutputToContain('TELEGRAM_WEBHOOK_SECRET')
            ->assertFailed();

        config(['services.telegram.webhook_secret' => 'x', 'services.telegram.bot_token' => null]);

        $this->artisan('gestor:telegram-webhook')
            ->expectsOutputToContain('TELEGRAM_BOT_TOKEN')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_fails_when_telegram_rejects_the_webhook(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad'], 400)]);

        $this->artisan('gestor:telegram-webhook')->assertFailed();
    }
}
