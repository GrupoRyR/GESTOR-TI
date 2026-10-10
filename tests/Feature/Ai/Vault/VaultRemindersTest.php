<?php

namespace Tests\Feature\Ai\Vault;

use App\Notifications\VaultReminder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

class VaultRemindersTest extends VaultTestCase
{
    public function test_expiry_and_rotation_reminders_go_out_30_15_and_7_days_before(): void
    {
        Notification::fake();
        // Expires on 6 Nov (30 days from 7 Oct).
        $expiring = $this->item('a', ['name' => 'API de WooCommerce', 'expires_at' => '2026-11-06', 'rotate_every_days' => null]);
        // Rotated on 22 Jul every 90 days: due 20 Oct (13 days).
        $rotating = $this->item('b', ['name' => 'cPanel Toma Pedidos', 'expires_at' => null, 'rotate_every_days' => 90, 'last_rotated_at' => '2026-07-22 10:00:00']);

        $sent = [];
        foreach (range(0, 31) as $day) {
            $this->artisan('ai:vault-reminders')->assertSuccessful();
            $this->travel(1)->days();
        }

        Notification::assertSentTo($this->jhon, VaultReminder::class, function (VaultReminder $notice) use (&$sent) {
            $sent[] = "{$notice->itemName}|{$notice->kind}|{$notice->days}";

            return true;
        });
        sort($sent);

        $this->assertSame([
            'API de WooCommerce|expiry|15',
            'API de WooCommerce|expiry|30',
            'API de WooCommerce|expiry|7',
            'cPanel Toma Pedidos|rotation|7',
        ], $sent);
        Notification::assertNotSentTo([$this->superAdmin, $this->edward], VaultReminder::class);
        $this->assertNotNull($expiring->id);
        $this->assertNotNull($rotating->id);
    }

    public function test_reminders_never_carry_the_value(): void
    {
        $notice = new VaultReminder(1, 'DEEPSEEK_API_KEY', 'expiry', 7, '2026-10-14');

        $this->assertSame('vault.reminder', $notice->eventType());
        $this->assertSame('Bóveda: DEEPSEEK_API_KEY vence en 7 días', $notice->title());
        $this->assertStringContainsString('14 oct', $notice->body());
    }

    public function test_the_reminder_command_runs_daily_in_bogota(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'ai:vault-reminders'));

        $this->assertNotNull($event);
        $this->assertSame('0 7 * * *', $event->expression);
        $this->assertSame('America/Bogota', (string) $event->timezone);
    }

    public function test_home_lists_expiring_credentials_only_for_the_vault_holder(): void
    {
        $soon = $this->item('a', ['name' => 'DEEPSEEK_API_KEY', 'expires_at' => '2026-10-19', 'rotate_every_days' => null]);
        $past = $this->item('b', ['name' => 'FTP de GoDaddy', 'expires_at' => '2026-10-05', 'rotate_every_days' => null]);
        $rotation = $this->item('c', ['name' => 'Token de Vercel', 'expires_at' => null, 'rotate_every_days' => 30, 'last_rotated_at' => '2026-09-17 10:00:00']);
        $this->item('d', ['name' => 'Lejana', 'expires_at' => '2027-03-01', 'rotate_every_days' => null]);

        $this->actingAs($this->jhon)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'it')
                ->where('attention.expiring_credentials', [
                    [
                        'id' => 'credential-'.$past->id,
                        'title' => 'FTP de GoDaddy · venció hace 2 días',
                        'detail' => 'Vencimiento',
                        'href_route' => 'ai.vault.index',
                        'href_params' => ['item' => $past->id],
                        'severity' => 'late',
                    ],
                    [
                        'id' => 'credential-'.$rotation->id,
                        'title' => 'Token de Vercel · 10 días',
                        'detail' => 'Rotación programada',
                        'href_route' => 'ai.vault.index',
                        'href_params' => ['item' => $rotation->id],
                        'severity' => 'attention',
                    ],
                    [
                        'id' => 'credential-'.$soon->id,
                        'title' => 'DEEPSEEK_API_KEY · 12 días',
                        'detail' => 'Vencimiento',
                        'href_route' => 'ai.vault.index',
                        'href_params' => ['item' => $soon->id],
                        'severity' => 'attention',
                    ],
                ])
            );

        $lider = $this->user($this->segurex, 'Líder IA', 'Otra Líder');

        $this->actingAs($lider)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'it')
                ->where('attention.expiring_credentials', [])
            );
    }
}
