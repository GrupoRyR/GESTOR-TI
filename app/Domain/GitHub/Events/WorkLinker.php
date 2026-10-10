<?php

namespace App\Domain\GitHub\Events;

use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\GitHubRepository;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds the Gestor work a GitHub event talks about.
 *
 * Projects in scope: the ones of the requests whose `GIA-…` key the event
 * mentions, plus the ones the repository belongs to (the linked project,
 * or the projects of the linked product). Inside them a task matches when
 * it already holds this PR, when its key (`T3`) appears in the title or
 * branch (`gia-139-t3-…`, "GIA-139 · T3 …"), or when its issue is the one
 * the PR closes ("Closes #12").
 */
class WorkLinker
{
    /** Task key "T3", not glued to letters or digits. */
    private const TASK_KEY = '/(?<![A-Za-z0-9])t(\d+)(?![A-Za-z0-9])/i';

    /** GitHub closing keywords: "Closes #12", "fixes #12", "resolved #12". */
    private const CLOSING = '/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s+#(\d+)\b/i';

    /**
     * Projects linked to the repository (directly or through its product).
     *
     * @return list<int>
     */
    public function repositoryProjectIds(GitHubRepository $repository): array
    {
        return match ($repository->linkable_type) {
            AiProject::class => [(int) $repository->linkable_id],
            AiProduct::class => AiProject::query()->withoutGlobalScopes()
                ->where('product_id', $repository->linkable_id)
                ->pluck('id')->map(fn ($id) => (int) $id)->all(),
            default => [],
        };
    }

    /**
     * Projects of the requests with these keys.
     *
     * @param  list<string>  $keys
     * @return list<int>
     */
    public function requestProjectIds(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return DB::table('ai_project_request')
            ->join('ai_requests', 'ai_requests.id', '=', 'ai_project_request.ai_request_id')
            ->whereIn('ai_requests.key', $keys)
            ->whereNull('ai_requests.deleted_at')
            ->distinct()
            ->pluck('ai_project_request.ai_project_id')
            ->map(fn ($id) => (int) $id)
            ->values()->all();
    }

    /**
     * Tasks a pull request (or a branch) works on.
     *
     * @param  list<string>  $keys
     * @return Collection<int, AiTask>
     */
    public function tasksFor(
        GitHubRepository $repository,
        array $keys,
        ?string $title,
        ?string $branch,
        ?string $body = null,
        ?string $prUrl = null,
        ?int $prNumber = null,
    ): Collection {
        $requestProjects = $this->requestProjectIds($keys);
        $repoProjects = $this->repositoryProjectIds($repository);
        $projectIds = array_values(array_unique([...$requestProjects, ...$repoProjects]));

        if ($projectIds === []) {
            return new Collection;
        }

        $taskKeys = $keys === [] ? [] : $this->taskKeys($title, $branch);
        $closedIssues = $this->closedIssues($body);

        if ($taskKeys === [] && $closedIssues === [] && $prUrl === null && $prNumber === null) {
            return new Collection;
        }

        return AiTask::query()
            ->whereIn('ai_project_id', $projectIds)
            ->where(function ($query) use ($taskKeys, $closedIssues, $prUrl, $prNumber, $requestProjects, $repoProjects): void {
                if ($prUrl !== null) {
                    $query->orWhere('github_pr_url', $prUrl);
                }

                if ($prNumber !== null && $repoProjects !== []) {
                    $query->orWhere(fn ($q) => $q->whereIn('ai_project_id', $repoProjects)->where('github_pr_number', $prNumber));
                }

                if ($taskKeys !== [] && $requestProjects !== []) {
                    $query->orWhere(fn ($q) => $q->whereIn('ai_project_id', $requestProjects)->whereIn('key', $taskKeys));
                }

                if ($closedIssues !== [] && $repoProjects !== []) {
                    $query->orWhere(fn ($q) => $q->whereIn('ai_project_id', $repoProjects)->whereIn('github_issue_number', $closedIssues));
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Tasks whose issue is this one: same URL, or same number in a project
     * of this repository.
     *
     * @return Collection<int, AiTask>
     */
    public function tasksWithIssue(GitHubRepository $repository, int $number, ?string $url): Collection
    {
        $repoProjects = $this->repositoryProjectIds($repository);

        return AiTask::query()
            ->where('github_issue_number', $number)
            ->where(function ($query) use ($url, $repoProjects): void {
                $query->whereIn('ai_project_id', $repoProjects);

                if ($url !== null) {
                    $query->orWhere('github_issue_url', $url);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * The Gestor person with this GitHub login (`users.github_login`).
     */
    public function user(mixed $login): ?User
    {
        if (! is_string($login) || $login === '') {
            return null;
        }

        return User::query()->where('github_login', $login)->first();
    }

    /**
     * @return list<string> "T3", …
     */
    private function taskKeys(?string $title, ?string $branch): array
    {
        $keys = [];

        foreach ([$title, $branch] as $source) {
            if ($source === null || $source === '') {
                continue;
            }

            preg_match_all(self::TASK_KEY, $source, $matches);

            foreach ($matches[1] as $digits) {
                $keys[] = 'T'.(int) $digits;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<int>
     */
    private function closedIssues(?string $body): array
    {
        if ($body === null || $body === '') {
            return [];
        }

        preg_match_all(self::CLOSING, $body, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }
}
