<?php

namespace Tests\Feature\Ai\Vault;

use App\Http\Middleware\RequireRecentMicrosoftAuth;
use App\Models\AiVaultAccessLog;
use App\Models\Alert;
use App\Notifications\AlertRaised;
use App\Notifications\VaultAccessed;
use App\Support\Vault\VaultSession;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

class VaultAccessTest extends VaultTestCase
{
    private const SECRET = 'sk-muestra-9c41f0a7d2e84b6b0e31';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_the_holder_sees_the_locked_vault_with_metadata_only(): void
    {
        $item = $this->item(self::SECRET);

        $this->assertSame(
            $this->withShared(['session', 'items', 'selected', 'revealed', 'access_log', 'telegram_alerts', 'holder_name', 'today', 'now']),
            $this->propKeys(route('ai.vault.index')),
        );

        $response = $this->actingAs($this->jhon)
            ->get(route('ai.vault.index', ['item' => $item->id]))
            ->assertOk()
            ->assertDontSee(self::SECRET)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Vault/Index')
                ->where('session', ['state' => 'locked'])
                ->has('items', 1)
                ->where('items.0', [
                    'id' => $item->id,
                    'name' => 'DEEPSEEK_API_KEY',
                    'product' => 'Gestor TI',
                    'environment' => 'production',
                    'type' => 'api_key',
                    'username' => 'jhon.castro@segurex.com',
                    'usage_url' => 'api.deepseek.com',
                    'expires_at' => '2026-10-19',
                    'expiry_note' => null,
                    'rotation_days' => 90,
                    'notes' => 'La usa el Asistente.',
                ])
                ->where('selected.id', $item->id)
                ->where('revealed', null)
                ->where('access_log', [])
                ->where('telegram_alerts', false)
                ->where('holder_name', 'Jhon Castro')
                ->where('today', '2026-10-07')
                ->has('now')
            );

        $this->assertTrue($response->viewData('page')['encryptHistory'] ?? false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_super_admin_without_the_exclusive_permission_is_denied_logged_and_alerted(): void
    {
        $item = $this->item();

        $this->assertSame($this->withShared(['holder_name', 'attempt']), $this->propKeys(route('ai.vault.index'), $this->superAdmin));
        AiVaultAccessLog::query()->delete();
        Alert::query()->delete();

        $this->actingAs($this->superAdmin)
            ->get(route('ai.vault.index', ['item' => $item->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Vault/Denied')
                ->where('holder_name', 'Jhon Castro')
                ->where('attempt.person.id', $this->superAdmin->id)
                ->where('attempt.person.name', 'Leonardo Herrera')
                ->where('attempt.at', now()->toIso8601String())
                ->missing('items')
            );

        $log = AiVaultAccessLog::query()->sole();
        $this->assertSame('denied', $log->action);
        $this->assertSame($this->superAdmin->id, $log->user_id);
        $this->assertSame($item->id, $log->ai_vault_item_id);
        $this->assertSame('web', $log->channel);

        $alert = Alert::query()->sole();
        $this->assertSame('warning', $alert->level);
        $this->assertSame("ai.vault.denied:{$this->superAdmin->id}:2026-10-07", $alert->group_key);
        Notification::assertSentTo($this->jhon, AlertRaised::class);
        Notification::assertNotSentTo($this->superAdmin, AlertRaised::class);

        // Grouped per user and day: a second attempt updates the same alert.
        $this->actingAs($this->superAdmin)->get(route('ai.vault.index'))->assertOk();
        $this->assertSame(1, Alert::query()->count());
        $this->assertSame(2, Alert::query()->sole()->occurrences);
        $this->assertSame(2, AiVaultAccessLog::query()->where('action', 'denied')->count());
    }

    public function test_every_vault_action_is_denied_without_the_permission(): void
    {
        $item = $this->item(self::SECRET);

        foreach ([
            ['post', route('ai.vault.reveal', $item)],
            ['post', route('ai.vault.copied', $item)],
            ['post', route('ai.vault.lock')],
            ['get', route('ai.vault.reauth')],
        ] as [$method, $url]) {
            $this->actingAs($this->paola)
                ->withSession($this->openVault())
                ->{$method}($url)
                ->assertOk()
                ->assertDontSee(self::SECRET)
                ->assertInertia(fn (Assert $page) => $page->component('Ai/Vault/Denied'));
        }

        $this->assertSame(4, AiVaultAccessLog::query()->where('action', 'denied')->count());
        $this->assertSame(0, AiVaultAccessLog::query()->where('action', 'view')->count());
    }

    public function test_unlocking_requires_a_recent_microsoft_reauthentication(): void
    {
        $item = $this->item();

        $this->actingAs($this->jhon)
            ->get(route('ai.vault.reauth', ['item' => $item->id]))
            ->assertRedirect(route('auth.microsoft.reauth'))
            ->assertSessionHas('url.intended', route('ai.vault.reauth', ['item' => $item->id]))
            ->assertSessionMissing(VaultSession::UNLOCKED_AT);

        $this->actingAs($this->jhon)
            ->withSession($this->recentReauth())
            ->get(route('ai.vault.reauth', ['item' => $item->id]))
            ->assertRedirect(route('ai.vault.index', ['item' => $item->id]))
            ->assertSessionHas(VaultSession::UNLOCKED_AT, now()->getTimestamp())
            // The confirmation is used once: a later unlock asks Microsoft again.
            ->assertSessionMissing(RequireRecentMicrosoftAuth::SESSION_KEY);

        $this->assertSame('unlock', AiVaultAccessLog::query()->sole()->action);
    }

    public function test_the_open_vault_shows_the_session_and_the_access_log_of_the_selected_item(): void
    {
        $item = $this->item();
        $other = $this->item('otro', ['name' => 'Token de Vercel', 'type' => 'token']);
        AiVaultAccessLog::create([
            'ai_vault_item_id' => $item->id,
            'item_name' => $item->name,
            'user_id' => $this->jhon->id,
            'action' => 'copy',
            'ip' => '10.0.0.5',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0',
            'channel' => 'web',
            'created_at' => now()->subMinutes(5),
        ]);
        AiVaultAccessLog::create(['ai_vault_item_id' => $other->id, 'item_name' => $other->name, 'user_id' => $this->jhon->id, 'action' => 'view', 'channel' => 'web', 'created_at' => now()]);

        $this->actingAs($this->jhon)
            ->withSession($this->openVault(3))
            ->get(route('ai.vault.index', ['item' => $item->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('session.state', 'open')
                ->where('session.confirmed_at', now()->subMinutes(3)->toIso8601String())
                ->where('session.expires_at', now()->addMinutes(10)->toIso8601String())
                ->has('items', 2)
                ->has('access_log', 1)
                ->where('access_log.0.action', 'copied')
                ->where('access_log.0.item_name', 'DEEPSEEK_API_KEY')
                ->where('access_log.0.person.name', 'Jhon Castro')
                ->where('access_log.0.from', 'Edge en Windows · 10.0.0.5')
                ->where('access_log.0.at', now()->subMinutes(5)->toIso8601String())
            );
    }

    public function test_the_vault_locks_itself_after_ten_minutes_without_use(): void
    {
        $this->item();

        $this->actingAs($this->jhon)->withSession($this->openVault())->get(route('ai.vault.index'))->assertOk();

        // Each request refreshes the idle timer.
        $this->travel(9)->minutes();
        $this->actingAs($this->jhon)->get(route('ai.vault.index'))
            ->assertInertia(fn (Assert $page) => $page->where('session.state', 'open'));
        $this->travel(9)->minutes();
        $this->actingAs($this->jhon)->get(route('ai.vault.index'))
            ->assertInertia(fn (Assert $page) => $page->where('session.state', 'open'));

        $this->travel(11)->minutes();
        $this->actingAs($this->jhon)->get(route('ai.vault.index'))
            ->assertInertia(fn (Assert $page) => $page->where('session.state', 'locked'))
            ->assertSessionMissing(VaultSession::UNLOCKED_AT);

        $lock = AiVaultAccessLog::query()->where('action', 'lock')->sole();
        $this->assertSame('auto', $lock->channel);
    }

    public function test_reveal_needs_an_open_vault(): void
    {
        $item = $this->item(self::SECRET);

        $this->actingAs($this->jhon)
            ->post(route('ai.vault.reveal', $item))
            ->assertRedirect(route('ai.vault.index', ['item' => $item->id]))
            ->assertSessionHas('error', 'La bóveda está cerrada. Confirma tu identidad con Microsoft para ver el valor.');

        $this->assertSame(0, AiVaultAccessLog::query()->count());
        Notification::assertNothingSent();
    }

    public function test_reveal_returns_the_value_once_with_encrypted_history_and_no_store(): void
    {
        $item = $this->item(self::SECRET);

        $response = $this->actingAs($this->jhon)
            ->withSession($this->openVault(1))
            ->withHeaders($this->partial('revealed,access_log'))
            ->post(route('ai.vault.reveal', $item))
            ->assertOk()
            ->assertJsonPath('component', 'Ai/Vault/Index')
            ->assertJsonPath('props.revealed', ['item_id' => $item->id, 'value' => self::SECRET, 'expires_in_seconds' => 30])
            ->assertJsonPath('props.access_log.0.action', 'viewed')
            ->assertJsonPath('encryptHistory', true)
            ->assertJsonMissingPath('props.items');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $log = AiVaultAccessLog::query()->where('action', 'view')->sole();
        $this->assertSame($item->id, $log->ai_vault_item_id);
        Notification::assertSentTo($this->jhon, VaultAccessed::class, fn (VaultAccessed $notice) => $notice->action === 'view'
            && $notice->itemName === 'DEEPSEEK_API_KEY'
            && ! str_contains($notice->title().$notice->body(), self::SECRET));

        // Only that response carries it.
        $this->flushHeaders();
        $this->actingAs($this->jhon)
            ->get(route('ai.vault.index', ['item' => $item->id]))
            ->assertDontSee(self::SECRET)
            ->assertInertia(fn (Assert $page) => $page->where('revealed', null));
    }

    public function test_copy_is_logged_and_noticed_without_sending_the_value_back(): void
    {
        $item = $this->item(self::SECRET);

        $this->actingAs($this->jhon)
            ->withSession($this->openVault(1))
            ->post(route('ai.vault.copied', $item))
            ->assertRedirect(route('ai.vault.index', ['item' => $item->id]));

        $this->assertSame('copy', AiVaultAccessLog::query()->sole()->action);
        Notification::assertSentTo($this->jhon, VaultAccessed::class, fn (VaultAccessed $notice) => $notice->action === 'copy');

        $this->actingAs($this->jhon)
            ->withHeaders(array_replace($this->partial('access_log'), ['X-Inertia-Partial-Data' => 'access_log']))
            ->get(route('ai.vault.index', ['item' => $item->id]))
            ->assertDontSee(self::SECRET)
            ->assertJsonPath('props.access_log.0.action', 'copied');
    }

    public function test_locking_closes_the_session(): void
    {
        $this->actingAs($this->jhon)
            ->withSession($this->openVault(2))
            ->post(route('ai.vault.lock'))
            ->assertRedirect(route('ai.vault.index'))
            ->assertSessionMissing(VaultSession::UNLOCKED_AT);

        $lock = AiVaultAccessLog::query()->sole();
        $this->assertSame('lock', $lock->action);
        $this->assertSame('web', $lock->channel);
    }

    public function test_the_item_edit_form_is_pending_design(): void
    {
        $item = $this->item();

        $this->actingAs($this->jhon)
            ->from(route('ai.vault.index'))
            ->get(route('ai.vault.edit', $item))
            ->assertRedirect(route('ai.vault.index'))
            ->assertSessionHas('error', 'Disponible pronto.');
    }

    public function test_the_vault_fails_closed_without_its_own_key(): void
    {
        $this->item();

        foreach ([null, '', config('app.key')] as $key) {
            config(['services.vault.key' => $key]);

            $this->actingAs($this->jhon)
                ->withSession($this->openVault())
                ->get(route('ai.vault.index'))
                ->assertRedirect(route('home'))
                ->assertSessionHas('error', fn (string $message) => str_contains($message, 'VAULT_KEY'));
        }
    }

    public function test_the_vault_requires_login_and_the_ai_module(): void
    {
        $this->get(route('ai.vault.index'))->assertRedirect(route('login'));

        config(['modules.ai.active' => false]);

        $this->get(route('ai.vault.index'))->assertNotFound();
    }
}
