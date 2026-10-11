<?php

namespace App\Domain\GitHub;

use App\Domain\GitHub\Events\WorkLinker;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\GitHubRepository;
use App\Models\GitHubWebhookEvent;
use App\Models\User;
use App\Support\Ai\Presenters\GitHubPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the stored webhook events of one repository say (types/github.ts):
 * the latest state of each pull request and issue, GitHub accounts shown
 * as Gestor people (`users.github_login`) and the Gestor work they link
 * to. Only processed events count; GitHub is never called.
 */
final class RepoActivity
{
    /** Newest stored events read per type. */
    private const EVENTS_LIMIT = 300;

    /** @var array<string, Collection<int, GitHubWebhookEvent>> */
    private array $events = [];

    /** @var array<string, ?User> */
    private array $people = [];

    /** @var ?list<int> */
    private ?array $projectIds = null;

    public function __construct(public readonly GitHubRepository $repo) {}

    /**
     * Repository of a project: its own link, else its product's.
     */
    public static function repositoryOf(AiProject $project): ?GitHubRepository
    {
        return $project->githubRepository()->first()
            ?? ($project->product_id === null ? null : GitHubRepository::query()
                ->where('linkable_type', AiProduct::class)
                ->where('linkable_id', $project->product_id)
                ->first());
    }

    /**
     * Processed events of a type, oldest first.
     *
     * @return Collection<int, GitHubWebhookEvent>
     */
    public function events(string $type): Collection
    {
        return $this->events[$type] ??= GitHubWebhookEvent::query()
            ->where('event', $type)
            ->where('status', GitHubWebhookEvent::STATUS_PROCESSED)
            ->where(DB::raw('lower(repository)'), Str::lower($this->repo->full_name))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(self::EVENTS_LIMIT)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Latest payload of each pull request, by number.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pullRequests(): array
    {
        $latest = [];

        foreach ($this->events('pull_request') as $event) {
            $pr = $event->payload['pull_request'] ?? null;

            if (is_array($pr) && (int) ($pr['number'] ?? 0) > 0) {
                $latest[(int) $pr['number']] = $pr;
            }
        }

        return $latest;
    }

    /**
     * Latest payload of each issue (pull requests excluded), by number.
     *
     * @return array<int, array<string, mixed>>
     */
    public function issues(): array
    {
        $latest = [];

        foreach ($this->events('issues') as $event) {
            $issue = $event->payload['issue'] ?? null;

            if (is_array($issue) && (int) ($issue['number'] ?? 0) > 0 && ! isset($issue['pull_request'])) {
                $latest[(int) $issue['number']] = $issue;
            }
        }

        return $latest;
    }

    /**
     * `PullRequest` from a webhook `pull_request` object.
     *
     * @param  array<string, mixed>  $pr
     * @return array<string, mixed>
     */
    public function pullRequest(array $pr): array
    {
        $number = (int) $pr['number'];
        $branch = (string) ($pr['head']['ref'] ?? '');
        $merged = (bool) ($pr['merged'] ?? false) || isset($pr['merged_at']);
        $state = $merged ? 'merged' : ((string) ($pr['state'] ?? 'open') === 'closed' ? 'closed' : 'open');
        $title = (string) ($pr['title'] ?? '');
        $keys = GiaKeys::extract($title, isset($pr['body']) ? (string) $pr['body'] : null, $branch);

        return [
            'number' => $number,
            'title' => $title,
            'state' => $state,
            'ci' => GitHubPresenter::ci($this->repo, $branch)['status'],
            'commits' => (int) ($pr['commits'] ?? 0),
            'author' => $this->actor($pr['user']['login'] ?? null),
            'branch' => $branch,
            'base_branch' => (string) ($pr['base']['ref'] ?? $this->repo->default_branch),
            'linked' => $this->linked($keys, $this->taskWith('github_pr_number', $number, $pr['html_url'] ?? null, 'github_pr_url')),
            'url' => (string) ($pr['html_url'] ?? $this->repo->html_url."/pull/{$number}"),
            'opened_at' => self::iso($pr['created_at'] ?? null) ?? now()->toIso8601String(),
            'merged_at' => self::iso($pr['merged_at'] ?? null),
            'closed_at' => self::iso($pr['closed_at'] ?? null),
        ];
    }

    /**
     * `GitHubIssue` from a webhook `issue` object, completed with what the
     * Gestor stored when it created the issue.
     *
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    public function issue(array $issue, ?AiTask $task = null): array
    {
        $number = (int) $issue['number'];
        $task ??= $this->taskWith('github_issue_number', $number, $issue['html_url'] ?? null, 'github_issue_url');
        $snapshot = $task !== null && (int) $task->github_issue_number === $number ? ($task->github_issue ?? null) : null;
        $title = (string) ($issue['title'] ?? '');
        $labels = array_values(array_filter(array_map(
            fn ($label) => is_array($label) ? (string) ($label['name'] ?? '') : (string) $label,
            (array) ($issue['labels'] ?? []),
        )));
        $assignee = $issue['assignee']['login'] ?? ($issue['assignees'][0]['login'] ?? null);
        $creator = is_array($snapshot) && isset($snapshot['created_by_id']) ? User::query()->find($snapshot['created_by_id']) : null;

        return [
            'number' => $number,
            'title' => $title,
            'state' => (string) ($issue['state'] ?? 'open') === 'closed' ? 'closed' : 'open',
            'labels' => $labels,
            'assignee' => $assignee === null ? null : $this->actor($assignee),
            'created_from_gestor' => is_array($snapshot) || in_array(IssueText::LABEL, $labels, true),
            'created_by' => $creator !== null ? self::person($creator) : (isset($issue['user']['login']) ? $this->actor($issue['user']['login']) : null),
            'created_at' => self::iso($issue['created_at'] ?? null) ?? now()->toIso8601String(),
            'closed_at' => self::iso($issue['closed_at'] ?? null),
            'closed_by_pr' => $this->closingPullRequest($number),
            'linked' => $this->linked(GiaKeys::extract($title, isset($issue['body']) ? (string) $issue['body'] : null), $task),
            'url' => (string) ($issue['html_url'] ?? $this->repo->html_url."/issues/{$number}"),
        ];
    }

    /**
     * `GitHubIssue` of a task's issue: the latest `issues` event, else what
     * the Gestor stored when creating it.
     *
     * @return array<string, mixed>|null
     */
    public function taskIssue(AiTask $task): ?array
    {
        $number = $task->github_issue_number;

        if ($number === null) {
            return null;
        }

        $issue = $this->issues()[$number] ?? null;

        if ($issue !== null) {
            return $this->issue($issue, $task);
        }

        $snapshot = (array) ($task->github_issue ?? []);

        return $this->issue([
            'number' => $number,
            'title' => (string) ($snapshot['title'] ?? "Issue #{$number}"),
            'state' => $task->github_issue_state ?? 'open',
            'labels' => (array) ($snapshot['labels'] ?? []),
            'assignee' => isset($snapshot['assignee_login']) ? ['login' => $snapshot['assignee_login']] : null,
            'created_at' => $snapshot['created_at'] ?? $task->updated_at?->toIso8601String(),
            'closed_at' => null,
            'html_url' => $task->github_issue_url,
        ], $task);
    }

    /**
     * `GitHubActor`: the Gestor person with that login, else the login.
     *
     * @return array{name: string, login: ?string}
     */
    public function actor(mixed $login, ?string $fallbackName = null): array
    {
        $login = is_string($login) && $login !== '' ? $login : null;
        $user = $login === null ? null : $this->user($login);

        return [
            'name' => $user?->name ?? $fallbackName ?? $login ?? 'GitHub',
            'login' => $login === null ? null : '@'.$login,
        ];
    }

    /**
     * `GitHubActor` of a Gestor person.
     *
     * @return array{name: string, login: ?string}
     */
    public static function person(User $user): array
    {
        return ['name' => $user->name, 'login' => $user->github_login === null ? null : '@'.$user->github_login];
    }

    public function user(string $login): ?User
    {
        $key = Str::lower($login);

        if (! array_key_exists($key, $this->people)) {
            $this->people[$key] = User::query()->where(DB::raw('lower(github_login)'), $key)->first();
        }

        return $this->people[$key];
    }

    /**
     * Projects whose work lives in this repository.
     *
     * @return list<int>
     */
    public function projectIds(): array
    {
        return $this->projectIds ??= app(WorkLinker::class)->repositoryProjectIds($this->repo);
    }

    /**
     * `LinkedWork`: the first `GIA-…` key and the task holding the PR/issue.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>|null
     */
    private function linked(array $keys, ?AiTask $task): ?array
    {
        $project = $task?->project;
        $requestKey = $keys[0] ?? $project?->originRequest()?->key;

        if ($requestKey === null) {
            return null;
        }

        return [
            'request_key' => $requestKey,
            'project_key' => $project?->key,
            'task_key' => $task?->key,
            'task_title' => $task?->title,
        ];
    }

    private function taskWith(string $numberColumn, int $number, mixed $url, string $urlColumn): ?AiTask
    {
        $projects = $this->projectIds();

        return AiTask::query()
            ->where(function ($query) use ($numberColumn, $number, $url, $urlColumn, $projects): void {
                $query->where(fn ($q) => $q->whereIn('ai_project_id', $projects)->where($numberColumn, $number));

                if (is_string($url) && $url !== '') {
                    $query->orWhere($urlColumn, $url);
                }
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * Merged PR whose body closes the issue ("Closes #12").
     */
    private function closingPullRequest(int $issue): ?int
    {
        foreach (array_reverse($this->pullRequests(), true) as $number => $pr) {
            if (($pr['merged_at'] ?? null) !== null
                && preg_match('/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s+#'.$issue.'\b/i', (string) ($pr['body'] ?? '')) === 1) {
                return $number;
            }
        }

        return null;
    }

    public static function iso(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value)->setTimezone(config('app.timezone'))->toIso8601String() : null;
    }
}
