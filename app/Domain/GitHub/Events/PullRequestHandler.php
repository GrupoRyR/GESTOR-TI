<?php

namespace App\Domain\GitHub\Events;

use App\Actions\Ai\MoveTask;
use App\Domain\Ai\Board;
use App\Domain\GitHub\GiaKeys;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\GitHubRepository;

/**
 * `pull_request` (PROMPT 6.4.6): a PR that mentions `GIA-123` links to its
 * task (`github_pr_*` on ai_tasks, see WorkLinker for the matching).
 *
 * - opened / reopened / ready for review (not draft): the task moves to
 *   "En revisión" unless it is already there or in "Hecho".
 * - closed and merged: the task moves to "Hecho".
 * - Moves go through App\Actions\Ai\MoveTask (positions, project status,
 *   "movió T3 … a En revisión"); the actor is the Gestor person with the
 *   PR author's (or merger's) GitHub login, else none.
 * - Each project gets one "github" activity per opened/ready/closed PR.
 */
class PullRequestHandler implements EventHandler
{
    private const OPENING_ACTIONS = ['opened', 'reopened', 'ready_for_review'];

    public function __construct(
        private readonly WorkLinker $linker,
        private readonly MoveTask $move,
    ) {}

    public function needsLinkedRepository(): bool
    {
        return true;
    }

    public function handle(string $action, array $payload, ?GitHubRepository $repository): Outcome
    {
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $number = (int) ($pr['number'] ?? $payload['number'] ?? 0);
        $title = (string) ($pr['title'] ?? '');
        $body = isset($pr['body']) ? (string) $pr['body'] : null;
        $branch = isset($pr['head']['ref']) ? (string) $pr['head']['ref'] : null;
        $url = isset($pr['html_url']) ? (string) $pr['html_url'] : null;
        $merged = (bool) ($pr['merged'] ?? false);
        $state = $merged ? 'merged' : ((string) ($pr['state'] ?? 'open') === 'closed' ? 'closed' : 'open');

        $keys = GiaKeys::extract($title, $body, $branch);
        /** @var GitHubRepository $repository */
        $tasks = $this->linker->tasksFor($repository, $keys, $title, $branch, $body, $url);

        if ($tasks->isEmpty()) {
            return Outcome::processed($keys);
        }

        $actor = $this->linker->user($merged ? ($pr['merged_by']['login'] ?? null) : ($pr['user']['login'] ?? null));
        $target = $this->targetColumn($action, $merged, (bool) ($pr['draft'] ?? false));
        $projects = [];

        foreach ($tasks as $task) {
            $task->forceFill(['github_pr_number' => $number, 'github_pr_url' => $url, 'github_pr_state' => $state])->save();
            $projects[$task->ai_project_id] = true;

            if ($target !== null && $this->shouldMove($task, $target)) {
                $this->move->handle($task, $target, null, $actor);
            }
        }

        $sentence = $this->sentence($action, $merged, (bool) ($pr['draft'] ?? false));

        if ($sentence !== null) {
            AiProject::query()->withoutGlobalScopes()->whereKey(array_keys($projects))->get()
                ->each(fn (AiProject $project) => $project->recordActivity('github', "PR #{$number} {$sentence}: {$title}", $actor));
        }

        return Outcome::processed($keys);
    }

    private function targetColumn(string $action, bool $merged, bool $draft): ?string
    {
        if ($action === 'closed') {
            return $merged ? Board::DONE : null;
        }

        return in_array($action, self::OPENING_ACTIONS, true) && ! $draft ? Board::REVIEW : null;
    }

    /**
     * Forward only: never back out of "Hecho", and review only moves
     * tasks that are not in review yet.
     */
    private function shouldMove(AiTask $task, string $target): bool
    {
        $column = $task->column;

        if ($column === null || $column->key === $target) {
            return false;
        }

        return $target === Board::DONE || ! $column->is_done;
    }

    private function sentence(string $action, bool $merged, bool $draft): ?string
    {
        return match (true) {
            $action === 'closed' => $merged ? 'fusionado' : 'cerrado sin fusionar',
            $action === 'ready_for_review' => 'listo para revisión',
            $action === 'opened' => $draft ? 'abierto como borrador' : 'abierto',
            $action === 'reopened' => 'reabierto',
            default => null,
        };
    }
}
