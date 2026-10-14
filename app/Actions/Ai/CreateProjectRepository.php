<?php

namespace App\Actions\Ai;

use App\Domain\Ai\SecretGuard;
use App\Domain\GitHub\RepoLinkForm;
use App\Models\AiProject;
use App\Models\GitHubRepository;
use App\Models\User;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubException;
use App\Support\Integrations\GitHubIntegration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "Crear repositorio con este CLAUDE.md" (`ai.projects.repository.create`,
 * body `RepoCreatePayload`): only while the project has no repository.
 * Creates a private repository in the organization with README.md, the
 * given CLAUDE.md and docs/README.md, stores it in `github_repositories`
 * and links it to the project (events and docs on). GitHub failures come
 * back as validation errors on `name`, never as a 500 and never with tokens.
 */
class CreateProjectRepository
{
    public function __construct(
        private readonly GitHubApp $github,
        private readonly GitHubIntegration $integration,
        private readonly RepoLinkForm $form,
        private readonly SecretGuard $secrets,
    ) {}

    public function handle(AiProject $project, string $name, string $claudeMd, User $actor): GitHubRepository
    {
        if ($this->form->hasRepository($project)) {
            throw ValidationException::withMessages(['name' => 'Este proyecto ya tiene un repositorio vinculado.']);
        }

        $organization = $this->integration->organization();

        if ($organization === null || ! $this->integration->connected()) {
            throw ValidationException::withMessages(['name' => 'GitHub no está conectado. Revísalo en Administración › Integraciones › GitHub.']);
        }

        $this->secrets->assertClean($claudeMd, 'claude_md');

        if (GitHubRepository::query()->where(DB::raw('lower(full_name)'), mb_strtolower("{$organization}/{$name}"))->exists()) {
            throw ValidationException::withMessages(['name' => 'Ya existe un repositorio con ese nombre en la cuenta de GitHub.']);
        }

        try {
            $data = $this->github->createRepository($organization, $name, $this->files($project, $claudeMd), "{$project->key} · {$project->name}");
        } catch (GitHubException $exception) {
            Log::warning('GitHub repository creation failed', ['reason' => $exception->reason, 'project' => $project->key]);

            throw ValidationException::withMessages(['name' => match ($exception->reason) {
                'github_422' => 'Ya existe un repositorio con ese nombre en la cuenta de GitHub.',
                'not_configured', 'not_connected' => 'GitHub no está conectado. Revísalo en Administración › Integraciones › GitHub.',
                'github_403', 'github_404' => 'La app de GitHub no tiene permiso para crear repositorios en la cuenta de GitHub.',
                default => 'GitHub no respondió. Intenta de nuevo en unos minutos.',
            }]);
        }

        return DB::transaction(function () use ($project, $actor, $data, $organization, $name): GitHubRepository {
            $branch = (string) ($data['default_branch'] ?? 'main');
            $fullName = (string) ($data['full_name'] ?? "{$organization}/{$name}");

            $repo = GitHubRepository::query()->firstOrNew(['github_id' => (int) ($data['id'] ?? 0)]);
            $repo->fill([
                'full_name' => $fullName,
                'name' => (string) ($data['name'] ?? $name),
                'default_branch' => $branch,
                'private' => (bool) ($data['private'] ?? true),
                'html_url' => (string) ($data['html_url'] ?? "https://github.com/{$fullName}"),
                'language' => null,
                'pushed_at' => isset($data['pushed_at']) ? Carbon::parse((string) $data['pushed_at'])->setTimezone(config('app.timezone')) : now(),
                'branches' => [$branch],
                'installation_id' => $this->github->installationId(),
                'last_synced_at' => now(),
            ])->save();

            $repo->linkTo($project, $branch, $actor);
            $project->forceFill(['repository_url' => $repo->html_url, 'repository_branch' => $branch])->save();
            $project->recordActivity('github', "creó el repositorio {$repo->full_name}", $actor);

            activity('integrations')
                ->causedBy($actor)
                ->performedOn($repo)
                ->event('github_repository_created')
                ->withProperties(['attributes' => ['repository' => $repo->full_name, 'target' => 'project:'.$project->key]])
                ->log('Creó un repositorio en GitHub');

            return $repo;
        });
    }

    /**
     * @return array<string, string>
     */
    private function files(AiProject $project, string $claudeMd): array
    {
        $readme = "# {$project->name}\n\nProyecto {$project->key} del Gestor TI.\n\n"
            ."- `CLAUDE.md`: contexto para trabajar con Claude.\n"
            ."- `docs/`: documentación del proyecto.\n";

        return [
            'README.md' => $readme,
            'CLAUDE.md' => rtrim($claudeMd)."\n",
            'docs/README.md' => "# Documentación\n\nDocumentación técnica de {$project->name} ({$project->key}).\n",
        ];
    }
}
