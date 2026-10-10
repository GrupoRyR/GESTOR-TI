<?php

namespace App\Domain\GitHub\Events;

use App\Domain\GitHub\GiaKeys;
use App\Models\GitHubRepository;
use Illuminate\Support\Carbon;

/**
 * `workflow_run` (CI): keeps the latest run per branch on
 * `github_repositories.ci` (design `CiRun`, the product page reads the
 * linked branch) and the CI status of the tasks whose PR or branch the run
 * belongs to (`ai_tasks.github_ci`). An older delivery never overwrites a
 * newer run of the same branch.
 */
class WorkflowRunHandler implements EventHandler
{
    public function __construct(private readonly WorkLinker $linker) {}

    public function needsLinkedRepository(): bool
    {
        return true;
    }

    public function handle(string $action, array $payload, ?GitHubRepository $repository): Outcome
    {
        /** @var GitHubRepository $repository */
        $run = is_array($payload['workflow_run'] ?? null) ? $payload['workflow_run'] : [];
        $branch = (string) ($run['head_branch'] ?? '');

        if ($branch === '') {
            return Outcome::ignored();
        }

        $status = self::status((string) ($run['status'] ?? ''), isset($run['conclusion']) ? (string) $run['conclusion'] : null);
        $runAt = isset($run['updated_at']) ? Carbon::parse((string) $run['updated_at']) : now();
        $keys = GiaKeys::extract(branch: $branch);

        $ci = $repository->ci ?? [];
        $previous = $ci[$branch]['run_at'] ?? null;

        if (is_string($previous) && Carbon::parse($previous)->greaterThan($runAt)) {
            return Outcome::processed($keys);
        }

        $ci[$branch] = [
            'status' => $status,
            'branch' => $branch,
            'commit_sha' => isset($run['head_sha']) ? substr((string) $run['head_sha'], 0, 7) : null,
            'run_at' => $runAt->toIso8601String(),
            'summary' => isset($run['name']) ? mb_substr((string) $run['name'], 0, 120) : null,
            'url' => isset($run['html_url']) ? (string) $run['html_url'] : null,
        ];
        $repository->forceFill(['ci' => $ci])->save();

        $prNumbers = array_values(array_filter(array_map(
            fn ($pr) => is_array($pr) ? (int) ($pr['number'] ?? 0) : 0,
            (array) ($run['pull_requests'] ?? []),
        )));

        $tasks = $this->linker->tasksFor($repository, $keys, null, $branch);

        foreach ($prNumbers as $number) {
            $tasks = $tasks->merge($this->linker->tasksFor($repository, [], null, null, prNumber: $number));
        }

        foreach ($tasks->unique('id') as $task) {
            $task->forceFill(['github_ci' => $status])->save();
        }

        return Outcome::processed($keys);
    }

    /**
     * Design `CiStatus`: running until completed; then passing or failing;
     * cancelled/skipped/neutral runs count as none.
     */
    public static function status(string $status, ?string $conclusion): string
    {
        if ($status !== 'completed') {
            return 'running';
        }

        return match ($conclusion) {
            'success' => 'passing',
            'failure', 'timed_out', 'startup_failure', 'action_required' => 'failing',
            default => 'none',
        };
    }
}
