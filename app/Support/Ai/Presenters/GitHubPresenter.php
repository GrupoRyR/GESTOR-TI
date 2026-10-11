<?php

namespace App\Support\Ai\Presenters;

use App\Models\GitHubRepository;
use App\Support\Integrations\GitHubIntegration;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Shapes of resources/js/types/github.ts built from `github_repositories`
 * rows: `GitHubRepo`, `GitHubRepoLink` and `CiRun`. Never calls GitHub and
 * never carries tokens or keys.
 */
final class GitHubPresenter
{
    /**
     * `GitHubRepo[]`: unlinked repositories first, then by full name.
     *
     * @return list<array<string, mixed>>
     */
    public static function repos(): array
    {
        return GitHubRepository::query()
            ->with(['linkable' => fn (MorphTo $morph) => $morph->withoutGlobalScopes()])
            ->orderBy('full_name')
            ->get()
            ->sortBy(fn (GitHubRepository $repo) => $repo->isLinked() ? 1 : 0)
            ->map(fn (GitHubRepository $repo) => self::repo($repo))
            ->values()
            ->all();
    }

    /**
     * `GitHubRepo`.
     *
     * @return array<string, mixed>
     */
    public static function repo(GitHubRepository $repo): array
    {
        return [
            'full_name' => $repo->full_name,
            'name' => $repo->name,
            'url' => $repo->html_url,
            'private' => $repo->private,
            'default_branch' => $repo->default_branch,
            'branches' => $repo->branchNames(),
            'language' => $repo->language,
            'pushed_at' => $repo->pushed_at?->toIso8601String(),
            'linked_to' => GitHubIntegration::linkedTo($repo->relationLoaded('linkable')
                ? $repo->linkable
                : ($repo->isLinked() ? $repo->linkable()->withoutGlobalScopes()->first() : null)),
        ];
    }

    /**
     * `Pick<GitHubRepo, 'full_name' | 'url'>`.
     *
     * @return array{full_name: string, url: string}
     */
    public static function repoRef(GitHubRepository $repo): array
    {
        return ['full_name' => $repo->full_name, 'url' => $repo->html_url];
    }

    /**
     * `GitHubRepoLink`.
     *
     * @return array<string, mixed>
     */
    public static function link(GitHubRepository $repo): array
    {
        return [
            'repo' => self::repo($repo),
            'default_branch' => $repo->trackedBranch(),
            'read_docs' => $repo->read_docs,
            'receive_events' => $repo->receive_events,
            'linked_at' => ($repo->linked_at ?? $repo->created_at ?? now())->toIso8601String(),
            'linked_by' => (string) ($repo->linkedBy?->name ?? 'Gestor TI'),
        ];
    }

    /**
     * `CiRun` of a branch from `github_repositories.ci`; `status: 'none'`
     * when no workflow run arrived for it.
     *
     * @return array<string, mixed>
     */
    public static function ci(GitHubRepository $repo, string $branch): array
    {
        $run = ($repo->ci ?? [])[$branch] ?? null;

        if (! is_array($run)) {
            return ['status' => 'none', 'branch' => $branch, 'commit_sha' => null, 'run_at' => null, 'summary' => null, 'url' => null];
        }

        return [
            'status' => in_array($run['status'] ?? null, ['passing', 'failing', 'running', 'none'], true) ? $run['status'] : 'none',
            'branch' => $branch,
            'commit_sha' => isset($run['commit_sha']) ? (string) $run['commit_sha'] : null,
            'run_at' => isset($run['run_at']) ? Carbon::parse((string) $run['run_at'])->setTimezone(config('app.timezone'))->toIso8601String() : null,
            'summary' => isset($run['summary']) ? (string) $run['summary'] : null,
            'url' => isset($run['url']) ? (string) $run['url'] : null,
        ];
    }
}
