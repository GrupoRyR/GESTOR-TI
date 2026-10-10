<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processes one stored GitHub webhook delivery (`github_webhook_events`) on
 * the database queue. Dispatched by the webhook endpoint and by
 * "Reprocesar" in Administración › Integraciones › GitHub.
 *
 * Stage D1 only defines the entry point: the processor (issues,
 * pull_request, workflow_run, push, release) arrives in Stage D2, so the
 * event stays `pending` until then.
 */
class ProcessGitHubEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        // Filled in Stage D2.
    }
}
