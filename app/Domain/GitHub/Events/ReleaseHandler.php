<?php

namespace App\Domain\GitHub\Events;

use App\Domain\GitHub\GiaKeys;
use App\Enums\Ai\ProductVersionSource;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\GitHubRepository;
use App\Models\GitHubWebhookEvent;
use Illuminate\Support\Carbon;

/**
 * `release` published (PROMPT 6.4.6, §7.2): creates (or, on a redelivery,
 * updates) the product version of the linked product (a project link uses
 * the project's product) with the tag, the release body as technical
 * notes, author and URL, and makes it the product's current version when
 * it is the newest release.
 *
 * Included requests: the `GIA-…` keys of the pull requests merged into the
 * repository since the previous release of the same repository, read from
 * the stored `pull_request` events (no GitHub call, so it also works when
 * the API is down), plus the keys written in the release name or body.
 *
 * Requesters are NOT notified here: IT confirms the user notes first and
 * notifies from the release page (Stage D4). Drafts and prereleases are
 * ignored.
 */
class ReleaseHandler implements EventHandler
{
    public function needsLinkedRepository(): bool
    {
        return true;
    }

    public function handle(string $action, array $payload, ?GitHubRepository $repository): Outcome
    {
        /** @var GitHubRepository $repository */
        $release = is_array($payload['release'] ?? null) ? $payload['release'] : [];

        if ($action !== 'published' || (bool) ($release['draft'] ?? false) || (bool) ($release['prerelease'] ?? false)) {
            return Outcome::ignored();
        }

        $product = $this->product($repository);
        $tag = mb_substr((string) ($release['tag_name'] ?? ''), 0, 100);
        $name = trim((string) ($release['name'] ?? ''));
        $body = isset($release['body']) ? (string) $release['body'] : null;
        $publishedAt = (isset($release['published_at']) ? Carbon::parse((string) $release['published_at']) : now())
            ->setTimezone(config('app.timezone'));
        $releaseId = isset($release['id']) ? (int) $release['id'] : null;
        $versionName = mb_substr((string) preg_replace('/^v(?=\d)/i', '', $tag), 0, 40);

        $version = ($releaseId === null ? null : AiProductVersion::query()->where('github_release_id', $releaseId)->first())
            ?? $product->versions()->where('version', $versionName)->first()
            ?? new AiProductVersion;

        $version->forceFill([
            'ai_product_id' => $product->id,
            'version' => $versionName,
            'tag' => $tag,
            'released_at' => $publishedAt->toDateString(),
            'summary' => mb_substr($name !== '' ? $name : "Versión {$tag}", 0, 255),
            'source' => ProductVersionSource::GithubRelease,
            'release_url' => isset($release['html_url']) ? (string) $release['html_url'] : null,
            'github_release_id' => $releaseId,
            'github_repository_id' => $repository->id,
            'published_at' => $publishedAt,
            'author_login' => isset($release['author']['login']) ? (string) $release['author']['login'] : null,
            'technical_notes' => $body,
        ])->save();

        $found = $this->mergedPullRequestKeys($repository, $this->previousPublishedAt($repository, $version), $publishedAt);

        foreach (GiaKeys::extract($name, $body) as $key) {
            $found[$key] ??= null;
        }

        $this->attachRequests($version, $found);
        $this->makeCurrent($product, $version);

        return Outcome::processed(array_keys($found));
    }

    private function product(GitHubRepository $repository): AiProduct
    {
        $productId = match ($repository->linkable_type) {
            AiProduct::class => $repository->linkable_id,
            AiProject::class => AiProject::query()->withoutGlobalScopes()->whereKey($repository->linkable_id)->value('product_id'),
            default => null,
        };

        $product = $productId === null ? null : AiProduct::query()->withoutGlobalScopes()->find($productId);

        return $product ?? throw EventFailure::noProduct();
    }

    private function previousPublishedAt(GitHubRepository $repository, AiProductVersion $version): ?Carbon
    {
        return AiProductVersion::query()
            ->where('github_repository_id', $repository->id)
            ->whereKeyNot($version->id)
            ->where('published_at', '<', $version->published_at)
            ->orderByDesc('published_at')
            ->first()?->published_at;
    }

    /**
     * Keys of the PRs merged after `$from` and up to `$to`, oldest merge
     * first, with the PR where each key was first found.
     *
     * @return array<string, ?int>
     */
    private function mergedPullRequestKeys(GitHubRepository $repository, ?Carbon $from, Carbon $to): array
    {
        $merged = [];

        $events = GitHubWebhookEvent::query()
            ->where('event', 'pull_request')
            ->where('action', 'closed')
            ->where('repository', $repository->full_name)
            ->orderBy('id')
            ->get(['id', 'payload']);

        foreach ($events as $event) {
            $pr = $event->payload['pull_request'] ?? null;

            if (! is_array($pr) || ($pr['merged'] ?? false) !== true || ! isset($pr['merged_at'])) {
                continue;
            }

            $mergedAt = Carbon::parse((string) $pr['merged_at']);

            if ($mergedAt->greaterThan($to) || ($from !== null && $mergedAt->lessThanOrEqualTo($from))) {
                continue;
            }

            $merged[] = [$mergedAt, (int) ($pr['number'] ?? 0), GiaKeys::extract(
                (string) ($pr['title'] ?? ''),
                isset($pr['body']) ? (string) $pr['body'] : null,
                isset($pr['head']['ref']) ? (string) $pr['head']['ref'] : null,
            )];
        }

        usort($merged, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $found = [];

        foreach ($merged as [, $number, $keys]) {
            foreach ($keys as $key) {
                $found[$key] ??= $number > 0 ? $number : null;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, ?int>  $found
     */
    private function attachRequests(AiProductVersion $version, array $found): void
    {
        if ($found === []) {
            return;
        }

        $requests = AiRequest::query()->withoutGlobalScopes()
            ->whereIn('key', array_keys($found))
            ->whereNull('deleted_at')
            ->get(['id', 'key']);

        $version->requests()->syncWithoutDetaching(
            $requests->mapWithKeys(fn (AiRequest $request) => [$request->id => ['found_in_pr' => $found[$request->key] ?? null]])->all(),
        );
    }

    private function makeCurrent(AiProduct $product, AiProductVersion $version): void
    {
        $newer = $product->versions()
            ->whereKeyNot($version->id)
            ->where('published_at', '>', $version->published_at)
            ->exists();

        if (! $newer && $product->current_version !== $version->version) {
            $product->forceFill(['current_version' => $version->version])->save();
        }
    }
}
