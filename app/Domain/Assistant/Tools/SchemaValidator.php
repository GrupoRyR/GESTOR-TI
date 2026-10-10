<?php

namespace App\Domain\Assistant\Tools;

/**
 * Checks tool arguments against the tool's JSON schema (the object schema
 * the provider received) before the tool runs (PROMPT 6.8.3). Supports what
 * the assistant tools use: required, additionalProperties false, type
 * (string, integer, number, boolean, array, null), enum, minLength,
 * maxLength, minimum, maximum and pattern. Messages are Spanish and go back
 * to the model so it can correct the call.
 */
final class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function errors(array $arguments, array $schema): array
    {
        $errors = [];
        $properties = (array) ($schema['properties'] ?? []);
        $required = (array) ($schema['required'] ?? []);

        foreach ($properties as $name => $definition) {
            if (! array_key_exists($name, $arguments)) {
                if (in_array($name, $required, true)) {
                    $errors[] = "Falta «{$name}».";
                }

                continue;
            }

            $error = self::property((string) $name, $arguments[$name], (array) $definition);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($arguments) as $name) {
                if (! array_key_exists($name, $properties)) {
                    $errors[] = "«{$name}» no es un argumento de esta herramienta.";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function property(string $name, mixed $value, array $definition): ?string
    {
        $types = (array) ($definition['type'] ?? []);

        if ($value === null) {
            return in_array('null', $types, true) || $types === [] ? null : "«{$name}» no puede estar vacío.";
        }

        $type = array_values(array_diff($types, ['null']))[0] ?? null;
        $typeError = match ($type) {
            'string' => is_string($value) ? null : "«{$name}» debe ser texto.",
            'integer' => is_int($value) ? null : "«{$name}» debe ser un número entero.",
            'number' => is_int($value) || is_float($value) ? null : "«{$name}» debe ser un número.",
            'boolean' => is_bool($value) ? null : "«{$name}» debe ser verdadero o falso.",
            'array' => is_array($value) && array_is_list($value) ? null : "«{$name}» debe ser una lista.",
            default => null,
        };

        if ($typeError !== null) {
            return $typeError;
        }

        if (isset($definition['enum']) && ! in_array($value, (array) $definition['enum'], true)) {
            return "«{$name}» debe ser uno de: ".implode(', ', array_map('strval', (array) $definition['enum'])).'.';
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($definition['maxLength']) && $length > (int) $definition['maxLength']) {
                return "«{$name}» admite máximo {$definition['maxLength']} caracteres.";
            }
            if (isset($definition['minLength']) && $length < (int) $definition['minLength']) {
                return "«{$name}» necesita al menos {$definition['minLength']} caracteres.";
            }
            if (isset($definition['pattern']) && preg_match('/'.str_replace('/', '\/', (string) $definition['pattern']).'/u', $value) !== 1) {
                return "«{$name}» no tiene el formato esperado.";
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($definition['minimum']) && $value < $definition['minimum']) {
                return "«{$name}» debe ser como mínimo {$definition['minimum']}.";
            }
            if (isset($definition['maximum']) && $value > $definition['maximum']) {
                return "«{$name}» debe ser como máximo {$definition['maximum']}.";
            }
        }

        return null;
    }
}
