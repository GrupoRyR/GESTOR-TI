<?php

namespace App\Support\Mail;

/**
 * Turns a design email template (resources/emails/*.html|txt, the source of
 * truth) into the Blade view the app sends, following the marker table of
 * resources/emails/README.md:
 *
 * - `{{ name }}` → `{{ $name }}`, `{{ item.field }}` → `{{ $item['field'] }}`
 *   (always escaped, never `{!! !!}`);
 * - `<!-- @if x -->`, `@else`, `@endif`, `<!-- @optional x -->` /
 *   `@endoptional` and `<!-- @foreach list as item -->` / `@endforeach`
 *   become the Blade directives;
 * - the documentation comments (`subject:`, `from:`, `to:`, `when:`,
 *   `partial:`) are dropped;
 * - Outlook conditionals (`<!--[if mso]>`) stay untouched.
 *
 * A marker alone on its line removes the whole line (Blade swallows the
 * newline after a directive). An inline directive that ends a line gets an
 * extra newline so the line break survives.
 *
 * The committed views under resources/views/mail/ are generated with this
 * class; tests/Feature/Mail/TemplateViewsTest.php fails when they drift
 * from their source.
 */
final class TemplateToBlade
{
    private const DOC_COMMENT = '/<!--\s*(?:subject|from|to|when|partial):.*?-->/u';

    /**
     * One marker comment; its argument never spans another comment, so a
     * line with several markers is never read as a single one.
     */
    private const MARKER = '/<!--\s*@(if|else|endif|optional|endoptional|foreach|endforeach)\b\s*((?:(?!-->).)*?)\s*-->/u';

    public static function convert(string $source): string
    {
        $lines = preg_split('/\r\n|\n/', $source) ?: [];
        $output = [];

        foreach ($lines as $line) {
            $withoutDocs = preg_replace(self::DOC_COMMENT, '', $line) ?? $line;

            if ($withoutDocs !== $line && trim($withoutDocs) === '') {
                continue;
            }

            $converted = self::echoes($withoutDocs);

            if (preg_match('/^\s*'.substr(self::MARKER, 1, -2).'\s*$/u', $converted, $match) === 1) {
                $output[] = self::directive($match[1], $match[2]);

                continue;
            }

            $converted = preg_replace_callback(
                self::MARKER,
                fn (array $match): string => self::inline(
                    $match[1][0],
                    $match[2][0],
                    $match[0][1] > 0 ? $converted[$match[0][1] - 1] : '',
                    substr($converted, $match[0][1] + strlen($match[0][0]), 1),
                ),
                $converted,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $converted;

            if (preg_match('/(@(endif|else|endforeach)|\?>)$/', $converted) === 1 || preg_match('/@(if|foreach) \(.*\)$/', $converted) === 1) {
                $converted .= "\n";
            }

            $output[] = $converted;
        }

        return implode("\n", $output);
    }

    private static function echoes(string $line): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?)\s*\}\}/',
            fn (array $match): string => '{{ '.self::variable($match[1]).' }}',
            $line,
        ) ?? $line;
    }

    private static function directive(string $name, string $argument): string
    {
        return match ($name) {
            'if', 'optional' => '@if ('.self::variable($argument).')',
            'else' => '@else',
            'endif', 'endoptional' => '@endif',
            'foreach' => self::foreach($argument),
            'endforeach' => '@endforeach',
        };
    }

    /**
     * An inline directive. Blade ignores a directive right after a letter
     * or digit ("esperando@else") and reads a bare one followed by a letter
     * as a longer name ("@elseRecibes"), so there it becomes the equivalent
     * PHP tag, which also swallows the newline after it like a directive.
     */
    private static function inline(string $name, string $argument, string $before, string $after): string
    {
        $bare = in_array($name, ['else', 'endif', 'endoptional', 'endforeach'], true);

        if (preg_match('/^\w$/', $before) !== 1 && ! ($bare && preg_match('/^\w$/', $after) === 1)) {
            return self::directive($name, $argument);
        }

        return match ($name) {
            'if', 'optional' => '<?php if ('.self::variable($argument).'): ?>',
            'else' => '<?php else: ?>',
            'endif', 'endoptional' => '<?php endif; ?>',
            'foreach' => '<?php foreach ('.self::foreachArguments($argument).'): ?>',
            'endforeach' => '<?php endforeach; ?>',
        };
    }

    private static function foreachArguments(string $argument): string
    {
        [$list, $item] = array_map('trim', explode(' as ', $argument, 2));

        return self::variable($list).' as $'.$item;
    }

    private static function foreach(string $argument): string
    {
        [$list, $item] = array_map('trim', explode(' as ', $argument, 2));

        return '@foreach ('.self::variable($list).' as $'.$item.')';
    }

    /**
     * `name` → `$name`, `item.field` → `$item['field']`.
     */
    private static function variable(string $name): string
    {
        $parts = explode('.', trim($name), 2);

        return '$'.$parts[0].(isset($parts[1]) ? "['{$parts[1]}']" : '');
    }
}
