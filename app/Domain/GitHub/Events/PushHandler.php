<?php

namespace App\Domain\GitHub\Events;

use App\Domain\GitHub\GiaKeys;
use App\Models\GitHubRepository;
use Illuminate\Support\Carbon;

/**
 * `push` to the linked branch (the main branch unless the link says
 * otherwise): the last deploy (`github_repositories.last_deploy`, design
 * `Deploy` without the provider, which comes from the product hosting) and
 * the newest commits (`recent_commits`, design `CommitSummary`, at most
 * 20). Pushes to other branches and branch deletions are ignored.
 */
class PushHandler implements EventHandler
{
    private const MAX_COMMITS = 20;

    public function needsLinkedRepository(): bool
    {
        return true;
    }

    public function handle(string $action, array $payload, ?GitHubRepository $repository): Outcome
    {
        /** @var GitHubRepository $repository */
        $branch = $repository->trackedBranch();

        if (($payload['ref'] ?? null) !== 'refs/heads/'.$branch || (bool) ($payload['deleted'] ?? false)) {
            return Outcome::ignored();
        }

        $commits = [];
        $keys = [];

        foreach (array_reverse((array) ($payload['commits'] ?? [])) as $commit) {
            if (! is_array($commit) || ! isset($commit['id'])) {
                continue;
            }

            $message = (string) ($commit['message'] ?? '');
            $keys = [...$keys, ...GiaKeys::extract($message)];
            $commits[] = self::commit($commit);
        }

        $head = is_array($payload['head_commit'] ?? null) ? $payload['head_commit'] : null;
        $sha = (string) ($head['id'] ?? $payload['after'] ?? '');
        $deployedAt = isset($head['timestamp']) ? Carbon::parse((string) $head['timestamp']) : now();

        $known = array_column($commits, 'sha');
        $older = array_filter($repository->recent_commits ?? [], fn ($commit) => ! in_array($commit['sha'] ?? null, $known, true));

        $repository->forceFill([
            'last_deploy' => [
                'branch' => $branch,
                'commit_sha' => substr($sha, 0, 7),
                'deployed_at' => $deployedAt->toIso8601String(),
                'url' => isset($head['url']) ? (string) $head['url'] : (isset($payload['compare']) ? (string) $payload['compare'] : null),
            ],
            'recent_commits' => array_slice([...$commits, ...array_values($older)], 0, self::MAX_COMMITS),
            // Eloquent stores the wall time as given: convert to the app zone first.
            'pushed_at' => $deployedAt->copy()->setTimezone(config('app.timezone')),
        ])->save();

        return Outcome::processed($keys);
    }

    /**
     * @param  array<string, mixed>  $commit
     * @return array<string, mixed>
     */
    private static function commit(array $commit): array
    {
        $author = is_array($commit['author'] ?? null) ? $commit['author'] : [];

        return [
            'sha' => substr((string) $commit['id'], 0, 7),
            'message' => mb_substr(strtok((string) ($commit['message'] ?? ''), "\n") ?: '', 0, 200),
            'author_name' => isset($author['name']) ? (string) $author['name'] : null,
            'author_login' => isset($author['username']) ? (string) $author['username'] : null,
            'committed_at' => isset($commit['timestamp']) ? Carbon::parse((string) $commit['timestamp'])->toIso8601String() : null,
            'url' => isset($commit['url']) ? (string) $commit['url'] : null,
        ];
    }
}
