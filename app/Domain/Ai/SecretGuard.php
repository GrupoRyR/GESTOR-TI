<?php

namespace App\Domain\Ai;

use Illuminate\Validation\ValidationException;

/**
 * "Nunca contraseñas" (PROMPT 6.4.4): documentation bodies are refused when
 * they carry an obvious secret. It is a guard against accidents, not a
 * scanner: it looks for assigned passwords and API keys, private key
 * blocks and well-known key shapes (AWS access keys, `sk-` tokens).
 * Naming a variable (`DEEPSEEK_API_KEY`) or talking about passwords is fine.
 */
final class SecretGuard
{
    public const MESSAGE = 'El documento parece tener una contraseña o una llave. Las credenciales van en la bóveda, nunca en la documentación.';

    /**
     * Pattern => what it found (for logs; never the value).
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        // password=…, PASSWORD: …, contraseña = … (a value right after).
        '/(?:pass(?:word|wd)?|pwd|contrase(?:ñ|n)a)["\']?\s*[:=]\s*["\']?[^\s"\'`]{4,}/iu' => 'password',
        // api_key = "…", "apiKey": "…", secret_key: … (assigned values only).
        '/(?:api[_-]?key|apikey|secret[_-]?key|client[_-]?secret|access[_-]?token)["\']?\s*[:=]\s*["\']?[A-Za-z0-9_\-\.\/+]{8,}/i' => 'api_key',
        '/-----BEGIN [A-Z0-9 ]+-----/' => 'pem_block',
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => 'aws_key',
        '/\bsk-[A-Za-z0-9_\-]{20,}\b/' => 'sk_token',
        '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/' => 'github_token',
    ];

    /**
     * Kind of the first secret found, or null when the text looks clean.
     */
    public function find(string $text): ?string
    {
        foreach (self::PATTERNS as $pattern => $kind) {
            if (preg_match($pattern, $text) === 1) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @throws ValidationException on `$field` with the Spanish message
     */
    public function assertClean(string $text, string $field = 'markdown'): void
    {
        if ($this->find($text) !== null) {
            throw ValidationException::withMessages([$field => self::MESSAGE]);
        }
    }
}
