<?php

namespace Tests\Feature\Ai\Vault;

use App\Http\Middleware\EnsureVaultAccess;
use App\Models\AiProduct;
use App\Models\AiVaultAccessLog;
use App\Models\AiVaultItem;
use App\Notifications\VaultAccessed;
use App\Support\Vault\VaultEncrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * `Ai/Vault/Create` and `Ai/Vault/Edit` (C-VaultItemForm,
 * types/pages/ai-value-vault.ts): `ai.vault.access` and an open vault
 * session; the form never receives the value.
 */
class VaultItemFormsTest extends VaultTestCase
{
    private const SECRET = 'sk-muestra-9c41f0a7d2e84b6b0e31';

    /** EnsureVaultAccess::NO_STORE as Symfony normalizes it (sorted directives). */
    private const NO_STORE = 'max-age=0, no-cache, no-store, private';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'OPENAI_API_KEY',
            'product' => 'Gestor TI',
            'environment' => 'testing',
            'type' => 'api_key',
            'username' => '',
            'value' => 'sk-nueva-1234567890',
            'rotation_days' => 90,
            'notes' => 'Solo para pruebas.',
        ], $overrides);
    }

    public function test_create_returns_exactly_the_contract_props(): void
    {
        $this->item();

        $response = $this->actingAs($this->jhon)
            ->withSession($this->openVault())
            ->get(route('ai.vault.create', ['product' => 'Gestor TI', 'environment' => 'testing']))
            ->assertOk()
            ->assertHeader('Cache-Control', self::NO_STORE)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Vault/Create', false)
                ->where('session.state', 'open')
                ->where('defaults', ['product' => 'Gestor TI', 'environment' => 'testing'])
                ->where('holder_name', 'Jhon Castro')
                ->where('telegram_alerts', false)
                ->where('today', '2026-10-07')
                ->has('now')
                ->where('projects', fn ($projects) => collect($projects)->contains(fn ($option) => $option['product'] === 'Gestor TI' && $option['environment'] === 'production'))
            );

        $keys = array_keys($response->viewData('page')['props']);
        sort($keys);
        $this->assertSame($this->withShared(['session', 'projects', 'holder_name', 'telegram_alerts', 'today', 'now', 'defaults']), $keys);
        $this->assertTrue($response->viewData('page')['encryptHistory']);
    }

    public function test_unknown_defaults_are_null(): void
    {
        $this->actingAs($this->jhon)
            ->withSession($this->openVault())
            ->get(route('ai.vault.create', ['product' => 'No existe']))
            ->assertInertia(fn (Assert $page) => $page->where('defaults', null));
    }

    public function test_edit_returns_metadata_and_never_the_value(): void
    {
        $item = $this->item(self::SECRET, ['last_rotated_at' => '2026-07-21 10:00']);

        $response = $this->actingAs($this->jhon)
            ->withSession($this->openVault())
            ->get(route('ai.vault.edit', $item))
            ->assertOk()
            ->assertHeader('Cache-Control', self::NO_STORE)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Vault/Edit', false)
                ->where('item.id', $item->id)
                ->where('item.name', 'DEEPSEEK_API_KEY')
                ->where('item.product', 'Gestor TI')
                ->where('last_rotated_at', '2026-07-21')
            );

        $keys = array_keys($response->viewData('page')['props']);
        sort($keys);
        $this->assertSame($this->withShared(['session', 'projects', 'holder_name', 'telegram_alerts', 'today', 'now', 'item', 'last_rotated_at']), $keys);
        $this->assertTrue($response->viewData('page')['encryptHistory']);
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertStringNotContainsString('secret_ciphertext', $response->getContent());
    }

    public function test_forms_and_writes_redirect_to_the_locked_vault_without_a_session(): void
    {
        $item = $this->item();

        $this->actingAs($this->jhon)->get(route('ai.vault.create'))
            ->assertRedirect(route('ai.vault.index'));
        $this->actingAs($this->jhon)->get(route('ai.vault.edit', $item))
            ->assertRedirect(route('ai.vault.index', ['item' => $item->id]));
        $this->actingAs($this->jhon)->post(route('ai.vault.store'), $this->payload())
            ->assertRedirect(route('ai.vault.index'));
        $this->actingAs($this->jhon)->put(route('ai.vault.update', $item), $this->payload())
            ->assertRedirect(route('ai.vault.index', ['item' => $item->id]))
            ->assertSessionHas('error');
        $this->actingAs($this->jhon)->delete(route('ai.vault.destroy', $item), ['confirm_name' => 'DEEPSEEK_API_KEY'])
            ->assertRedirect(route('ai.vault.index', ['item' => $item->id]));

        // An idle session (over 10 minutes) is locked too.
        $this->actingAs($this->jhon)->withSession($this->openVault(11))->get(route('ai.vault.edit', $item))
            ->assertRedirect(route('ai.vault.index', ['item' => $item->id]));

        $this->assertSame(1, AiVaultItem::query()->count());
        $this->assertSame('DEEPSEEK_API_KEY', $item->fresh()->name);
    }

    public function test_without_the_permission_every_form_route_is_denied(): void
    {
        $item = $this->item();

        foreach ([['get', route('ai.vault.create')], ['get', route('ai.vault.edit', $item)], ['post', route('ai.vault.store')], ['put', route('ai.vault.update', $item)], ['delete', route('ai.vault.destroy', $item)]] as [$method, $url]) {
            $this->actingAs($this->superAdmin)
                ->withSession($this->openVault())
                ->{$method}($url, $this->payload())
                ->assertInertia(fn (Assert $page) => $page->component('Ai/Vault/Denied', false));
        }

        $this->assertSame(1, AiVaultItem::query()->count());
    }

    public function test_store_encrypts_with_the_vault_key_logs_and_notices(): void
    {
        Notification::fake();
        $this->item();

        $this->actingAs($this->jhon)
            ->withSession($this->openVault())
            ->post(route('ai.vault.store'), $this->payload(['value' => '  sk-nueva con espacios ']))
            ->assertRedirect()
            ->assertHeader('Cache-Control', self::NO_STORE)
            ->assertSessionHas('success', 'Credencial guardada.');

        $item = AiVaultItem::query()->where('name', 'OPENAI_API_KEY')->sole();
        $this->assertSame('testing', $item->environment);
        $this->assertSame('Gestor TI', $item->product?->name);
        $this->assertNull($item->username);
        $this->assertSame(90, $item->rotate_every_days);
        $this->assertSame('Solo para pruebas.', $item->notes);

        $raw = (string) DB::table('ai_vault_items')->where('id', $item->id)->value('secret_ciphertext');
        $this->assertStringNotContainsString('sk-nueva', $raw);
        // Not trimmed: the exact value, with the vault key.
        $this->assertSame('  sk-nueva con espacios ', VaultEncrypter::fromConfig()->decrypt($raw));

        $log = AiVaultAccessLog::query()->where('ai_vault_item_id', $item->id)->sole();
        $this->assertSame('create', $log->action);
        Notification::assertSentTo($this->jhon, VaultAccessed::class, fn (VaultAccessed $notice) => $notice->action === 'create' && $notice->itemName === 'OPENAI_API_KEY');

        $activity = Activity::query()->where('event', 'vault_create')->sole();
        $this->assertStringNotContainsString('sk-nueva', json_encode($activity->properties));
    }

    public function test_store_validation_in_spanish(): void
    {
        $this->actingAs($this->jhon)
            ->withSession($this->openVault())
            ->from(route('ai.vault.create'))
            ->post(route('ai.vault.store'), $this->payload([
                'name' => '',
                'product' => 'Producto inventado',
                'environment' => 'staging',
                'type' => 'otro',
                'value' => '',
                'rotation_days' => 45,
            ]))
            ->assertRedirect(route('ai.vault.create'))
            ->assertSessionHasErrors([
                'name' => 'Escribe el nombre de la credencial.',
                'product' => 'Elige un producto o proyecto de la lista.',
                'environment' => 'Elige el ambiente.',
                'type' => 'Elige el tipo de credencial.',
                'value' => 'Escribe el valor de la credencial.',
                'rotation_days' => 'Elige cada cuánto se rota.',
            ]);

        $this->assertSame(0, AiVaultItem::query()->count());

        // A typed value is never flashed back as old input.
        $this->actingAs($this->jhon)
            ->withSession($this->openVault())
            ->from(route('ai.vault.create'))
            ->post(route('ai.vault.store'), $this->payload(['name' => '', 'value' => 'sk-no-flashear']))
            ->assertSessionHasErrors(['name'])
            ->assertSessionHasInput('product', 'Gestor TI')
            ->assertSessionMissing('_old_input.value');
        $this->assertStringNotContainsString('sk-no-flashear', serialize(session()->all()));
    }

    public function test_update_with_an_empty_value_keeps_the_current_one(): void
    {
        Notification::fake();
        $item = $this->item(self::SECRET, ['last_rotated_at' => '2026-07-21 10:00']);

        $this->actingAs($this->jhon)
            ->withSession($this->openVault())
            ->put(route('ai.vault.update', $item), $this->payload(['name' => 'DEEPSEEK_API_KEY', 'value' => '', 'rotation_days' => null, 'username' => 'otra@segurex.com', 'type' => 'user_password']))
            ->assertRedirect(route('ai.vault.index', ['item' => $item->id]))
            ->assertHeader('Cache-Control', self::NO_STORE)
            ->assertSessionHas('success', 'Cambios guardados.');

        $item->refresh();
        $this->assertSame(self::SECRET, $item->revealSecret());
        $this->assertNull($item->rotate_every_days);
        $this->assertSame('otra@segurex.com', $item->username);
        $this->assertSame('2026-07-21', $item->last_rotated_at->toDateString());
        $this->assertSame('update', AiVaultAccessLog::query()->sole()->action);
        Notification::assertSentTo($this->jhon, VaultAccessed::class, fn (VaultAccessed $notice) => $notice->action === 'update');
    }

    public function test_update_with_a_new_value_rotates_it(): void
    {
        $item = $this->item(self::SECRET);

        $this->actingAs($this->jhon)
            ->withSession($this->openVault())
            ->put(route('ai.vault.update', $item), $this->payload(['name' => 'DEEPSEEK_API_KEY', 'value' => 'sk-rotada-42']))
            ->assertRedirect(route('ai.vault.index', ['item' => $item->id]));

        $item->refresh();
        $this->assertSame('sk-rotada-42', $item->revealSecret());
        $this->assertSame('2026-10-07', $item->last_rotated_at->timezone('America/Bogota')->toDateString());
    }

    public function test_destroy_requires_the_exact_name(): void
    {
        Notification::fake();
        $item = $this->item();

        foreach (['', 'deepseek_api_key', 'DEEPSEEK_API'] as $typed) {
            $this->actingAs($this->jhon)
                ->withSession($this->openVault())
                ->from(route('ai.vault.edit', $item))
                ->delete(route('ai.vault.destroy', $item), ['confirm_name' => $typed])
                ->assertRedirect(route('ai.vault.edit', $item))
                ->assertSessionHasErrors(['confirm_name' => 'Escribe el nombre exacto de la credencial para eliminarla.']);
        }

        $this->assertNotNull($item->fresh());
        $this->assertSame(0, AiVaultAccessLog::query()->count());

        $this->actingAs($this->jhon)
            ->withSession($this->openVault())
            ->delete(route('ai.vault.destroy', $item), ['confirm_name' => ' DEEPSEEK_API_KEY '])
            ->assertRedirect(route('ai.vault.index'))
            ->assertHeader('Cache-Control', self::NO_STORE)
            ->assertSessionHas('success', 'Credencial eliminada.');

        $this->assertNull(AiVaultItem::query()->find($item->id));
        $this->assertSame('delete', AiVaultAccessLog::query()->sole()->action);
        Notification::assertSentTo($this->jhon, VaultAccessed::class, fn (VaultAccessed $notice) => $notice->action === 'delete');
    }

    public function test_projects_offer_products_and_projects_with_both_environments(): void
    {
        AiProduct::factory()->create(['name' => 'Visualizador de cartera']);
        AiProduct::factory()->create(['name' => 'App retirada', 'status' => 'retired']);

        $this->actingAs($this->jhon)
            ->withSession($this->openVault())
            ->get(route('ai.vault.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects', fn ($projects) => collect($projects)->contains(fn ($o) => $o['product'] === 'Visualizador de cartera' && $o['environment'] === 'testing')
                    && ! collect($projects)->contains(fn ($o) => $o['product'] === 'App retirada'))
            );
    }
}
