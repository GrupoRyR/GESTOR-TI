<?php

namespace Tests\Feature\Ai\Vault;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireRecentMicrosoftAuth;
use App\Models\AiProduct;
use App\Models\AiVaultItem;
use App\Models\Permission;
use App\Support\Vault\VaultSession;
use Carbon\CarbonImmutable;
use Tests\Feature\Ai\Triage\TriageTestCase;

/**
 * Vault (PROMPT 6.4.10, types/pages/ai-value-vault.ts). Jhon holds
 * `ai.vault.access` directly; Leonardo (Súper Admin) does not. The vault
 * key is a fresh random key, different from APP_KEY. The clock is fixed on
 * Wednesday 7 Oct 2026, 09:40 Bogotá.
 */
abstract class VaultTestCase extends TriageTestCase
{
    protected string $vaultKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:40', 'America/Bogota'));

        $this->vaultKey = 'base64:'.base64_encode(random_bytes(32));
        config(['services.vault.key' => $this->vaultKey]);

        Permission::withExclusiveGrant(fn () => $this->jhon->givePermissionTo(Permission::VAULT));
        $this->jhon->forceFill(['azure_id' => 'azure-jhon'])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function item(string $secret = 'sk-muestra-9c41f0a7d2e84b6b0e31', array $attributes = []): AiVaultItem
    {
        $product = AiProduct::factory()->create(['name' => 'Gestor TI']);

        $item = new AiVaultItem(array_replace([
            'name' => 'DEEPSEEK_API_KEY',
            'ai_product_id' => $product->id,
            'environment' => 'production',
            'type' => 'api_key',
            'username' => 'jhon.castro@segurex.com',
            'url' => 'api.deepseek.com',
            'expires_at' => '2026-10-19',
            'rotate_every_days' => 90,
            'notes' => 'La usa el Asistente.',
        ], $attributes));
        $item->setSecret($secret);
        $item->save();

        return $item;
    }

    /**
     * Session of an open vault (identity confirmed `$minutesAgo` minutes ago).
     *
     * @return array<string, int>
     */
    protected function openVault(int $minutesAgo = 0): array
    {
        $at = now()->subMinutes($minutesAgo)->getTimestamp();

        return [VaultSession::UNLOCKED_AT => $at, VaultSession::LAST_USED_AT => $at];
    }

    /**
     * @return array<string, int>
     */
    protected function recentReauth(): array
    {
        return [RequireRecentMicrosoftAuth::SESSION_KEY => now()->getTimestamp()];
    }

    /**
     * Headers of an Inertia partial reload of the vault page.
     *
     * @return array<string, string>
     */
    protected function partial(string $props): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Ai/Vault/Index',
            'X-Inertia-Partial-Data' => $props,
        ];
    }
}
