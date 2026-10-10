<?php

namespace App\Domain\GitHub;

use App\Domain\Ai\Board;
use App\Domain\Ai\BoardPeople;
use App\Models\AcceptanceCriterion;
use App\Models\AiProject;
use App\Models\AiProjectActivity;
use App\Models\AiTask;
use App\Models\GitHubRepository;
use App\Models\User;
use App\Support\Ai\Presenters\GitHubPresenter;
use App\Support\Ai\Presenters\RequestPresenter;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

/**
 * `TaskGitHub` (types/pages/ai-github.ts): the GitHub block of a task on
 * the board drawer (`selected_task_github`) and the task page (`github`).
 * GitHub data only goes to `ai.manage`; "Crear issue" (`issue_form`) also
 * needs `ai.github.manage`, a linked repository and a task without issue.
 *
 * `events` mixes the stored webhook events of the task's PR (opened,
 * merged, CI runs), the issue the Gestor created and the board moves of
 * the activity feed ("movió T3 … a En revisión").
 */
final class TaskActivity
{
    private const EVENTS_LIMIT = 20;

    /** Error keys of `ai.tasks.issue.store`. */
    public const ERROR_KEYS = ['title', 'body', 'labels', 'assignee_id'];

    /** Board column → event kind of a move. */
    private const MOVES = [
        Board::REVIEW => 'moved_review',
        Board::DONE => 'moved_done',
        Board::IN_PROGRESS => 'moved_progress',
    ];

    public function __construct(private readonly BoardPeople $people) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(AiTask $task, AiProject $project, User $viewer, ?ViewErrorBag $errors = null): ?array
    {
        if (! $viewer->can('ai.manage')) {
            return null;
        }

        $repo = RepoActivity::repositoryOf($project);
        $activity = $repo === null ? null : new RepoActivity($repo);
        $pr = $activity === null || $task->github_pr_number === null ? null : ($activity->pullRequests()[$task->github_pr_number] ?? null);
        $origin = $project->originRequest();

        return [
            'repo' => $repo === null ? null : GitHubPresenter::repoRef($repo),
            'request_key' => (string) $origin?->key,
            'task_url' => route('ai.tasks.show', [$project, $task]),
            'criteria' => $origin === null ? [] : $origin->criteria()->get()
                ->map(fn (AcceptanceCriterion $criterion) => ['id' => $criterion->id, 'text' => $criterion->text])
                ->values()->all(),
            'issue' => $activity?->taskIssue($task),
            'pull_request' => $pr === null ? null : $activity?->pullRequest($pr),
            'ci' => $this->ci($task, $repo, $pr),
            'events' => $this->events($task, $project, $activity, $pr),
            'issue_form' => $this->issueForm($task, $project, $viewer, $repo, $activity, $errors),
        ];
    }

    /**
     * CI of the open PR (its head branch), else the task's stored status.
     *
     * @param  array<string, mixed>|null  $pr
     * @return array<string, mixed>|null
     */
    private function ci(AiTask $task, ?GitHubRepository $repo, ?array $pr): ?array
    {
        if ($repo !== null && $pr !== null && isset($pr['head']['ref'])) {
            $run = GitHubPresenter::ci($repo, (string) $pr['head']['ref']);

            if ($run['status'] !== 'none' || $task->github_ci === null) {
                return $run;
            }
        }

        if ($task->github_ci === null) {
            return null;
        }

        return ['status' => $task->github_ci, 'branch' => (string) ($pr['head']['ref'] ?? ''), 'commit_sha' => null, 'run_at' => null, 'summary' => null, 'url' => null];
    }

    /**
     * `IssueFormSetup`; null without permission, repository or once the task has an issue.
     *
     * @return array<string, mixed>|null
     */
    private function issueForm(AiTask $task, AiProject $project, User $viewer, ?GitHubRepository $repo, ?RepoActivity $activity, ?ViewErrorBag $errors): ?array
    {
        if (! $viewer->can('ai.github.manage') || $repo === null || $activity === null || $task->github_issue_number !== null) {
            return null;
        }

        $task->loadMissing('assignees');
        $labels = [IssueText::LABEL];

        foreach ($activity->issues() as $issue) {
            foreach ((array) ($issue['labels'] ?? []) as $label) {
                $name = is_array($label) ? (string) ($label['name'] ?? '') : (string) $label;

                if ($name !== '' && ! in_array($name, $labels, true)) {
                    $labels[] = $name;
                }
            }
        }

        return [
            'open' => $errors !== null && $errors->getBag('default')->hasAny(self::ERROR_KEYS),
            'assignees' => $this->people->for($project)
                ->map(fn (User $user) => [
                    ...RequestPresenter::person($user),
                    'github_login' => $user->github_login === null ? null : '@'.$user->github_login,
                ])
                ->values()->all(),
            'assignee_id' => $task->assignees->first()?->id,
            'label_options' => $labels,
        ];
    }

    /**
     * `TaskGitHubEvent[]`, newest first. Ids are unique across sources:
     * webhook event id × 10 + 1, activity id × 10 + 2, task id × 10 + 3.
     *
     * @param  array<string, mixed>|null  $pr
     * @return list<array<string, mixed>>
     */
    private function events(AiTask $task, AiProject $project, ?RepoActivity $activity, ?array $pr): array
    {
        $events = [];

        if ($activity !== null && $task->github_pr_number !== null) {
            $number = $task->github_pr_number;
            $branch = isset($pr['head']['ref']) ? (string) $pr['head']['ref'] : null;

            foreach ($activity->events('pull_request') as $event) {
                if ((int) ($event->payload['pull_request']['number'] ?? 0) !== $number) {
                    continue;
                }

                $merged = $event->action === 'closed' && (bool) ($event->payload['pull_request']['merged'] ?? false);
                $kind = match (true) {
                    $merged => 'pr_merged',
                    in_array($event->action, ['opened', 'reopened', 'ready_for_review'], true) => 'pr_opened',
                    default => null,
                };

                if ($kind !== null) {
                    $events[] = [
                        'id' => $event->id * 10 + 1,
                        'kind' => $kind,
                        'title' => $kind === 'pr_merged' ? "Se fusionó el PR #{$number}" : "Se abrió el PR #{$number}",
                        'at' => RepoActivity::iso(match (true) {
                            $merged => $event->payload['pull_request']['merged_at'] ?? null,
                            $event->action === 'opened' => $event->payload['pull_request']['created_at'] ?? null,
                            default => null,
                        }) ?? $event->received_at->toIso8601String(),
                        'note' => $activity->actor($event->payload['sender']['login'] ?? ($event->payload['pull_request']['user']['login'] ?? null))['name'],
                    ];
                }
            }

            foreach ($activity->events('workflow_run') as $event) {
                $run = (array) ($event->payload['workflow_run'] ?? []);
                $numbers = array_map(fn ($item) => is_array($item) ? (int) ($item['number'] ?? 0) : 0, (array) ($run['pull_requests'] ?? []));
                $onPr = in_array($number, $numbers, true) || ($branch !== null && ($run['head_branch'] ?? null) === $branch);

                if (! $onPr || ($run['status'] ?? null) !== 'completed') {
                    continue;
                }

                $status = Events\WorkflowRunHandler::status('completed', isset($run['conclusion']) ? (string) $run['conclusion'] : null);

                if (! in_array($status, ['passing', 'failing'], true)) {
                    continue;
                }

                $events[] = [
                    'id' => $event->id * 10 + 1,
                    'kind' => $status === 'passing' ? 'ci_passed' : 'ci_failed',
                    'title' => ($status === 'passing' ? 'CI pasó' : 'CI falló')." en el PR #{$number}",
                    'at' => RepoActivity::iso($run['updated_at'] ?? null) ?? $event->received_at->toIso8601String(),
                    'note' => isset($run['name']) ? (string) $run['name'] : null,
                ];
            }
        }

        $snapshot = $task->github_issue;

        if (is_array($snapshot) && $task->github_issue_number !== null) {
            $creator = isset($snapshot['created_by_id']) ? User::query()->find($snapshot['created_by_id']) : null;
            $events[] = [
                'id' => $task->id * 10 + 3,
                'kind' => 'issue_created',
                'title' => "Se creó el issue #{$task->github_issue_number} en GitHub",
                'at' => RepoActivity::iso($snapshot['created_at'] ?? null) ?? now()->toIso8601String(),
                'note' => $creator?->name,
            ];
        }

        foreach ($this->moves($task, $project) as $move) {
            $events[] = $move;
        }

        usort($events, fn (array $a, array $b) => [$b['at'], $b['id']] <=> [$a['at'], $a['id']]);

        return array_slice($events, 0, self::EVENTS_LIMIT);
    }

    /**
     * Board moves of the task from the activity feed ("movió T3 … a En revisión").
     *
     * @return list<array<string, mixed>>
     */
    private function moves(AiTask $task, AiProject $project): array
    {
        $columns = $project->columns()->get()->filter(fn ($column) => isset(self::MOVES[$column->key]));
        $prefix = "movió {$task->key} ";

        return $project->activities()
            ->with('user')
            ->where('kind', 'task')
            ->where('text', 'like', $prefix.'%')
            ->latest('id')
            ->limit(self::EVENTS_LIMIT)
            ->get()
            ->map(function (AiProjectActivity $activity) use ($columns, $task) {
                $column = $columns->first(fn ($column) => Str::endsWith($activity->text, ' a '.$column->name));

                if ($column === null) {
                    return null;
                }

                $byPr = $activity->user === null && $task->github_pr_number !== null && $column->key !== Board::IN_PROGRESS;

                return [
                    'id' => $activity->id * 10 + 2,
                    'kind' => self::MOVES[$column->key],
                    'title' => "Pasó a {$column->name}".($byPr ? " por el PR #{$task->github_pr_number}" : ''),
                    'at' => ($activity->created_at ?? now())->toIso8601String(),
                    'note' => $activity->user?->name ?? 'automático, desde GitHub',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
