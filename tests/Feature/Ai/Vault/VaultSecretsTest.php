<?php

namespace Tests\Feature\Ai\Vault;

use App\Actions\Ai\Vault\ManageVaultItems;
use App\Models\AiVaultAccessLog;
use App\Models\AiVaultItem;
use App\Notifications\VaultAccessed;
use App\Support\Vault\VaultEncrypter;
use App\Support\Vault\VaultKeyException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

class VaultSecretsTest extends VaultTestCase
{
    private const SECRET = "  sk-muestra con espacios \n";

    public function test_values_are_encrypted_with_the_vault_key_not_the_app_key(): void
    {
        $item = $this->item(self::SECRET);

        $raw = (string) DB::table('ai_vault_items')->where('id', $item->id)->value('secret_ciphertext');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('sk-muestra', $raw);
        $this->assertStringNotContainsString('sk-muestra', base64_decode($raw));

        // Exact value back, whitespace included.
        $this->assertSame(self::SECRET, $item->fresh()->revealSecret());

        // A database copy plus APP_KEY is not enough.
        $this->expectException(DecryptException::class);
        Crypt::decryptString($raw);
    }

    public function test_another_vault_key_cannot_read_the_values(): void
    {
        $item = $this->item(self::SECRET);

        config(['services.vault.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->expectException(DecryptException::class);
        $item->fresh()->revealSecret();
    }

    public function test_the_key_fails_closed_when_missing_invalid_or_equal_to_app_key(): void
    {
        foreach ([null, '', 'base64:'.base64_encode('corta'), config('app.key')] as $key) {
            config(['services.vault.key' => $key]);

            try {
                VaultEncrypter::fromConfig();
                $this->fail('A vault key ['.var_export($key, true).'] was accepted.');
            } catch (VaultKeyException $e) {
                $this->assertStringContainsString('VAULT_KEY', $e->getMessage());
            }
        }

        // A raw 32-character key works too.
        config(['services.vault.key' => str_repeat('k', 32)]);
        $vault = VaultEncrypter::fromConfig();
        $this->assertSame('hola', $vault->decrypt($vault->encrypt('hola')));
    }

    public function test_a_missing_key_never_stores_a_value(): void
    {
        config(['services.vault.key' => null]);

        $this->expectException(VaultKeyException::class);
        $this->item();
    }

    public function test_serialization_never_includes_the_value(): void
    {
        $item = $this->item(self::SECRET)->fresh();

        $this->assertArrayNotHasKey('secret_ciphertext', $item->toArray());
        $this->assertArrayNotHasKey('value', $item->toArray());
        $this->assertStringNotContainsString('secret_ciphertext', $item->toJson());
        $this->assertStringNotContainsString((string) $item->getRawOriginal('secret_ciphertext'), json_encode($item->load('product')->toArray()));
        $this->assertStringNotContainsString('sk-muestra', (string) json_encode(AiVaultItem::query()->get()));
        $this->assertFalse($item->isFillable('secret_ciphertext'));
    }

    public function test_create_update_and_delete_are_logged_and_noticed_without_the_value(): void
    {
        Notification::fake();
        $actions = app(ManageVaultItems::class);

        $item = $actions->create($this->jhon, [
            'name' => 'Token de Vercel',
            'environment' => 'production',
            'type' => 'token',
            'rotate_every_days' => 365,
        ], 'vercel-uno');
        $this->assertSame('vercel-uno', $item->revealSecret());
        $this->assertNull($item->last_rotated_at);

        $actions->update($this->jhon, $item, ['notes' => 'Cambiar también en Vercel.'], 'vercel-dos');
        $item->refresh();
        $this->assertSame('vercel-dos', $item->revealSecret());
        $this->assertSame(now()->toDateTimeString(), $item->last_rotated_at?->toDateTimeString());

        $actions->delete($this->jhon, $item);
        $this->assertSoftDeleted($item);

        $this->assertSame(['create', 'update', 'delete'], AiVaultAccessLog::query()->orderBy('id')->pluck('action')->all());
        Notification::assertSentToTimes($this->jhon, VaultAccessed::class, 3);

        foreach (Activity::query()->get() as $activity) {
            $this->assertStringNotContainsString('vercel-', (string) json_encode($activity->toArray()));
        }
    }
}
