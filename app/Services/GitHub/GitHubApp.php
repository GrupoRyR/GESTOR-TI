<?php

namespace App\Services\GitHub;

use App\Support\Settings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * GitHub App client on Laravel's HTTP client (fakeable with `Http::fake()`).
 *
 * - App calls authenticate with a JWT (RS256, signed with `openssl_sign`
 *   using the private key at `services.github.private_key_path`).
 * - Repository calls use the installation access token of the organization
 *   installation (`github.installation_id` setting), cached encrypted for
 *   less than an hour (GitHub tokens live one hour).
 *
 * Without `GITHUB_APP_ID` and a readable key every call throws
 * `GitHubException::notConfigured()`; without an installation,
 * `notConnected()`. The key, the JWT and tokens are never logged, flashed
 * or put in exception messages.
 */
class GitHubApp
{
    public const API_VERSION = '2022-11-28';

    private const TIMEOUT_SECONDS = 15;

    /** Tokens are dropped from the cache this long before GitHub expires them. */
    private const TOKEN_MARGIN_SECONDS = 300;

    private const TOKEN_MAX_TTL_SECONDS = 3300;

    private const PER_PAGE = 100;

    public function __construct(
        private readonly Settings $settings,
        private readonly Cache $cache,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('services.github.app_id')) && $this->privateKeyConfigured();
    }

    /**
     * The key file is set and readable (its content is never exposed).
     */
    public function privateKeyConfigured(): bool
    {
        $path = config('services.github.private_key_path');

        return is_string($path) && $path !== '' && is_file($path) && is_readable($path);
    }

    /**
     * Installation of the app on the organization; null until connected.
     */
    public function installationId(): ?int
    {
        $id = $this->settings->get('github.installation_id');

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * App JWT: issued 60 s in the past (clock drift), valid 9 minutes.
     */
    public function jwt(): string
    {
        if (! $this->isConfigured()) {
            throw GitHubException::notConfigured();
        }

        $key = openssl_pkey_get_private((string) file_get_contents((string) config('services.github.private_key_path')));
        if ($key === false) {
            throw GitHubException::notConfigured();
        }

        $now = now()->getTimestamp();
        $segments = [
            self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => (string) config('services.github.app_id')])),
        ];

        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw GitHubException::notConfigured();
        }

        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    /**
     * Installation access token, cached (encrypted) until shortly before it expires.
     */
    public function installationToken(?int $installationId = null): string
    {
        if (! $this->isConfigured()) {
            throw GitHubException::notConfigured();
        }

        $installationId ??= $this->installationId() ?? throw GitHubException::notConnected();
        $cacheKey = self::tokenCacheKey($installationId);

        $cached = $this->cache->get($cacheKey);
        if (is_string($cached)) {
            return Crypt::decryptString($cached);
        }

        $response = $this->send('post', "app/installations/{$installationId}/access_tokens", [], $this->jwt());
        $token = (string) $response->json('token');
        $expiresAt = $response->json('expires_at');

        $ttl = is_string($expiresAt)
            ? (int) now()->diffInSeconds(Carbon::parse($expiresAt), false) - self::TOKEN_MARGIN_SECONDS
            : self::TOKEN_MAX_TTL_SECONDS;
        $this->cache->put($cacheKey, Crypt::encryptString($token), max(60, min($ttl, self::TOKEN_MAX_TTL_SECONDS)));

        return $token;
    }

    public function forgetInstallationToken(int $installationId): void
    {
        $this->cache->forget(self::tokenCacheKey($installationId));
    }

    /**
     * Installation id of the app on an organization; null when not installed.
     */
    public function organizationInstallationId(string $organization): ?int
    {
        $response = $this->send('get', 'orgs/'.rawurlencode($organization).'/installation', [], $this->jwt(), allowNotFound: true);

        return $response === null ? null : (int) $response->json('id');
    }

    /**
     * Every repository the installation can access (all pages).
     *
     * @return list<array<string, mixed>>
     */
    public function installationRepositories(): array
    {
        $repositories = [];
        $page = 1;

        do {
            $batch = (array) $this->send('get', 'installation/repositories', ['per_page' => self::PER_PAGE, 'page' => $page], $this->installationToken())
                ->json('repositories', []);
            array_push($repositories, ...array_values($batch));
            $page++;
        } while (count($batch) === self::PER_PAGE);

        return $repositories;
    }

    /**
     * Branch names (first 100).
     *
     * @return list<string>
     */
    public function branches(string $fullName): array
    {
        $branches = (array) $this->send('get', 'repos/'.$this->repoPath($fullName).'/branches', ['per_page' => self::PER_PAGE], $this->installationToken())->json();

        return array_values(array_map(fn (array $branch): string => (string) $branch['name'], $branches));
    }

    /**
     * Raw contents API answer for a file (object) or directory (list); null when missing.
     *
     * @return ?array<array-key, mixed>
     */
    public function contents(string $fullName, string $path, ?string $ref = null): ?array
    {
        $query = $ref === null ? [] : ['ref' => $ref];
        $response = $this->send('get', 'repos/'.$this->repoPath($fullName).'/contents/'.self::encodePath($path), $query, $this->installationToken(), allowNotFound: true);

        return $response === null ? null : (array) $response->json();
    }

    /**
     * Every path of a branch (Git trees API, recursive): `path` and `type`
     * (`blob` file | `tree` folder). Empty when the branch is missing.
     *
     * @return list<array{path: string, type: string}>
     */
    public function tree(string $fullName, string $ref): array
    {
        $response = $this->send('get', 'repos/'.$this->repoPath($fullName).'/git/trees/'.rawurlencode($ref), ['recursive' => 1], $this->installationToken(), allowNotFound: true);

        if ($response === null) {
            return [];
        }

        return array_values(array_map(
            fn (array $item): array => ['path' => (string) ($item['path'] ?? ''), 'type' => (string) ($item['type'] ?? '')],
            array_filter((array) $response->json('tree', []), 'is_array'),
        ));
    }

    /**
     * When the last commit of the branch touching a path was made; null when none.
     */
    public function lastCommitAt(string $fullName, string $path, string $ref): ?Carbon
    {
        $commits = (array) $this->send('get', 'repos/'.$this->repoPath($fullName).'/commits', ['sha' => $ref, 'path' => $path, 'per_page' => 1], $this->installationToken())->json();
        $date = $commits[0]['commit']['committer']['date'] ?? $commits[0]['commit']['author']['date'] ?? null;

        return is_string($date) ? Carbon::parse($date) : null;
    }

    /**
     * Decoded text of a file; null when missing or a directory.
     */
    public function fileContents(string $fullName, string $path, ?string $ref = null): ?string
    {
        $contents = $this->contents($fullName, $path, $ref);

        if ($contents === null || ($contents['type'] ?? null) !== 'file' || ! isset($contents['content'])) {
            return null;
        }

        $decoded = base64_decode(str_replace(["\n", "\r"], '', (string) $contents['content']), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @param  list<string>  $labels
     * @param  list<string>  $assignees  GitHub logins
     * @return array<string, mixed> the created issue (`number`, `html_url`, …)
     */
    public function createIssue(string $fullName, string $title, string $body, array $labels = [], array $assignees = []): array
    {
        return (array) $this->send('post', 'repos/'.$this->repoPath($fullName).'/issues', [
            'title' => $title,
            'body' => $body,
            'labels' => array_values($labels),
            'assignees' => array_values($assignees),
        ], $this->installationToken())->json();
    }

    /**
     * Creates a private repository in the organization and commits each
     * file (path => content) to its default branch.
     *
     * @param  array<string, string>  $files
     * @return array<string, mixed> the created repository
     */
    public function createRepository(string $organization, string $name, array $files, string $description = '', string $message = 'Estructura inicial creada desde el Gestor TI'): array
    {
        $token = $this->installationToken();

        $repository = (array) $this->send('post', 'orgs/'.rawurlencode($organization).'/repos', [
            'name' => $name,
            'description' => $description,
            'private' => true,
            'auto_init' => false,
        ], $token)->json();

        $fullName = (string) ($repository['full_name'] ?? $organization.'/'.$name);

        foreach ($files as $path => $content) {
            $this->send('put', 'repos/'.$this->repoPath($fullName).'/contents/'.self::encodePath($path), [
                'message' => $message,
                'content' => base64_encode($content),
            ], $token);
        }

        return $repository;
    }

    /**
     * @param  array<string, mixed>  $data  query (GET) or JSON body
     */
    private function send(string $method, string $endpoint, array $data, string $bearer, bool $allowNotFound = false): ?Response
    {
        try {
            $response = $this->http($bearer)->{$method}($endpoint, $data);
        } catch (ConnectionException) {
            throw GitHubException::connectionFailed($endpoint);
        }

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw GitHubException::requestFailed($response->status(), $endpoint);
        }

        return $response;
    }

    private function http(string $bearer): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.github.api_url', 'https://api.github.com'), '/'))
            ->withToken($bearer)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => self::API_VERSION])
            ->timeout(self::TIMEOUT_SECONDS);
    }

    private function repoPath(string $fullName): string
    {
        [$owner, $name] = array_pad(explode('/', $fullName, 2), 2, '');

        return rawurlencode($owner).'/'.rawurlencode($name);
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

    private static function tokenCacheKey(int $installationId): string
    {
        return "github.installation_token.{$installationId}";
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
