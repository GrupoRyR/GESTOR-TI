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

    private const PREFIX = 'https://t.me/GestorRyrBot?start=';

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
     * Opens `Profile/TelegramLink` (which issues a token when the session has
     * none) and returns the plain token of its deep link.
     */
    private function generateToken(User $user): string
    {
        $url = $this->linkPage($user)['link_url'];
        $this->assertStringStartsWith(self::PREFIX, $url);

        return substr($url, strlen(self::PREFIX));
    }

    /**
     * @return array<string, mixed>
     */
    private function linkPage(User $user): array
    {
        $response = $this->actingAs($user)->get(route('profile.telegram.create'))->assertOk();

        return $response->viewData('page')['props'];
    }

    /**
     * Partial reload as `router.reload({ only: ['status'] })` sends it.
     *
     * @return array<string, mixed>
     */
    private function reloadStatus(): array
    {
        $version = $this->get(route('profile.telegram.create'))->viewData('page')['version'];

        $props = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Profile/TelegramLink',
            'X-Inertia-Partial-Data' => 'status',
        ])->get(route('profile.telegram.create'))->assertOk()->json('props');

        // `withHeaders()` sticks to later requests of the test.
        $this->flushHeaders();

        return $props;
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

    public function test_the_link_page_issues_a_hashed_single_use_token_valid_for_thirty_minutes(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.telegram.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/TelegramLink', false)
                ->where('link_url', fn (string $url) => str_starts_with($url, self::PREFIX))
                ->where('expires_at', now()->addMinutes(30)->toIso8601String())
                ->where('status', 'waiting')
            );

        $token = substr($this->linkPage($user)['link_url'], strlen(self::PREFIX));
        $link = TelegramLink::query()->where('user_id', $user->id)->sole();
        $this->assertNotSame($token, $link->link_token);
        $this->assertSame(hash('sha256', $token), $link->link_token);
        $this->assertTrue($link->token_expires_at->equalTo(now()->addMinutes(30)));
        $this->assertNull($link->chat_id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32,64}$/', $token);
    }

    public function test_revisiting_and_polling_keep_the_same_token(): void
    {
        $user = User::factory()->create();
        $first = $this->linkPage($user);
        $hash = TelegramLink::query()->where('user_id', $user->id)->value('link_token');

        $this->assertSame($first['link_url'], $this->linkPage($user)['link_url']);

        $partial = $this->reloadStatus();
        $this->assertSame('waiting', $partial['status']);
        $this->assertArrayNotHasKey('link_url', $partial);
        $this->assertArrayNotHasKey('expires_at', $partial);

        $this->assertSame($hash, TelegramLink::query()->where('user_id', $user->id)->value('link_token'));
    }

    public function test_status_goes_from_waiting_to_linked(): void
    {
        $user = User::factory()->create();
        $token = $this->generateToken($user);

        $this->sendUpdate(['text' => '/start '.$token])->assertOk();

        $this->actingAs($user);
        $this->assertSame('linked', $this->reloadStatus()['status']);
        $this->assertSame('linked', $this->linkPage($user)['status']);
    }

    public function test_status_expires_with_the_token_and_store_issues_a_new_one(): void
    {
        $user = User::factory()->create();
        $first = $this->generateToken($user);

        $this->travel(31)->minutes();
        $this->assertSame('expired', $this->reloadStatus()['status']);
        // An expired link is not replaced silently.
        $this->assertSame(self::PREFIX.$first, $this->linkPage($user)['link_url']);

        $this->actingAs($user)
            ->post(route('profile.telegram.store'))
            ->assertRedirect(route('profile.telegram.create'));

        $page = $this->linkPage($user);
        $this->assertNotSame(self::PREFIX.$first, $page['link_url']);
        $this->assertSame('waiting', $page['status']);
        $this->assertSame(
            hash('sha256', substr($page['link_url'], strlen(self::PREFIX))),
            TelegramLink::query()->where('user_id', $user->id)->value('link_token'),
        );
    }

    public function test_the_session_link_belongs_to_its_user(): void
    {
        $ana = User::factory()->create();
        $luis = User::factory()->create();
        $anaUrl = $this->linkPage($ana)['link_url'];

        $this->assertNotSame($anaUrl, $this->linkPage($luis)['link_url']);
    }

    public function test_an_already_linked_user_goes_back_to_the_profile(): void
    {
        $user = User::factory()->create();
        TelegramLink::create(['user_id' => $user->id, 'chat_id' => '4242', 'linked_at' => now()]);

        $this->actingAs($user)
            ->get(route('profile.telegram.create'))
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('4242', TelegramLink::query()->where('user_id', $user->id)->value('chat_id'));
    }

    public function test_link_fails_when_the_bot_is_not_configured(): void
    {
        config(['services.telegram.bot_username' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.telegram.create'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('error', 'El bot de Telegram no está configurado.');

        $this->post(route('profile.telegram.store'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('error', 'El bot de Telegram no está configurado.');

        $this->assertSame(0, TelegramLink::query()->count());
    }

    public function test_guests_cannot_generate_links(): void
    {
        $this->get(route('profile.telegram.create'))->assertRedirect(route('login'));
        $this->post(route('profile.telegram.store'))->assertRedirect(route('login'));
        $this->delete(route('profile.telegram.destroy'))->assertRedirect(route('login'));
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

    public function test_destroy_unlinks_and_goes_back_to_the_profile(): void
    {
        $user = User::factory()->create();
        TelegramLink::create(['user_id' => $user->id, 'chat_id' => '4242', 'linked_at' => now()]);

        $this->actingAs($user)
            ->delete(route('profile.telegram.destroy'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success', 'Telegram desvinculado.');

        $this->assertSame(0, TelegramLink::query()->count());
        $this->assertNull($user->fresh()->routeNotificationFor('telegram'));
    }
}
