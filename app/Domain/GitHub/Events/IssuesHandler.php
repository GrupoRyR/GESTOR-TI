<?php

namespace App\Domain\GitHub\Events;

use App\Domain\GitHub\GiaKeys;
use App\Models\GitHubRepository;

/**
 * `issues`: keeps the `GIA-…` keys of the issue on the event and the issue
 * state (`open` | `closed`) of the task that holds it (same issue URL, or
 * same number in a project of this repository).
 */
class IssuesHandler implements EventHandler
{
    public function __construct(private readonly WorkLinker $linker) {}

    public function needsLinkedRepository(): bool
    {
        return true;
    }

    public function handle(string $action, array $payload, ?GitHubRepository $repository): Outcome
    {
        $issue = is_array($payload['issue'] ?? null) ? $payload['issue'] : [];
        $keys = GiaKeys::extract((string) ($issue['title'] ?? ''), isset($issue['body']) ? (string) $issue['body'] : null);
        $number = (int) ($issue['number'] ?? 0);

        if ($number > 0) {
            $state = (string) ($issue['state'] ?? 'open') === 'closed' ? 'closed' : 'open';
            $url = isset($issue['html_url']) ? (string) $issue['html_url'] : null;

            /** @var GitHubRepository $repository */
            foreach ($this->linker->tasksWithIssue($repository, $number, $url) as $task) {
                $task->forceFill(['github_issue_state' => $state])->save();
            }
        }

        return Outcome::processed($keys);
    }
}
