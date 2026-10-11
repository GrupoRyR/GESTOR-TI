<?php

namespace App\Support\Vault;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Encryption of the vault values (PROMPT 6.4.10) with the vault's own key,
 * `config('services.vault.key')` (`VAULT_KEY`), never with APP_KEY: a copy
 * of the database or of a backup plus the app key is not enough to read
 * them.
 *
 * - The key may carry the `base64:` prefix (as APP_KEY) or be the raw bytes.
 * - Cipher `services.vault.cipher` (default AES-256-GCM; AES-256-CBC works).
 * - Fails closed with VaultKeyException when the key is missing, has the
 *   wrong length for the cipher or equals APP_KEY.
 *
 * Built on demand (`fromConfig()`), never cached in the container, so a key
 * change takes effect at once and nothing keeps the key in memory longer
 * than one operation needs.
 */
final class VaultEncrypter
{
    public const DEFAULT_CIPHER = 'aes-256-gcm';

    private function __construct(private readonly Encrypter $encrypter) {}

    /**
     * @throws VaultKeyException
     */
    public static function fromConfig(): self
    {
        $configured = config('services.vault.key');
        $cipher = (string) (config('services.vault.cipher') ?: self::DEFAULT_CIPHER);

        if (! is_string($configured) || trim($configured) === '') {
            throw VaultKeyException::missing();
        }

        $key = self::parse($configured);

        if ($key === null || ! Encrypter::supported($key, $cipher)) {
            throw VaultKeyException::invalid();
        }

        $appKey = config('app.key');

        if (is_string($appKey) && $appKey !== '' && hash_equals((string) self::parse($appKey), $key)) {
            throw VaultKeyException::sameAsAppKey();
        }

        return new self(new Encrypter($key, $cipher));
    }

    /**
     * Throws when the vault cannot work (missing or invalid key).
     *
     * @throws VaultKeyException
     */
    public static function assertConfigured(): void
    {
        self::fromConfig();
    }

    public function encrypt(string $value): string
    {
        return $this->encrypter->encryptString($value);
    }

    /**
     * @throws DecryptException
     */
    public function decrypt(string $payload): string
    {
        return $this->encrypter->decryptString($payload);
    }

    private static function parse(string $key): ?string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? null : $decoded;
        }

        return $key;
    }
}
