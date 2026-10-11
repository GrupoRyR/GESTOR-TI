<?php

namespace App\Jobs;

use App\Domain\GitHub\Events\GitHubEventProcessor;
use App\Models\GitHubWebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes one stored GitHub webhook delivery (`github_webhook_events`) on
 * the database queue. Dispatched by the webhook endpoint and by
 * "Reprocesar" in Administración › Integraciones › GitHub.
 *
 * Processed and ignored events are skipped (a duplicate dispatch does
 * nothing). Data problems (`repo_not_linked`, `no_product`) fail the event
 * without retrying; unexpected errors (GitHub down, a bug) mark it failed
 * and are retried by the queue up to `$tries` times.
 */
class ProcessGitHubEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds */
    public array $backoff = [60, 300];

    public function __construct(public int $eventId) {}

    public function handle(GitHubEventProcessor $processor): void
    {
        $event = GitHubWebhookEvent::query()->find($this->eventId);

        if ($event === null || in_array($event->status, [GitHubWebhookEvent::STATUS_PROCESSED, GitHubWebhookEvent::STATUS_IGNORED], true)) {
            return;
        }

        try {
            $processor->process($event);
        } catch (Throwable $error) {
            $processor->markCrashed($event, $error);

            throw $error;
        }
    }
}
