<?php

namespace App\Support\Integrations;

use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\GitHubRepository;
use App\Models\GitHubWebhookEvent;
use App\Services\GitHub\GitHubApp;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GitHub state for Administración › Integraciones: the Index card
 * (`IntegrationsProps.github`) and the `Admin/Integrations/GitHub` page
 * (`AdminIntegrationsGitHubProps`). Built only from settings, config flags
 * and stored rows: rendering never calls GitHub, and the private key, the
 * webhook secret and tokens only leave the server as booleans.
 *
 * Connected = the app is configured and installed on the organization
 * (`github.installation_id`, stored by App\Services\GitHub\RepositorySync).
 */
class GitHubIntegration
{
    public const ORGANIZATION_PLACEHOLDER = '[ORG]';

    /**
     * Permissions the GitHub App needs (shown on the page). `contents` and
     * `administration` write: "Crear repositorio" commits README, CLAUDE.md and docs/.
     */
    public const PERMISSIONS = [
        ['scope' => 'issues', 'access' => 'write'],
        ['scope' => 'pull_requests', 'access' => 'read'],
        ['scope' => 'contents', 'access' => 'write'],
        ['scope' => 'releases', 'access' => 'read'],
        ['scope' => 'actions', 'access' => 'read'],
        ['scope' => 'administration', 'access' => 'write'],
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly GitHubApp $github,
    ) {}

    public function connected(): bool
    {
        return $this->github->isConfigured() && $this->github->installationId() !== null;
    }

    public function organization(): ?string
    {
        $organization = $this->settings->get('github.organization');

        return is_string($organization) && $organization !== '' ? $organization : null;
    }

    /**
     * External install page of the app; only once the organization is saved.
     */
    public function installUrl(): ?string
    {
        $url = config('services.github.install_url');

        return $this->organization() !== null && filled($url) ? (string) $url : null;
    }

    public function repositoriesCount(): int
    {
        return $this->connected() ? GitHubRepository::query()->count() : 0;
    }

    /**
     * `IntegrationsProps.github` (Integraciones card).
     *
     * @return array<string, mixed>
     */
    public function toCardProps(): array
    {
        return [
            'status' => $this->connected() ? 'connected' : 'disconnected',
            'organization' => $this->organization(),
            'install_url' => $this->installUrl(),
            'repositories_count' => $this->repositoriesCount(),
        ];
    }

    /**
     * `AdminIntegrationsGitHubProps`.
     *
     * @return array<string, mixed>
     */
    public function toPageProps(): array
    {
        return [
            'connection' => $this->connection(),
            'install_url' => $this->installUrl(),
            'repos' => $this->connected() ? $this->repos() : [],
            'stats' => $this->stats(),
            'failed_events' => $this->failedEvents(),
            'now' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(): array
    {
        $organization = $this->organization() ?? self::ORGANIZATION_PLACEHOLDER;
        $lastDelivery = GitHubWebhookEvent::query()->max('received_at');

        return [
            'connected' => $this->connected(),
            'kind' => filled(config('services.github.token')) && blank(config('services.github.app_id')) ? 'token' : 'app',
            'app_name' => (string) config('services.github.app_name', 'Gestor TI RYR'),
            'organization' => $organization,
            'installed_repos_count' => $this->repositoriesCount(),
            'permissions' => self::PERMISSIONS,
            'private_key_configured' => $this->github->privateKeyConfigured(),
            'manage_url' => "https://github.com/organizations/{$organization}/settings/installations",
            'webhook' => [
                // Public address (APP_URL), whatever host served this page.
                'url' => rtrim((string) config('app.url'), '/').'/api/github/webhook',
                'configured' => filled(config('services.github.webhook_secret')),
                'events' => GitHubWebhookEvent::SUBSCRIBED_EVENTS,
                'last_delivery_at' => $lastDelivery === null ? null : Carbon::parse($lastDelivery)->toIso8601String(),
            ],
        ];
    }

    /**
     * `GitHubRepo[]` by full name.
     *
     * @return list<array<string, mixed>>
     */
    private function repos(): array
    {
        return GitHubRepository::query()
            ->with(['linkable' => fn (MorphTo $morph) => $morph->withoutGlobalScopes()])
            ->orderBy('full_name')
            ->get()
            ->map(fn (GitHubRepository $repo): array => [
                'full_name' => $repo->full_name,
                'name' => $repo->name,
                'url' => $repo->html_url,
                'private' => $repo->private,
                'default_branch' => $repo->default_branch,
                'branches' => $repo->branchNames(),
                'language' => $repo->language,
                'pushed_at' => $repo->pushed_at?->toIso8601String(),
                'linked_to' => self::linkedTo($repo->linkable),
            ])
            ->values()
            ->all();
    }

    /**
     * @return ?array{kind: string, key: string, name: string}
     */
    public static function linkedTo(?Model $linkable): ?array
    {
        return match (true) {
            $linkable instanceof AiProduct => ['kind' => 'product', 'key' => $linkable->slug, 'name' => $linkable->name],
            $linkable instanceof AiProject => ['kind' => 'project', 'key' => $linkable->key, 'name' => $linkable->name],
            default => null,
        };
    }

    /**
     * Counters of the current month (America/Bogota): every stored event
     * counts as received; ignored ones only there.
     *
     * @return array{month: string, received: int, processed: int, failed: int}
     */
    private function stats(): array
    {
        $start = now()->startOfMonth();
        $counts = GitHubWebhookEvent::query()
            ->where('received_at', '>=', $start)
            ->where('received_at', '<', $start->copy()->addMonth())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'month' => $start->locale('es')->translatedFormat('F'),
            'received' => (int) $counts->sum(),
            'processed' => (int) ($counts[GitHubWebhookEvent::STATUS_PROCESSED] ?? 0),
            'failed' => (int) ($counts[GitHubWebhookEvent::STATUS_FAILED] ?? 0),
        ];
    }

    /**
     * Failed events still pending, newest first. A `repo_not_linked` event
     * points to the product whose `repository` names the event repository
     * ("Vincularlo" opens its repository dialog).
     *
     * @return list<array<string, mixed>>
     */
    private function failedEvents(): array
    {
        $events = GitHubWebhookEvent::query()
            ->where('status', GitHubWebhookEvent::STATUS_FAILED)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();

        $unlinked = $events
            ->where('error_code', GitHubWebhookEvent::ERROR_REPO_NOT_LINKED)
            ->pluck('repository')
            ->filter()
            ->map(fn (string $name) => Str::lower($name))
            ->unique()
            ->values();

        $productSlugs = $unlinked->isEmpty() ? collect() : AiProduct::query()
            ->whereNotNull('repository')
            ->whereIn(DB::raw('lower(repository)'), $unlinked->all())
            // Oldest product wins when several name the same repository.
            ->orderByDesc('id')
            ->get(['slug', 'repository'])
            ->mapWithKeys(fn (AiProduct $product) => [Str::lower((string) $product->repository) => $product->slug]);

        return $events
            ->map(fn (GitHubWebhookEvent $event): array => [
                'id' => $event->id,
                'type' => $event->event,
                'repo' => (string) $event->repository,
                'received_at' => $event->received_at->toIso8601String(),
                'status' => 'failed',
                'error' => $event->error,
                'error_code' => $event->error_code,
                'fix_product_slug' => $event->error_code === GitHubWebhookEvent::ERROR_REPO_NOT_LINKED && $event->repository !== null
                    ? ($productSlugs[Str::lower($event->repository)] ?? null)
                    : null,
            ])
            ->values()
            ->all();
    }
}
