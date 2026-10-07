<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TelegramLinkTest extends TestCase
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
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    /**
     * Generates a link token through the endpoint and returns the plain token.
     */
    private function generateToken(User $user): string
    {
        $response = $this->actingAs($user)
            ->from(route('profile.notifications.edit'))
            ->post(route('profile.telegram.link'))
            ->assertRedirect(route('profile.notifications.edit'));

        $url = $response->getSession()->get('telegram_link_url');
        $this->assertStringStartsWith('https://t.me/GestorRyrBot?start=', $url);

        return substr($url, strlen('https://t.me/GestorRyrBot?start='));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function sendUpdate(array $message, string $secret = self::SECRET): TestResponse
    {
        return $this->postJson('/api/telegram/webhook/'.$secret, [
            'update_id' => random_int(1, 1_000_000),
            'message' => $message + [
                'message_id' => 10,
                'chat' => ['id' => 4242, 'type' => 'private'],
                'from' => ['id' => 4242, 'username' => 'ana_p'],
            ],
        ]);
    }

    private function sentText(string $needle): bool
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains((string) $r['text'], $needle))->isNotEmpty();
    }

    public function test_link_generates_a_hashed_single_use_token_valid_for_thirty_minutes(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();

        $token = $this->generateToken($user);

        $link = TelegramLink::query()->where('user_id', $user->id)->first();
        $this->assertNotSame($token, $link->link_token);
        $this->assertSame(hash('sha256', $token), $link->link_token);
        $this->assertTrue($link->token_expires_at->equalTo(now()->addMinutes(30)));
        $this->assertNull($link->chat_id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32,64}$/', $token);
    }

    public function test_the_link_url_is_shared_on_the_notifications_page_after_generation(): void
    {
        $user = User::factory()->create();
        $token = $this->generateToken($user);

        $this->actingAs($user)
            ->withSession(['telegram_link_url' => 'https://t.me/GestorRyrBot?start='.$token])
            ->get(route('profile.notifications.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('telegram.linked', false)
                ->where('telegram.link_url', 'https://t.me/GestorRyrBot?start='.$token));
    }

    public function test_link_fails_when_the_bot_is_not_configured(): void
    {
        config(['services.telegram.bot_username' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.telegram.link'))
            ->assertSessionHas('error', 'El bot de Telegram no está configurado.');

        $this->assertSame(0, TelegramLink::query()->count());
    }

    public function test_guests_cannot_generate_links(): void
    {
        $this->post(route('profile.telegram.link'))->assertRedirect(route('login'));
    }

    public function test_webhook_with_a_wrong_or_missing_secret_returns_404(): void
    {
        $this->sendUpdate(['text' => '/start'], 'wrong-secret')->assertNotFound();

        config(['services.telegram.webhook_secret' => null]);
        $this->sendUpdate(['text' => '/start'], 'anything')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_webhook_rejects_a_mismatching_secret_header(): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'other')
            ->postJson('/api/telegram/webhook/'.self::SECRET, ['update_id' => 1])
            ->assertNotFound();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook/'.self::SECRET, ['update_id' => 1])
            ->assertOk();
    }

    public function test_webhook_requires_the_header_when_configured(): void
    {
        config(['services.telegram.require_secret_header' => true]);

        $this->postJson('/api/telegram/webhook/'.self::SECRET, ['update_id' => 1])->assertNotFound();
    }

    public function test_start_with_a_valid_token_links_the_chat(): void
    {
        $user = User::factory()->create();
        $token = $this->generateToken($user);

        $this->sendUpdate(['text' => '/start '.$token])->assertOk();

        $link = TelegramLink::query()->where('user_id', $user->id)->first();
        $this->assertSame('4242', $link->chat_id);
        $this->assertSame('ana_p', $link->telegram_username);
        $this->assertNotNull($link->linked_at);
        $this->assertNull($link->link_token);
        $this->assertNull($link->token_expires_at);
        $this->assertSame('4242', $user->fresh()->routeNotificationFor('telegram'));
        $this->assertTrue($this->sentText('vinculada'));
    }

    public function test_a_used_token_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $token = $this->generateToken($user);
        $this->sendUpdate(['text' => '/start '.$token]);

        $this->postJson('/api/telegram/webhook/'.self::SECRET, [
            'update_id' => 99,
            'message' => ['message_id' => 11, 'text' => '/start '.$token, 'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777]],
        ])->assertOk();

        $this->assertSame('4242', TelegramLink::query()->where('user_id', $user->id)->value('chat_id'));
        $this->assertNull(TelegramLink::query()->where('chat_id', '777')->first());
        $this->assertTrue($this->sentText('no es válido o ya venció'));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $this->generateToken($user);

        $this->travel(31)->minutes();
        $this->sendUpdate(['text' => '/start '.$token])->assertOk();

        $this->assertNull(TelegramLink::query()->where('user_id', $user->id)->value('chat_id'));
        $this->assertTrue($this->sentText('no es válido o ya venció'));
    }

    public function test_a_chat_linked_to_another_user_is_not_taken_over(): void
    {
        $owner = User::factory()->create();
        TelegramLink::create(['user_id' => $owner->id, 'chat_id' => '4242', 'linked_at' => now()]);
        $user = User::factory()->create();
        $token = $this->generateToken($user);

        $this->sendUpdate(['text' => '/start '.$token])->assertOk();

        $this->assertNull(TelegramLink::query()->where('user_id', $user->id)->value('chat_id'));
        $this->assertSame($owner->id, TelegramLink::query()->where('chat_id', '4242')->value('user_id'));
        $this->assertTrue($this->sentText('ya está vinculado'));
    }

    public function test_start_without_token_and_unknown_commands_reply_with_help(): void
    {
        $this->sendUpdate(['text' => '/start'])->assertOk();
        $this->sendUpdate(['text' => '/desconocido'])->assertOk();

        $help = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains((string) $r['text'], 'Perfil'));
        $this->assertCount(2, $help);
        $this->assertSame(0, TelegramLink::query()->count());
    }

    public function test_unlink_removes_the_link(): void
    {
        $user = User::factory()->create();
        TelegramLink::create(['user_id' => $user->id, 'chat_id' => '4242', 'linked_at' => now()]);

        $this->actingAs($user)
            ->from(route('profile.notifications.edit'))
            ->delete(route('profile.telegram.unlink'))
            ->assertRedirect(route('profile.notifications.edit'))
            ->assertSessionHas('success', 'Telegram desvinculado.');

        $this->assertSame(0, TelegramLink::query()->count());
        $this->assertNull($user->fresh()->routeNotificationFor('telegram'));
    }
}
