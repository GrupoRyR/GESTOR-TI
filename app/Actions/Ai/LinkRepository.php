<?php

namespace App\Actions\Ai;

use App\Jobs\ProcessGitHubEvent;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\GitHubRepository;
use App\Models\GitHubWebhookEvent;
use App\Models\User;
use App\Support\Integrations\GitHubIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Vincular repositorio" (`ai.products.repository.store`,
 * `ai.projects.repository.store`, body `RepoLinkPayload`):
 *
 * - The repository must be installed (`github_repositories`) and free, or
 *   already linked to this target. A project may reuse the repository of
 *   its own product: the product keeps the link (events, CI, releases)
 *   and the project only records it (`repository_url`/`repository_branch`).
 * - The branch must be one of the repository's branches.
 * - A previous repository of the target is unlinked (one per target).
 * - The product names it (`ai_products.repository`); the project records
 *   its URL and branch and a "github" activity.
 * - Failed `repo_not_linked` events of that repository go back to the
 *   queue (they were waiting for this link).
 */
class LinkRepository
{
    /**
     * @param  array{repo: string, branch: string, read_docs: bool, receive_events: bool}  $data
     * @return int events sent to reprocess
     */
    public function handle(AiProduct|AiProject $target, array $data, User $actor): int
    {
        $repo = GitHubRepository::query()->where(DB::raw('lower(full_name)'), Str::lower(trim($data['repo'])))->first();

        if ($repo === null) {
            throw ValidationException::withMessages(['repo' => 'Ese repositorio no está en la organización o la app de GitHub no tiene acceso a él.']);
        }

        $shared = $target instanceof AiProject && $target->product_id !== null
            && $repo->linkable_type === AiProduct::class && (int) $repo->linkable_id === (int) $target->product_id;

        if ($repo->isLinked() && ! $shared && ! ($repo->linkable_type === $target::class && (int) $repo->linkable_id === $target->getKey())) {
            $linked = GitHubIntegration::linkedTo($repo->linkable()->withoutGlobalScopes()->first());

            throw ValidationException::withMessages(['repo' => 'Ese repositorio ya está vinculado a '.($linked['name'] ?? 'otro producto o proyecto').'.']);
        }

        if (! in_array($data['branch'], $repo->branchNames(), true)) {
            throw ValidationException::withMessages(['branch' => 'Esa rama no existe en el repositorio.']);
        }

        return DB::transaction(function () use ($target, $data, $actor, $repo, $shared): int {
            if (! $shared) {
                GitHubRepository::query()
                    ->where('linkable_type', $target::class)
                    ->where('linkable_id', $target->getKey())
                    ->whereKeyNot($repo->id)
                    ->update(['linkable_type' => null, 'linkable_id' => null, 'linked_by_id' => null, 'linked_at' => null]);

                $repo->linkTo($target, $data['branch'], $actor, $data['read_docs'], $data['receive_events']);
            }

            if ($target instanceof AiProduct) {
                $target->forceFill(['repository' => $repo->full_name])->save();
            } else {
                $target->forceFill(['repository_url' => $repo->html_url, 'repository_branch' => $data['branch']])->save();
                $target->recordActivity('github', "vinculó el repositorio {$repo->full_name}", $actor);
            }

            activity('integrations')
                ->causedBy($actor)
                ->performedOn($repo)
                ->event('github_repository_linked')
                ->withProperties(['attributes' => [
                    'repository' => $repo->full_name,
                    'target' => ($target instanceof AiProduct ? 'product:'.$target->slug : 'project:'.$target->key),
                    'branch' => $data['branch'],
                ]])
                ->log('Vinculó un repositorio de GitHub');

            return $this->reprocess($repo);
        });
    }

    /**
     * Failed events that only waited for the link go back to the queue.
     */
    private function reprocess(GitHubRepository $repo): int
    {
        $ids = GitHubWebhookEvent::query()
            ->where('status', GitHubWebhookEvent::STATUS_FAILED)
            ->where('error_code', GitHubWebhookEvent::ERROR_REPO_NOT_LINKED)
            ->where(DB::raw('lower(repository)'), Str::lower($repo->full_name))
            ->orderBy('received_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            GitHubWebhookEvent::query()->whereKey($id)->update([
                'status' => GitHubWebhookEvent::STATUS_PENDING,
                'error' => null,
                'error_code' => null,
            ]);

            ProcessGitHubEvent::dispatch((int) $id)->afterCommit();
        }

        return $ids->count();
    }
}
