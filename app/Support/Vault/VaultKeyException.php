<?php

namespace App\Support\Vault;

use RuntimeException;

/**
 * The vault key (`VAULT_KEY`) is missing, invalid or equal to APP_KEY: the
 * vault fails closed. Messages are Spanish and safe to show (they never
 * include the key).
 */
class VaultKeyException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('La bóveda está cerrada: falta configurar VAULT_KEY en el .env del servidor.');
    }

    public static function sameAsAppKey(): self
    {
        return new self('La bóveda está cerrada: VAULT_KEY no puede ser igual a APP_KEY. Genera una llave propia para la bóveda.');
    }

    public static function invalid(): self
    {
        return new self('La bóveda está cerrada: VAULT_KEY no es válida. Debe ser una llave de 32 bytes (base64:…).');
    }
}
