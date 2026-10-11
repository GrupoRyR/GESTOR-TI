<?php

namespace App\Domain\GitHub;

use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\GitHubRepository;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * `RepoState` of `Ai/Docs/Repo` (types/pages/ai-knowledge.ts): read-only
 * Markdown of the product's linked repository, read with the GitHub App
 * from the tracked branch: the Markdown files at the root (README.md,
 * CLAUDE.md, CHANGELOG.md…) and everything under docs/.
 *
 * `{ connected: false }` when the product has no repository with
 * `read_docs`, GitHub is not configured/connected or a call fails (never
 * a 500). The file list and each file are cached briefly.
 */
final class RepoDocs
{
    public const CACHE_SECONDS = 600;

    /** Files that get their last-commit date (one GitHub call each). */
    private const DATED_FILES = 40;

    private const MARKDOWN = '/\.(md|markdown)$/i';

    public function __construct(
        private readonly GitHubApp $github,
        private readonly Cache $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function state(?AiProduct $product, ?string $path): array
    {
        $repo = $product?->githubRepository()->first();

        if ($repo === null || ! $repo->read_docs || ! $this->github->isConfigured() || $this->github->installationId() === null) {
            return ['connected' => false];
        }

        try {
            $listing = $this->listing($repo);
            $files = $listing['files'];
            $paths = array_column(array_filter($files, fn (array $file) => $file['kind'] === 'file'), 'path');
            $chosen = is_string($path) && in_array($path, $paths, true)
                ? $path
                : (in_array('README.md', $paths, true) ? 'README.md' : ($paths[0] ?? null));

            return [
                'connected' => true,
                'name' => $repo->full_name,
                'branch' => $repo->trackedBranch(),
                'fetched_at' => $listing['fetched_at'],
                'files' => $files,
                'selected' => $chosen === null ? null : $this->selected($repo, $product, $chosen, $files),
            ];
        } catch (GitHubException $exception) {
            Log::warning('GitHub docs could not be read', ['reason' => $exception->reason, 'repository' => $repo->full_name]);

            return ['connected' => false];
        }
    }

    /**
     * `RepoFile[]` (folders before their files) and when they were read.
     *
     * @return array{files: list<array<string, mixed>>, fetched_at: string}
     */
    private function listing(GitHubRepository $repo): array
    {
        $branch = $repo->trackedBranch();

        return $this->cache->remember($this->key($repo, 'tree'), self::CACHE_SECONDS, function () use ($repo, $branch): array {
            $paths = [];

            foreach ($this->github->tree($repo->full_name, $branch) as $item) {
                $path = $item['path'];
                $inDocs = Str::startsWith($path, 'docs/');

                if ($item['type'] === 'blob' && preg_match(self::MARKDOWN, $path) === 1 && ($inDocs || ! str_contains($path, '/'))) {
                    $paths[] = $path;
                }
            }

            $files = [];
            $folders = [];
            $dated = 0;

            foreach ($this->ordered($paths) as $path) {
                $segments = explode('/', $path);

                for ($depth = 0; $depth < count($segments) - 1; $depth++) {
                    $folder = implode('/', array_slice($segments, 0, $depth + 1)).'/';

                    if (! isset($folders[$folder])) {
                        $folders[$folder] = true;
                        $files[] = ['path' => $folder, 'kind' => 'dir', 'depth' => $depth, 'updated_at' => null];
                    }
                }

                $date = $dated++ < self::DATED_FILES ? $this->github->lastCommitAt($repo->full_name, $path, $branch) : null;
                $files[] = ['path' => $path, 'kind' => 'file', 'depth' => count($segments) - 1, 'updated_at' => $date?->toDateString()];
            }

            return ['files' => $files, 'fetched_at' => now()->toIso8601String()];
        });
    }

    /**
     * Root files first (README.md, CLAUDE.md, then by name), then docs/.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function ordered(array $paths): array
    {
        $rank = fn (string $path) => match (true) {
            $path === 'README.md' => 0,
            $path === 'CLAUDE.md' => 1,
            ! str_contains($path, '/') => 2,
            default => 3,
        };

        usort($paths, fn (string $a, string $b) => [$rank($a), $a] <=> [$rank($b), $b]);

        return $paths;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return array<string, mixed>
     */
    private function selected(GitHubRepository $repo, AiProduct $product, string $path, array $files): array
    {
        $branch = $repo->trackedBranch();
        $markdown = $this->cache->remember($this->key($repo, 'file:'.$path), self::CACHE_SECONDS, fn () => (string) $this->github->fileContents($repo->full_name, $path, $branch));
        $updated = collect($files)->firstWhere('path', $path)['updated_at'] ?? null;

        return [
            'path' => $path,
            'markdown' => $markdown,
            'updated_at' => $updated ?? now()->toDateString(),
            'release' => $updated === null ? null : $this->release($product, $repo, $updated),
            'github_url' => rtrim($repo->html_url, '/').'/blob/'.rawurlencode($branch).'/'.implode('/', array_map('rawurlencode', explode('/', $path))),
        ];
    }

    /**
     * "versión 3.1.0": the first GitHub release published on or after the
     * file's last change; null when none came after it.
     */
    private function release(AiProduct $product, GitHubRepository $repo, string $updated): ?string
    {
        /** @var AiProductVersion|null $version */
        $version = $product->versions()
            ->where('github_repository_id', $repo->id)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', Carbon::parse($updated, 'America/Bogota')->startOfDay()->setTimezone(config('app.timezone')))
            ->orderBy('published_at')
            ->first();

        return $version === null ? null : 'versión '.ltrim($version->version, 'v');
    }

    private function key(GitHubRepository $repo, string $suffix): string
    {
        return "github.docs.{$repo->id}.".sha1($repo->trackedBranch().'|'.$suffix);
    }
}
