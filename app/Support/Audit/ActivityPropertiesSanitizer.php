<?php

namespace App\Support\Audit;

/**
 * Removes sensitive entries from activity log properties before they leave
 * the server (Administración › Auditoría).
 *
 * Defence in depth: models already avoid logging secrets, but any key whose
 * name contains password, secret, token, value or key (case-insensitive) is
 * dropped at every nesting level. Lists keep their remaining items.
 */
class ActivityPropertiesSanitizer
{
    public const SENSITIVE_PATTERN = '/password|secret|token|value|key/i';

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $properties): array
    {
        $clean = [];

        foreach ($properties as $name => $item) {
            if (is_string($name) && preg_match(self::SENSITIVE_PATTERN, $name) === 1) {
                continue;
            }

            $clean[$name] = is_array($item) ? self::sanitize($item) : $item;
        }

        return array_is_list($properties) ? array_values($clean) : $clean;
    }
}
