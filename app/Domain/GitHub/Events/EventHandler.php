<?php

namespace App\Domain\GitHub\Events;

use App\Models\GitHubRepository;

/**
 * Processes one GitHub event type. `$repository` is the linked repository
 * that receives events (GitHubEventProcessor checks it) or null for
 * handlers that do not need one (installation).
 */
interface EventHandler
{
    public function needsLinkedRepository(): bool;

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws EventFailure
     */
    public function handle(string $action, array $payload, ?GitHubRepository $repository): Outcome;
}
