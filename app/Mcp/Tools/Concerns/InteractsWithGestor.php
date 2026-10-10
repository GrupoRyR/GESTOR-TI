<?php

namespace App\Mcp\Tools\Concerns;

use App\Domain\Ai\BoardPeople;
use App\Domain\Ai\SecretGuard;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * Shared lookups of the Gestor IA tools. Records are found through the
 * usual global scopes (CompanyScope: a user without `companies.view_all`
 * only reaches their company; with it, every company, since the connector
 * has no "Empresa" selector), so a record out of reach reads "No encontré…"
 * exactly like an unknown key.
 *
 * Failures are thrown as ValidationException with a Spanish message: the
 * MCP server turns them into a tool error (`isError: true`), never a 500.
 */
trait InteractsWithGestor
{
    protected function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            $this->fail('No hay un usuario autenticado.');
        }

        return $user;
    }

    protected function project(string $key): AiProject
    {
        $key = strtoupper(trim($key));
        $project = AiProject::query()->where('key', $key)->first();

        return $project instanceof AiProject ? $project : $this->fail("No encontré el proyecto {$key}.");
    }

    protected function task(AiProject $project, string $key): AiTask
    {
        $key = strtoupper(trim($key));
        $task = $project->tasks()->where('key', $key)->first();

        return $task instanceof AiTask ? $task : $this->fail("No encontré la tarea {$key} en {$project->key}.");
    }

    /**
     * Assignee ids for `responsable` (an email): the board's people (IT team,
     * project team and lead). An empty value clears the assignees.
     *
     * @return list<int>
     */
    protected function assignees(AiProject $project, ?string $email): array
    {
        $email = trim((string) $email);

        if ($email === '') {
            return [];
        }

        $person = app(BoardPeople::class)->for($project)
            ->first(fn (User $user) => mb_strtolower($user->email) === mb_strtolower($email));

        return $person instanceof User
            ? [$person->id]
            : $this->fail('Solo puedes asignar a personas del equipo de TI o del proyecto (indica su correo).');
    }

    /**
     * @param  array<string, ?string>  $texts
     */
    protected function assertNoSecrets(array $texts, string $message): void
    {
        $guard = app(SecretGuard::class);

        foreach ($texts as $field => $text) {
            if ($text !== null && $guard->find($text) !== null) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }
    }

    /**
     * Activity log entry of a connector write (`ai` log, origin `api` from
     * EnsureMcpAccess, `tool` = the tool name).
     *
     * @param  array<string, mixed>  $properties
     */
    protected function audit(Model $subject, User $actor, string $event, string $description, array $properties = []): void
    {
        activity('ai')
            ->performedOn($subject)
            ->causedBy($actor)
            ->event($event)
            ->withProperties(['tool' => $this->name(), ...$properties])
            ->log($description);
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['gestor' => $message]);
    }
}
