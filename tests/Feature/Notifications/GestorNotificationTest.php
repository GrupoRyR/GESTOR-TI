<?php

namespace Tests\Feature\Notifications;

use App\Models\Company;
use App\Models\NotificationPreference;
use App\Models\TelegramLink;
use App\Models\User;
use App\Notifications\GestorNotification;
use App\Notifications\Messages\TelegramMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GestorNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notifications.events' => [
                'test.event' => ['label' => 'Evento de prueba', 'channels' => ['mail', 'telegram', 'database']],
                'test.quiet' => ['label' => 'Evento silencioso', 'channels' => ['database']],
            ],
            'services.telegram.bot_token' => 'test-token',
            'mail.from.address' => 'default@example.com',
            'mail.from.name' => 'Gestor TI',
        ]);
    }

    private function notification(string $event = 'test.event', ?Company $company = null): GestorNotification
    {
        return (new TestNotification($event))->forCompany($company);
    }

    private function linkTelegram(User $user, string $chatId = '555'): void
    {
        TelegramLink::create([
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'telegram_username' => 'ana',
            'linked_at' => now(),
        ]);
    }

    public function test_via_uses_the_catalog_default_without_preference(): void
    {
        $user = User::factory()->create();
        $this->linkTelegram($user);

        $this->assertSame(['mail', 'telegram', 'database'], $this->notification()->via($user->fresh()));
        $this->assertSame(['database'], $this->notification('test.quiet')->via($user->fresh()));
    }

    public function test_via_honours_the_user_preference(): void
    {
        $user = User::factory()->create();
        $this->linkTelegram($user);
        NotificationPreference::create(['user_id' => $user->id, 'event_type' => 'test.event', 'channels' => ['telegram']]);
        NotificationPreference::create(['user_id' => $user->id, 'event_type' => 'test.quiet', 'channels' => []]);

        $this->assertSame(['telegram'], $this->notification()->via($user->fresh()));
        $this->assertSame([], $this->notification('test.quiet')->via($user->fresh()));
    }

    public function test_telegram_is_dropped_when_the_user_has_no_linked_chat(): void
    {
        $user = User::factory()->create();

        $this->assertSame(['mail', 'database'], $this->notification()->via($user));

        // A pending link (token generated, chat not linked yet) does not count.
        TelegramLink::create(['user_id' => $user->id, 'link_token' => hash('sha256', 'x'), 'token_expires_at' => now()->addMinutes(30)]);
        $this->assertSame(['mail', 'database'], $this->notification()->via($user->fresh()));
    }

    public function test_unknown_event_types_only_use_the_database_channel(): void
    {
        $user = User::factory()->create();

        $this->assertSame(['database'], $this->notification('unknown.event')->via($user));
    }

    public function test_the_database_channel_is_delivered_synchronously(): void
    {
        $this->assertSame(['database' => 'sync'], $this->notification()->viaConnections());
    }

    public function test_mail_is_sent_from_the_sender_mailbox_of_the_notification_company(): void
    {
        $user = User::factory()->create();
        $companies = [
            Company::factory()->create(['sender_email' => 'segurex.info@segurex.com', 'sender_name' => 'Segurex Latam']),
            Company::factory()->create(['sender_email' => 'derivados.info@derivadoscol.com', 'sender_name' => 'Derivados']),
            Company::factory()->create(['sender_email' => 'info@tercera.com', 'sender_name' => 'Tercera SAS']),
        ];

        foreach ($companies as $company) {
            $mail = $this->notification(company: $company)->toMail($user);

            $this->assertSame([$company->sender_email, $company->sender_name], $mail->from);
        }
    }

    public function test_mail_falls_back_to_the_user_company_then_to_the_default_sender(): void
    {
        $company = Company::factory()->create(['sender_email' => 'info@tercera.com', 'sender_name' => 'Tercera SAS']);
        $withCompany = User::factory()->create(['company_id' => $company->id]);
        $withoutCompany = User::factory()->create();
        $silent = Company::factory()->create(['sender_email' => null, 'sender_name' => null]);

        $this->assertSame(['info@tercera.com', 'Tercera SAS'], $this->notification()->toMail($withCompany)->from);
        $this->assertSame([], $this->notification()->toMail($withoutCompany)->from);
        $this->assertSame([], $this->notification(company: $silent)->toMail($withoutCompany)->from);
    }

    public function test_notifications_are_delivered_through_the_telegram_channel(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);
        $user = User::factory()->create();
        $this->linkTelegram($user, '9001');
        NotificationPreference::create(['user_id' => $user->id, 'event_type' => 'test.event', 'channels' => ['telegram']]);

        $user->fresh()->notify($this->notification());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/bottest-token/sendMessage')
            && $request['chat_id'] === '9001'
            && str_contains($request['text'], 'Título de prueba')
            && $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'test:1');
    }

    public function test_notification_fake_sees_the_resolved_channels(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $user->notify($this->notification());

        Notification::assertSentTo($user, TestNotification::class, fn ($notification, array $channels) => $channels === ['mail', 'database']);
    }
}

class TestNotification extends GestorNotification
{
    public function __construct(private string $event) {}

    public function eventType(): string
    {
        return $this->event;
    }

    public function title(): string
    {
        return 'Título de prueba';
    }

    public function body(): string
    {
        return 'Cuerpo de prueba';
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return parent::toTelegram($notifiable)->button('Aprobar', 'test:1');
    }
}
