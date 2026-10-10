<?php

namespace App\Domain\GitHub;

use App\Enums\Ai\ProductVersionSource;
use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `Release` (resources/js/types/github.ts) of `Ai/Releases/Show`, built
 * from the product version a GitHub release created (ReleaseHandler):
 *
 * - `included`: the requests of `ai_product_version_request` (every
 *   company of the product), oldest first, with their current status, the
 *   requester's name, company short name and the request area, and the PR
 *   where the key was found;
 * - `author`: the Gestor person whose `github_login` published it, else
 *   the login;
 * - `compare`: previous release of the same repository ("v1.8.1...v1.8.2"),
 *   null for the first one;
 * - `notified_count`: distinct requesters notified (0 until notified).
 *
 * Never carries costs or internal notes; `technical_notes` is the release
 * body exactly as GitHub sent it (only `ai.manage` reaches this page).
 */
final class ReleaseDetail
{
    /**
     * @return array<string, mixed>
     */
    public function for(AiProductVersion $version): array
    {
        $version->loadMissing(['githubRepository', 'requests.requester', 'requests.area', 'requests.company']);
        $requests = $version->requests->sortBy('id')->values();
        $repo = $version->githubRepository;

        return [
            'tag' => (string) $version->tag,
            'summary' => (string) $version->summary,
            'published_at' => ($version->published_at ?? $version->released_at ?? $version->created_at ?? now())->toIso8601String(),
            'request_keys' => $requests->map(fn (AiRequest $request) => $request->key)->all(),
            'notified_count' => self::notifiedCount($version),
            'url' => (string) $version->release_url,
            'repo' => (string) ($repo?->full_name ?? Str::between((string) $version->release_url, 'github.com/', '/releases')),
            'author' => $this->author($version->author_login),
            'included' => $requests->map(fn (AiRequest $request) => [
                'key' => $request->key,
                'title' => $request->title,
                'status' => $request->status->value,
                'requester' => [
                    'name' => (string) $request->requester?->name,
                    'company' => (string) ($request->company?->short_name ?: $request->company?->name),
                    'area' => (string) $request->area?->name,
                ],
                'found_in_pr' => $request->pivot?->getAttribute('found_in_pr') === null ? null : (int) $request->pivot->getAttribute('found_in_pr'),
            ])->all(),
            'technical_notes' => (string) $version->technical_notes,
            'user_notes' => $version->user_notes,
            'version_created' => $version->source === ProductVersionSource::GithubRelease,
            'notified_at' => $version->notified_at?->toIso8601String(),
            'compare' => $this->compare($version),
        ];
    }

    /**
     * Requesters notified for this version (`ReleaseSummary.notified_count`).
     */
    public static function notifiedCount(AiProductVersion $version): int
    {
        if ($version->notified_at === null) {
            return 0;
        }

        return $version->requests->pluck('requester_id')->filter()->unique()->count();
    }

    /**
     * @return array{name: string, login: ?string}
     */
    private function author(?string $login): array
    {
        $login = $login === null || $login === '' ? null : $login;
        $user = $login === null ? null : User::query()->where(DB::raw('lower(github_login)'), Str::lower($login))->first();

        return [
            'name' => $user?->name ?? $login ?? 'GitHub',
            'login' => $login === null ? null : '@'.$login,
        ];
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private function compare(AiProductVersion $version): ?array
    {
        $repo = $version->githubRepository;

        if ($repo === null || $version->tag === null || $version->published_at === null) {
            return null;
        }

        $previous = AiProductVersion::query()
            ->where('github_repository_id', $repo->id)
            ->whereKeyNot($version->id)
            ->whereNotNull('tag')
            ->where('published_at', '<', $version->published_at)
            ->orderByDesc('published_at')
            ->value('tag');

        if ($previous === null) {
            return null;
        }

        $label = "{$previous}...{$version->tag}";

        return ['label' => $label, 'url' => rtrim((string) $repo->html_url, '/').'/compare/'.$label];
    }
}
