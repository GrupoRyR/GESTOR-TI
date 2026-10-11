<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\CreateProjectRepository;
use App\Actions\Ai\CreateTaskIssue;
use App\Actions\Ai\LinkRepository;
use App\Domain\GitHub\RepoActivity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\IssueRequest;
use App\Http\Requests\Ai\RepoCreateRequest;
use App\Http\Requests\Ai\RepoLinkRequest;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GitHub writes of the Gestor IA (types/pages/ai-github.ts), route-gated by
 * `ai.github.manage`:
 *
 * - `ai.products.repository.store` / `ai.projects.repository.store`:
 *   "Vincular repositorio" (App\Actions\Ai\LinkRepository).
 * - `ai.projects.repository.create`: "Crear repositorio con este
 *   CLAUDE.md" (App\Actions\Ai\CreateProjectRepository).
 * - `ai.tasks.issue.store`: "Crear issue en GitHub"
 *   (App\Actions\Ai\CreateTaskIssue). Back to the page it came from: the
 *   board gets `issue_created` (toast), the task page a success flash.
 */
class GitHubController extends Controller
{
    /** Session key of the board's `issue_created` (flashed once). */
    public const ISSUE_CREATED = 'github_issue_created';

    public function linkProduct(RepoLinkRequest $http, AiProduct $product, LinkRepository $link): RedirectResponse
    {
        Gate::authorize('view', $product);
        $count = $link->handle($product, $http->payload(), $this->user($http));

        return redirect()->route('ai.products.show', $product)->with('success', self::linkedMessage($count));
    }

    public function linkProject(RepoLinkRequest $http, AiProject $project, LinkRepository $link): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $count = $link->handle($project, $http->payload(), $this->user($http));

        return redirect()->route('ai.projects.show', $project)->with('success', self::linkedMessage($count));
    }

    public function createRepository(RepoCreateRequest $http, AiProject $project, CreateProjectRepository $create): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $repo = $create->handle($project, (string) $http->validated('name'), (string) $http->validated('claude_md'), $this->user($http));

        return redirect()->route('ai.projects.show', $project)
            ->with('success', "Se creó el repositorio privado {$repo->full_name} y quedó vinculado al proyecto.");
    }

    public function storeIssue(IssueRequest $http, AiProject $project, AiTask $task, CreateTaskIssue $create): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $repo = RepoActivity::repositoryOf($project);
        $issue = $create->handle($project, $task, $http->payload(), $this->user($http));

        if ($this->fromBoard($http)) {
            return back()->with(self::ISSUE_CREATED, [
                'task_key' => $task->key,
                'issue' => $issue,
                'repo_full_name' => (string) $repo?->full_name,
            ]);
        }

        return back()->with('success', "Se creó el issue #{$issue['number']} en GitHub.");
    }

    private static function linkedMessage(int $reprocessed): string
    {
        return match ($reprocessed) {
            0 => 'Repositorio vinculado.',
            1 => 'Repositorio vinculado. 1 evento pendiente se está reprocesando.',
            default => "Repositorio vinculado. {$reprocessed} eventos pendientes se están reprocesando.",
        };
    }

    /**
     * The form was sent from the board drawer (`ai.projects.board`).
     */
    private function fromBoard(Request $http): bool
    {
        $path = (string) parse_url(url()->previous(), PHP_URL_PATH);

        return str_ends_with(rtrim($path, '/'), '/board');
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
