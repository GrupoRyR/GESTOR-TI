<?php

namespace App\Actions\Ai;

use App\Domain\GitHub\IssueText;
use App\Domain\GitHub\RepoActivity;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\User;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "Crear issue en GitHub" from a task (`ai.tasks.issue.store`, body
 * `IssuePayload`): the issue goes to the project's repository with the
 * `gestor-ia` label and the privacy footer (IssueText re-checks the text:
 * no money, credentials or internal notes). The assignee is the Gestor
 * person's `github_login`. The task keeps the issue number, URL, state and
 * what was sent (`github_issue`), and the project feed gets a "github"
 * activity. GitHub failures come back as a validation error on `title`,
 * never as a 500 and never with tokens.
 */
class CreateTaskIssue
{
    public function __construct(
        private readonly GitHubApp $github,
        private readonly IssueText $text,
    ) {}

    /**
     * @param  array{title: string, body: string, labels: list<string>, assignee_id: ?int}  $data
     * @return array<string, mixed> the created issue as `GitHubIssue`
     */
    public function handle(AiProject $project, AiTask $task, array $data, User $actor): array
    {
        if ($task->github_issue_number !== null) {
            throw ValidationException::withMessages(['title' => "Esta tarea ya tiene el issue #{$task->github_issue_number}."]);
        }

        $repo = RepoActivity::repositoryOf($project);

        if ($repo === null) {
            throw ValidationException::withMessages(['title' => 'El proyecto no tiene un repositorio vinculado. Vincúlalo en el resumen del proyecto.']);
        }

        $assignee = $data['assignee_id'] === null ? null : User::query()->find($data['assignee_id']);

        if ($data['assignee_id'] !== null && blank($assignee?->github_login)) {
            throw ValidationException::withMessages(['assignee_id' => 'Esa persona no tiene usuario de GitHub registrado.']);
        }

        $title = trim($data['title']);
        $this->text->assertSafe($title, $data['body'], $project);
        $body = IssueText::withFooter($data['body']);
        $labels = IssueText::labels($data['labels']);
        $logins = $assignee === null ? [] : [(string) $assignee->github_login];

        try {
            $issue = $this->github->createIssue($repo->full_name, $title, $body, $labels, $logins);
        } catch (GitHubException $exception) {
            Log::warning('GitHub issue creation failed', ['reason' => $exception->reason, 'repository' => $repo->full_name, 'task' => $task->id]);

            throw ValidationException::withMessages(['title' => self::failure($exception)]);
        }

        $number = (int) ($issue['number'] ?? 0);
        $task->forceFill([
            'github_issue_number' => $number,
            'github_issue_url' => (string) ($issue['html_url'] ?? $repo->html_url."/issues/{$number}"),
            'github_issue_state' => 'open',
            'github_issue' => [
                'title' => $title,
                'labels' => $labels,
                'assignee_login' => $logins[0] ?? null,
                'created_by_id' => $actor->id,
                'created_at' => RepoActivity::iso($issue['created_at'] ?? null) ?? now()->toIso8601String(),
            ],
        ])->save();

        $project->recordActivity('github', "creó el issue #{$number} de {$task->key} {$task->title}", $actor);

        return (new RepoActivity($repo))->taskIssue($task->fresh() ?? $task) ?? [];
    }

    public static function failure(GitHubException $exception): string
    {
        return match ($exception->reason) {
            'not_configured', 'not_connected' => 'GitHub no está conectado. Revísalo en Administración › Integraciones › GitHub.',
            'github_404', 'github_403' => 'La app de GitHub no tiene acceso a ese repositorio.',
            'github_410' => 'Los issues están desactivados en ese repositorio.',
            'github_422' => 'GitHub no aceptó el issue. Revisa las etiquetas y el responsable.',
            default => 'GitHub no respondió. Intenta de nuevo en unos minutos.',
        };
    }
}
