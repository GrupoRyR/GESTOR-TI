<?php

namespace App\Support;

/**
 * Commit installed on the server. The deploy workflow writes the `REVISION`
 * file (`<commit sha> <UTC date>`) next to `artisan`; locally it is absent.
 * Shown by `php artisan about --only=gestor_ti`.
 */
class DeployedRevision
{
    /**
     * @return array{commit: string, short: string, deployed_at: string|null}|null
     */
    public static function current(): ?array
    {
        $file = (string) config('gestor.revision_file', base_path('REVISION'));

        if (! is_file($file)) {
            return null;
        }

        $parts = preg_split('/\s+/', trim((string) file_get_contents($file))) ?: [];
        $commit = (string) ($parts[0] ?? '');

        if (preg_match('/^[0-9a-f]{7,40}$/i', $commit) !== 1) {
            return null;
        }

        return ['commit' => $commit, 'short' => substr($commit, 0, 7), 'deployed_at' => $parts[1] ?? null];
    }
}
