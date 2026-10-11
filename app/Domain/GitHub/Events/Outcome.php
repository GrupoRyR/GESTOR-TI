<?php

namespace App\Domain\GitHub\Events;

use App\Models\GitHubWebhookEvent;

/**
 * Result of one handler: `processed` (with the `GIA-…` keys it found) or
 * `ignored` (nothing to do: draft, other branch, prerelease…).
 */
final class Outcome
{
    /**
     * @param  list<string>  $keys
     */
    private function __construct(public readonly string $status, public readonly array $keys) {}

    /**
     * @param  list<string>  $keys
     */
    public static function processed(array $keys = []): self
    {
        return new self(GitHubWebhookEvent::STATUS_PROCESSED, array_values(array_unique($keys)));
    }

    /**
     * @param  list<string>  $keys
     */
    public static function ignored(array $keys = []): self
    {
        return new self(GitHubWebhookEvent::STATUS_IGNORED, array_values(array_unique($keys)));
    }
}
