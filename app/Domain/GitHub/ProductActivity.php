<?php

namespace App\Domain\GitHub;

use App\Domain\Ai\BusinessDays;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\GitHubRepository;
use App\Support\Ai\Presenters\GitHubPresenter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * `ProductGitHub` (types/pages/ai-github.ts), the GitHub section of
 * `Ai/Products/Show`: built from the linked repository row (CI per branch,
 * last deploy, recent commits) and its stored webhook events (pull
 * requests, issues) plus the versions GitHub releases created. Null while
 * no repository is linked to the product.
 */
final class ProductActivity
{
    /** Commits listed, of the last 7 days. */
    private const COMMITS_SHOWN = 5;

    private const ISSUES_SHOWN = 3;

    private const PERIOD_DAYS = 7;

    private const DAYS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * @return array<string, mixed>|null
     */
    public function for(AiProduct $product): ?array
    {
        /** @var GitHubRepository|null $repo */
        $repo = $product->githubRepository()->with('linkedBy')->first();

        if ($repo === null) {
            return null;
        }

        $activity = new RepoActivity($repo);
        $pullRequests = $activity->pullRequests();
        $branch = $repo->trackedBranch();
        [$commits, $period] = $this->commits($repo, $activity);

        $open = array_values(array_filter($pullRequests, fn (array $pr) => ($pr['state'] ?? 'open') === 'open' && ! isset($pr['merged_at'])));
        usort($open, fn (array $a, array $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $closed = array_values(array_filter($pullRequests, fn (array $pr) => ($pr['state'] ?? null) === 'closed' && ! ($pr['merged'] ?? false) && ! isset($pr['merged_at'])));
        usort($closed, fn (array $a, array $b) => strcmp((string) ($b['closed_at'] ?? ''), (string) ($a['closed_at'] ?? '')));

        $issues = array_values($activity->issues());
        usort($issues, fn (array $a, array $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        $project = AiProject::query()
            ->where('product_id', $product->id)
            ->where('repository_url', $repo->html_url)
            ->latest('id')
            ->first();

        return [
            'link' => GitHubPresenter::link($repo),
            'project' => $project === null ? null : ['key' => $project->key, 'name' => $project->name],
            'fetched_at' => ($repo->updated_at ?? now())->toIso8601String(),
            'ci_main' => GitHubPresenter::ci($repo, $branch),
            'deploy' => $this->deploy($repo, $product),
            'commits' => array_slice($commits, 0, self::COMMITS_SHOWN),
            'commits_total' => count($commits),
            'commits_period' => $period,
            'commits_authors' => count(array_unique(array_map(fn (array $commit) => Str::lower((string) ($commit['author']['login'] ?? $commit['author']['name'])), $commits))),
            'open_pull_requests' => array_map(fn (array $pr) => $activity->pullRequest($pr), $open),
            'last_closed_pull_request' => $closed === [] ? null : $activity->pullRequest($closed[0]),
            'issues' => array_map(fn (array $issue) => $activity->issue($issue), array_slice($issues, 0, self::ISSUES_SHOWN)),
            'releases' => $this->releases($product),
            // Planned versions are not stored yet (no "próxima versión" field).
            'planned_release' => null,
        ];
    }

    /**
     * `Deploy` from the last push to the tracked branch; the provider is
     * the product hosting and the URL its public address.
     *
     * @return array<string, mixed>|null
     */
    private function deploy(GitHubRepository $repo, AiProduct $product): ?array
    {
        $deploy = $repo->last_deploy;

        if (! is_array($deploy) || ! isset($deploy['deployed_at'])) {
            return null;
        }

        return [
            'provider' => filled($product->hosting) ? (string) $product->hosting : 'GitHub',
            'branch' => (string) ($deploy['branch'] ?? $repo->trackedBranch()),
            'commit_sha' => (string) ($deploy['commit_sha'] ?? ''),
            'deployed_at' => RepoActivity::iso((string) $deploy['deployed_at']),
            'trigger' => 'push',
            'url' => filled($product->url) ? (string) $product->url : null,
        ];
    }

    /**
     * `CommitSummary[]` of the last 7 days (Bogotá), newest first, and the
     * period label "jue 1 a mié 7 oct".
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function commits(GitHubRepository $repo, RepoActivity $activity): array
    {
        $today = now(BusinessDays::TIMEZONE)->startOfDay();
        $start = $today->copy()->subDays(self::PERIOD_DAYS - 1);
        $commits = [];

        foreach ($repo->recent_commits ?? [] as $commit) {
            if (! is_array($commit) || ! isset($commit['committed_at'])) {
                continue;
            }

            $at = Carbon::parse((string) $commit['committed_at'])->setTimezone(config('app.timezone'));

            if ($at->lt($start)) {
                continue;
            }

            $commits[] = [
                'sha' => (string) ($commit['sha'] ?? ''),
                'message' => (string) ($commit['message'] ?? ''),
                'author' => $activity->actor($commit['author_login'] ?? null, isset($commit['author_name']) ? (string) $commit['author_name'] : null),
                'committed_at' => $at->toIso8601String(),
                'url' => (string) ($commit['url'] ?? $repo->html_url),
            ];
        }

        usort($commits, fn (array $a, array $b) => strcmp($b['committed_at'], $a['committed_at']));

        return [$commits, self::period($start, $today)];
    }

    public static function period(CarbonInterface $start, CarbonInterface $end): string
    {
        $day = fn (CarbonInterface $date) => self::DAYS[$date->dayOfWeek].' '.$date->day;
        $month = fn (CarbonInterface $date) => self::MONTHS[$date->month - 1];

        return $start->month === $end->month
            ? $day($start).' a '.$day($end).' '.$month($end)
            : $day($start).' '.$month($start).' a '.$day($end).' '.$month($end);
    }

    /**
     * `ReleaseSummary[]`: versions created from GitHub releases, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function releases(AiProduct $product): array
    {
        return $product->versions()
            ->whereNotNull('tag')
            ->with('requests')
            ->get()
            ->sortByDesc(fn (AiProductVersion $version) => ($version->published_at ?? $version->released_at)?->getTimestamp())
            ->map(fn (AiProductVersion $version) => [
                'tag' => (string) $version->tag,
                'summary' => (string) $version->summary,
                'published_at' => ($version->published_at ?? $version->released_at ?? $version->created_at ?? now())->toIso8601String(),
                'request_keys' => $version->requests->sortBy('id')->map(fn (AiRequest $request) => $request->key)->values()->all(),
                'notified_count' => $version->notified_at === null ? 0 : $version->requests->count(),
                'url' => (string) $version->release_url,
            ])
            ->values()
            ->all();
    }
}
