<?php

namespace App\Domain\GitHub;

use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\GitHubRepository;
use App\Models\GitHubWebhookEvent;
use App\Models\User;
use App\Support\Ai\Presenters\GitHubPresenter;
use App\Support\Integrations\GitHubIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

/**
 * Setup of the GitHub dialogs (types/pages/ai-github.ts, ai-projects.ts),
 * only for `ai.github.manage`:
 *
 * - `RepoLinkSetup` ("Vincular repositorio") while the product has no
 *   linked repository / the project has no repository; defaults to the
 *   repository the product names (`ai_products.repository`) or, for a
 *   project, its product's repository. `pending_note` tells which failed
 *   events of that repository are reprocessed on linking.
 * - `RepoCreateSetup` ("Crear repositorio con este CLAUDE.md") while the
 *   project has no repository.
 */
final class RepoLinkForm
{
    public const ERROR_KEYS = ['repo', 'branch'];

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /** Webhook event type → how the note names it. */
    private const EVENT_NAMES = [
        'release' => 'El release',
        'pull_request' => 'El PR',
        'issues' => 'El issue',
        'push' => 'El push',
        'workflow_run' => 'El CI',
    ];

    public function __construct(private readonly GitHubIntegration $integration) {}

    /**
     * @param  array<string, mixed>  $old  old input after a failed store
     * @return array<string, mixed>|null
     */
    public function setup(AiProduct|AiProject $target, User $viewer, bool $open, array $old = [], ?ViewErrorBag $errors = null): ?array
    {
        if (! $viewer->can('ai.github.manage') || $this->hasRepository($target)) {
            return null;
        }

        $failed = $errors !== null && $errors->getBag('default')->hasAny(self::ERROR_KEYS);
        $candidate = $this->candidate($target);

        $defaults = $failed && isset($old['repo'])
            ? [
                'repo' => (string) $old['repo'],
                'branch' => (string) ($old['branch'] ?? ''),
                'read_docs' => filter_var($old['read_docs'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'receive_events' => filter_var($old['receive_events'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ]
            : [
                'repo' => (string) $candidate?->full_name,
                'branch' => $candidate?->default_branch ?? 'main',
                'read_docs' => true,
                'receive_events' => true,
            ];

        return [
            'repos' => GitHubPresenter::repos(),
            'target' => $target instanceof AiProduct
                ? ['kind' => 'product', 'key' => $target->slug, 'name' => $target->name, 'product_key' => null]
                : ['kind' => 'project', 'key' => $target->key, 'name' => $target->name, 'product_key' => $target->product?->slug],
            'organization' => $this->organization(),
            'open' => $open || $failed,
            'defaults' => $defaults,
            'pending_note' => $this->pendingNote($defaults['repo']),
        ];
    }

    /**
     * `RepoCreateSetup`; null without permission or once the project has a repository.
     *
     * @return array{organization: string, default_name: string}|null
     */
    public function createSetup(AiProject $project, User $viewer): ?array
    {
        if (! $viewer->can('ai.github.manage') || $this->hasRepository($project)) {
            return null;
        }

        return ['organization' => $this->organization(), 'default_name' => Str::slug($project->name) ?: Str::lower($project->key)];
    }

    public function organization(): string
    {
        return $this->integration->organization() ?? GitHubIntegration::ORGANIZATION_PLACEHOLDER;
    }

    public function hasRepository(AiProduct|AiProject $target): bool
    {
        if ($target instanceof AiProject && $target->repository_url !== null) {
            return true;
        }

        return $target->githubRepository()->exists();
    }

    /**
     * The repository the dialog suggests: the one the product names, or
     * the product's linked repository for a project.
     */
    private function candidate(AiProduct|AiProject $target): ?GitHubRepository
    {
        if ($target instanceof AiProject) {
            return $target->product_id === null ? null : GitHubRepository::query()
                ->where('linkable_type', AiProduct::class)
                ->where('linkable_id', $target->product_id)
                ->first();
        }

        if (blank($target->repository)) {
            return null;
        }

        return GitHubRepository::query()
            ->where(DB::raw('lower(full_name)'), Str::lower((string) $target->repository))
            ->whereNull('linkable_id')
            ->first();
    }

    /**
     * "El release que falló el 5 oct se reprocesa solo al vincular."
     */
    private function pendingNote(string $repo): ?string
    {
        if ($repo === '') {
            return null;
        }

        $events = GitHubWebhookEvent::query()
            ->where('status', GitHubWebhookEvent::STATUS_FAILED)
            ->where('error_code', GitHubWebhookEvent::ERROR_REPO_NOT_LINKED)
            ->where(DB::raw('lower(repository)'), Str::lower($repo))
            ->orderBy('received_at')
            ->get(['id', 'event', 'received_at']);

        if ($events->isEmpty()) {
            return null;
        }

        if ($events->count() > 1) {
            return "Los {$events->count()} eventos que fallaron se reprocesan solos al vincular.";
        }

        /** @var GitHubWebhookEvent $event */
        $event = $events->first();
        $date = $event->received_at->copy()->setTimezone('America/Bogota');
        $name = self::EVENT_NAMES[$event->event] ?? 'El evento';

        return "{$name} que falló el {$date->day} ".self::MONTHS[$date->month - 1].' se reprocesa solo al vincular.';
    }
}
