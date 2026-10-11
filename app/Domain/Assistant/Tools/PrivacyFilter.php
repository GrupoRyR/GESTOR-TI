<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Ai\SecretGuard;
use Illuminate\Support\Str;

/**
 * Last gate before a tool result reaches the AI provider (PROMPT 6.8.3,
 * §8): the provider never receives credentials, vault content, rates,
 * salaries or costs. Tools already build their payload from safe fields;
 * this filter drops, at any depth, every key that names a secret or money
 * (password, secret, token, value, ciphertext, api key, cost/costo, rate,
 * tarifa, salary/salario/sueldo) and masks any text that looks like an
 * assigned password or key (App\Domain\Ai\SecretGuard).
 */
final class PrivacyFilter
{
    public const MASK = '[omitido: parece una credencial]';

    /** Words of a key (split on `_`, `-`, spaces and camelCase) that drop it. */
    private const BLOCKED_WORDS = [
        'password', 'passwords', 'passwd', 'pwd', 'contrasena', 'contrasenas',
        'secret', 'secrets', 'token', 'tokens', 'value', 'ciphertext', 'apikey',
        'cost', 'costs', 'costo', 'costos', 'rate', 'rates', 'tarifa', 'tarifas',
        'salary', 'salaries', 'salario', 'salarios', 'sueldo', 'sueldos',
    ];

    /** Fragments that drop a key wherever they appear. */
    private const BLOCKED_FRAGMENTS = ['password', 'secret', 'ciphertext', 'contrasen', 'salari', 'api_key', 'apikey'];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function clean(array $data): array
    {
        $guard = new SecretGuard;
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::blocked($key)) {
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => self::clean($value),
                is_string($value) => $guard->find($value) === null ? $value : self::MASK,
                default => $value,
            };
        }

        return $clean;
    }

    public static function blocked(string $key): bool
    {
        $normalized = Str::lower(Str::ascii(Str::snake($key)));

        foreach (self::BLOCKED_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        $words = preg_split('/[^a-z0-9]+/', $normalized) ?: [];

        return array_intersect($words, self::BLOCKED_WORDS) !== [];
    }
}
