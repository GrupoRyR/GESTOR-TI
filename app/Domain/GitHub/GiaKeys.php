<?php

namespace App\Domain\GitHub;

/**
 * Request keys inside GitHub data: a webhook event (PR, issue, push) links
 * to a request when its title, body or branch name mentions `GIA-123`,
 * case-insensitively (`gia-123-arreglar-filtro` counts). Mirrors
 * resources/js/lib/githubKeys.ts; keep both in sync.
 */
final class GiaKeys
{
    /** `GIA-` + digits, not glued to letters/digits on either side. */
    private const PATTERN = '/(?<![A-Za-z0-9])gia-(\d+)(?![A-Za-z0-9])/i';

    /**
     * Every key mentioned in title, body and branch, upper-cased, first seen
     * first, no repeats. Zero padding is kept as written (`GIA-095`).
     *
     * @return list<string>
     */
    public static function extract(?string $title = null, ?string $body = null, ?string $branch = null): array
    {
        $keys = [];

        foreach ([$title, $body, $branch] as $source) {
            if ($source === null || $source === '') {
                continue;
            }

            preg_match_all(self::PATTERN, $source, $matches);

            foreach ($matches[1] as $digits) {
                $key = 'GIA-'.$digits;
                if (! in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * "gia-139" → "GIA-139"; null when it is not a single key.
     */
    public static function normalize(string $value): ?string
    {
        return preg_match('/^\s*gia-(\d+)\s*$/i', $value, $match) === 1 ? 'GIA-'.$match[1] : null;
    }
}
