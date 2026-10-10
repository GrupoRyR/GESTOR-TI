<?php

namespace App\Domain\GitHub\Events;

use App\Models\GitHubRepository;
use App\Models\GitHubWebhookEvent;
use App\Services\GitHub\GitHubException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs the handler of a stored webhook event and records the outcome on
 * `github_webhook_events` (status, `GIA-…` keys, error, attempts).
 *
 * - `issues`, `pull_request`, `workflow_run`, `push` and `release` need
 *   the repository linked to a product or project: otherwise the event
 *   fails with `repo_not_linked` (Integraciones › GitHub offers
 *   "Vincularlo" and "Reprocesar"). A link with `receive_events` off
 *   ignores them.
 * - `installation` and `installation_repositories` refresh the installed
 *   repositories and need no link.
 */
class GitHubEventProcessor
{
    /** Event types stored and processed; anything else is not stored. */
    public const HANDLED_EVENTS = [
        ...GitHubWebhookEvent::SUBSCRIBED_EVENTS, 'installation', 'installation_repositories',
    ];

    private const HANDLERS = [
        'issues' => IssuesHandler::class,
        'pull_request' => PullRequestHandler::class,
        'workflow_run' => WorkflowRunHandler::class,
        'push' => PushHandler::class,
        'release' => ReleaseHandler::class,
        'installation' => InstallationHandler::class,
        'installation_repositories' => InstallationHandler::class,
    ];

    public function process(GitHubWebhookEvent $event): void
    {
        $event->forceFill(['attempts' => $event->attempts + 1])->save();

        $class = self::HANDLERS[$event->event] ?? null;

        if ($class === null) {
            $this->finish($event, Outcome::ignored());

            return;
        }

        /** @var EventHandler $handler */
        $handler = app($class);

        try {
            // Gestor data changes are atomic; installation handlers call
            // GitHub and write settings (cache), so they run outside.
            $outcome = $handler->needsLinkedRepository()
                ? DB::transaction(function () use ($event, $handler): Outcome {
                    $repository = $this->linkedRepository($event->payload);

                    return $repository->receive_events
                        ? $handler->handle((string) $event->action, $event->payload, $repository)
                        : Outcome::ignored();
                })
                : $handler->handle((string) $event->action, $event->payload, null);
        } catch (EventFailure $failure) {
            $event->forceFill([
                'status' => GitHubWebhookEvent::STATUS_FAILED,
                'error' => $failure->getMessage(),
                'error_code' => $failure->errorCode,
                'processed_at' => null,
            ])->save();

            return;
        }

        $this->finish($event, $outcome);
    }

    /**
     * Unexpected error (GitHub down, bug): the event shows as failed while
     * the queue retries it; the last attempt leaves it failed.
     */
    public function markCrashed(GitHubWebhookEvent $event, Throwable $error): void
    {
        $event->forceFill([
            'status' => GitHubWebhookEvent::STATUS_FAILED,
            'error' => 'No se pudo procesar el evento. Se reintentará; si sigue fallando, usa "Reprocesar".',
            'error_code' => $error instanceof GitHubException ? $error->reason : 'processing_error',
            'processed_at' => null,
        ])->save();
    }

    private function finish(GitHubWebhookEvent $event, Outcome $outcome): void
    {
        $event->forceFill([
            'status' => $outcome->status,
            'request_keys' => $outcome->keys === [] ? null : $outcome->keys,
            'error' => null,
            'error_code' => null,
            'processed_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function linkedRepository(array $payload): GitHubRepository
    {
        $data = is_array($payload['repository'] ?? null) ? $payload['repository'] : [];
        $repository = null;

        if (isset($data['id'])) {
            $repository = GitHubRepository::query()->where('github_id', (int) $data['id'])->first();
        }

        if ($repository === null && isset($data['full_name'])) {
            $repository = GitHubRepository::query()->where('full_name', (string) $data['full_name'])->first();
        }

        if ($repository === null || ! $repository->isLinked()) {
            throw EventFailure::repoNotLinked();
        }

        return $repository;
    }
}
