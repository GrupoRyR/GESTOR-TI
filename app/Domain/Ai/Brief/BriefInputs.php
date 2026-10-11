<?php

namespace App\Domain\Ai\Brief;

use App\Domain\Ai\SecretGuard;
use App\Domain\GitHub\RepoActivity;
use App\Enums\Ai\AcceptanceResult;
use App\Enums\Ai\CommentVisibility;
use App\Models\AcceptanceCriterion;
use App\Models\AiDocument;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiRequestComment;
use App\Models\AiSubtask;
use App\Models\AiTask;
use App\Models\Company;
use App\Models\GitHubRepository;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Server inputs of "Brief para Claude" (PROMPT 6.4.7 step 1): the page
 * builds the Markdown with lib/brief.ts from `RequestBriefInput`,
 * `TaskBriefInput` and `ProjectBriefInput`. Only for `ai.manage` with
 * access to the company (the request/project `manage` policies); null
 * otherwise.
 *
 * Whitelist: keys, titles, company short name, area, status labels, the
 * requester's job title (never their name), size, problem, current
 * process, expected result, the requester's hours per week and people,
 * acceptance criteria, product name/version/stack/hosting and the
 * request systems, repository + CLAUDE.md + Gestor documentation links,
 * task rows, assignee names, subtasks and the public decision log. Never
 * read: vault items, rates, time entries, costs, estimates, product
 * monthly cost, area hour value, comments or internal notes. Free text
 * goes through BriefText (credentials, money, internal notes dropped).
 */
final class BriefInputs
{
    /** Gestor documents linked from a brief ("prompts" carries model costs). */
    private const LINKED_TEMPLATES = ['project_sheet', 'requirements', 'architecture', 'technical_manual', 'user_manual'];

    private const DECISIONS_LIMIT = 20;

    /** RequestStatus phase rail (lib/status.ts PHASE_LABELS) of a project status. */
    private const PHASES = [
        'planned' => 'Plan',
        'in_development' => 'Construcción',
        'in_testing' => 'Construcción',
        'in_production' => 'Entrega',
        'closed' => 'Entrega',
    ];

    public function __construct(private readonly SecretGuard $secrets) {}

    /**
     * `BriefOpenState` from `?brief=1`; null (closed) otherwise.
     *
     * @return array{open: true}|null
     */
    public static function state(Request $http): ?array
    {
        return filter_var($http->query('brief'), FILTER_VALIDATE_BOOLEAN) ? ['open' => true] : null;
    }

    /**
     * `RequestBriefInput` of the IT view.
     *
     * @return array<string, mixed>|null
     */
    public function forRequest(AiRequest $request, User $viewer): ?array
    {
        if (! Gate::forUser($viewer)->allows('viewInternal', $request)) {
            return null;
        }

        $request->loadMissing(['company', 'area', 'product', 'requester.employee', 'criteria']);
        /** @var AiProject|null $project */
        $project = $request->projects()->orderBy('ai_projects.id')->first();
        $text = $this->text($project === null ? [$request->id] : [$request->id, ...$this->requestIds($project)]);

        return [
            'key' => $request->key,
            'title' => $request->title,
            'company' => $this->company($request->company),
            'area' => (string) $request->area?->name,
            'requester' => (string) ($request->requester?->employee?->job_title ?: $request->area?->name),
            'size' => $request->size?->value,
            'project_key' => $project?->key,
            'problem' => (string) $text->clean($request->problem),
            'current_process' => $text->clean($request->current_process),
            'expected_result' => $text->clean($request->expected_result),
            'hours_per_week' => $this->number($request->hours_per_week),
            'people' => $request->people_count,
            'criteria' => $this->criteriaTexts($request->criteria, $text),
            'product' => $this->product($request->product, $request),
            'links' => $this->links($project, $request->product, $text),
            'tasks' => $project === null ? [] : $this->taskRows($project),
            'decisions' => $project === null ? [] : $this->decisions($project, $text),
        ];
    }

    /**
     * `ProjectBriefInput` of the project summary.
     *
     * @return array<string, mixed>|null
     */
    public function forProject(AiProject $project, User $viewer): ?array
    {
        if (! Gate::forUser($viewer)->allows('manage', $project)) {
            return null;
        }

        $project->loadMissing(['company', 'area', 'product']);
        $requests = $project->requests()->with('criteria')->orderBy('ai_requests.id')->get();
        $text = $this->text($requests->pluck('id')->all());

        return [
            'key' => $project->key,
            'name' => $project->name,
            'company' => $this->company($project->company),
            'area' => (string) $project->area?->name,
            'status' => $project->status->label(),
            'phase' => self::PHASES[$project->status->value] ?? null,
            'request_keys' => $requests->pluck('key')->values()->all(),
            'objective' => (string) $text->clean($project->objective),
            'criteria' => $requests
                ->flatMap(fn (AiRequest $request) => $request->criteria)
                ->filter(fn (AcceptanceCriterion $criterion) => $text->clean($criterion->text) !== null)
                ->map(fn (AcceptanceCriterion $criterion) => [
                    'text' => trim($criterion->text),
                    'done' => $criterion->result === AcceptanceResult::Pass && $criterion->validated_at !== null,
                ])
                ->values()->all(),
            'product' => $this->product($project->product, $requests->first()),
            'links' => $this->links($project, $project->product, $text),
            'tasks' => $this->taskRows($project),
            'decisions' => $this->decisions($project, $text),
        ];
    }

    /**
     * `TaskBriefInput` of the board drawer and the task page. The page adds
     * the task's own issue/PR and the suggested branch from `TaskGitHub`.
     *
     * @return array<string, mixed>|null
     */
    public function forTask(AiTask $task, AiProject $project, User $viewer): ?array
    {
        if (! Gate::forUser($viewer)->allows('manage', $project)) {
            return null;
        }

        $task->loadMissing(['column', 'assignees', 'subtasks']);
        $project->loadMissing('product');
        $origin = $project->originRequest();
        $text = $this->text($this->requestIds($project));

        return [
            'key' => $task->key,
            'title' => $task->title,
            'project' => ['key' => $project->key, 'name' => $project->name],
            'request' => $origin === null ? null : ['key' => $origin->key, 'title' => $origin->title],
            'status' => (string) $task->column?->name,
            'assignees' => $task->assignees->sortBy('name')->pluck('name')->values()->all(),
            'description' => (string) $text->clean($task->description),
            'subtasks' => $task->subtasks
                ->sortBy(fn (AiSubtask $subtask) => [$subtask->position, $subtask->id])
                ->filter(fn (AiSubtask $subtask) => $text->clean($subtask->title) !== null)
                ->map(fn (AiSubtask $subtask) => ['title' => trim($subtask->title), 'done' => (bool) $subtask->done])
                ->values()->all(),
            'criteria' => $origin === null ? [] : $this->criteriaTexts($origin->criteria()->get(), $text),
            'product' => $this->product($project->product, $origin),
            'links' => $this->links($project, $project->product, $text),
            'pull_request' => null,
            'issue' => $task->github_issue_number === null ? null : ['number' => $task->github_issue_number],
            'suggested_branch' => null,
            'tasks' => $this->taskRows($project),
            'decisions' => $this->decisions($project, $text),
        ];
    }

    /**
     * `BriefOmitted` of the project summary: whether the project has hours,
     * estimates or costs (never sent), and how many internal notes its
     * requests have.
     *
     * @return array{time_and_costs: bool, internal_notes: int}|null
     */
    public function omitted(AiProject $project, User $viewer): ?array
    {
        if (! Gate::forUser($viewer)->allows('manage', $project)) {
            return null;
        }

        $timeAndCosts = $project->timeEntries()->exists()
            || $project->costs()->exists()
            || $project->estimates()->exists()
            || $project->tasks()->whereNotNull('estimate_hours')->exists();

        return [
            'time_and_costs' => $timeAndCosts,
            'internal_notes' => $this->internalNotes($this->requestIds($project))->count(),
        ];
    }

    /**
     * @param  list<int>  $requestIds
     */
    private function text(array $requestIds): BriefText
    {
        return new BriefText($this->secrets, $this->internalNotes($requestIds)->pluck('body')->all());
    }

    /**
     * @param  list<int>  $requestIds
     * @return Collection<int, AiRequestComment>
     */
    private function internalNotes(array $requestIds): Collection
    {
        return $requestIds === [] ? collect() : AiRequestComment::query()
            ->whereIn('ai_request_id', $requestIds)
            ->where('visibility', CommentVisibility::Internal->value)
            ->get(['id', 'body']);
    }

    /**
     * @return list<int>
     */
    private function requestIds(AiProject $project): array
    {
        return $project->requests()->pluck('ai_requests.id')->map(fn ($id) => (int) $id)->all();
    }

    private function company(?Company $company): string
    {
        return (string) ($company?->short_name ?: $company?->name);
    }

    private function number(int|float|string|null $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;

        return floor($float) === $float ? (int) $float : $float;
    }

    /**
     * @param  iterable<AcceptanceCriterion>  $criteria
     * @return list<string>
     */
    private function criteriaTexts(iterable $criteria, BriefText $text): array
    {
        return collect($criteria)
            ->sortBy(fn (AcceptanceCriterion $criterion) => [$criterion->position, $criterion->id])
            ->map(fn (AcceptanceCriterion $criterion) => $text->clean($criterion->text))
            ->filter()
            ->values()->all();
    }

    /**
     * `BriefProduct`: name, version, stack, hosting; `data_source` = the
     * systems the request named ("SAP Business One").
     *
     * @return array<string, string|null>|null
     */
    private function product(?AiProduct $product, ?AiRequest $request): ?array
    {
        if ($product === null) {
            return null;
        }

        $systems = collect((array) $request?->systems)->map(fn ($system) => trim((string) $system))->filter()->unique()->values();

        return [
            'name' => $product->name,
            'version' => $product->current_version,
            'stack' => $product->stack,
            'host' => $product->hosting,
            'data_source' => $systems->isEmpty() ? null : $systems->implode(', '),
        ];
    }

    /**
     * Repository and its CLAUDE.md, then the Gestor documentation of the
     * project and the product (whitelisted templates only).
     *
     * @return list<array{label: string, url: string}>
     */
    private function links(?AiProject $project, ?AiProduct $product, BriefText $text): array
    {
        $repo = $project !== null ? RepoActivity::repositoryOf($project) : null;
        $repo ??= $product?->githubRepository()->first();
        $links = [];

        if ($repo instanceof GitHubRepository) {
            $url = rtrim($repo->html_url, '/');
            $links[] = $text->link('Repositorio', $url);
            $links[] = $text->link('Instrucciones para Claude (CLAUDE.md)', $url.'/blob/'.$repo->trackedBranch().'/CLAUDE.md');
        } else {
            $links[] = $text->link('Repositorio', $project?->repository_url ?: $product?->repository);
        }

        if ($project !== null || $product !== null) {
            $documents = AiDocument::query()
                ->where(function ($query) use ($project, $product): void {
                    $query->when($project !== null, fn ($query) => $query->orWhere('ai_project_id', $project?->id))
                        ->when($product !== null, fn ($query) => $query->orWhere('ai_product_id', $product?->id));
                })
                ->whereIn('template', self::LINKED_TEMPLATES)
                ->where('not_applicable', false)
                ->orderByRaw('CASE WHEN ai_project_id IS NULL THEN 1 ELSE 0 END')
                ->orderBy('id')
                ->get(['id', 'title']);

            foreach ($documents as $document) {
                $links[] = $text->link($document->title, route('ai.docs.edit', $document->id));
            }
        }

        return array_values(array_filter($links));
    }

    /**
     * `BriefTaskRow[]` in board order.
     *
     * @return list<array{key: string, title: string, status: string, github: string|null}>
     */
    private function taskRows(AiProject $project): array
    {
        return $project->tasks()
            ->with('column')
            ->get()
            ->sortBy(fn (AiTask $task) => [$task->column?->position, $task->position, $task->id])
            ->map(fn (AiTask $task) => [
                'key' => $task->key,
                'title' => $task->title,
                'status' => (string) $task->column?->name,
                'github' => match (true) {
                    $task->github_pr_number !== null => 'PR #'.$task->github_pr_number,
                    $task->github_issue_number !== null => 'Issue #'.$task->github_issue_number,
                    default => null,
                },
            ])
            ->values()->all();
    }

    /**
     * Rows of the project's "Registro de decisiones" (`| Fecha | Decisión |
     * Motivo |`), oldest first. IT-only logs are never read.
     *
     * @return list<array{text: string}>
     */
    private function decisions(AiProject $project, BriefText $text): array
    {
        $documents = AiDocument::query()
            ->where('ai_project_id', $project->id)
            ->where('template', 'decisions')
            ->where('visibility', '!=', 'it')
            ->where('not_applicable', false)
            ->orderBy('id')
            ->pluck('body');

        $decisions = [];

        foreach ($documents as $body) {
            foreach (preg_split('/\R/u', (string) $body) ?: [] as $line) {
                $decision = $this->decisionRow($line);
                $clean = $decision === null ? null : $text->clean($decision);

                if ($clean !== null && count($decisions) < self::DECISIONS_LIMIT) {
                    $decisions[] = ['text' => $clean];
                }
            }
        }

        return $decisions;
    }

    /**
     * "Decisión. Motivo: motivo" of one table row; null for the header,
     * the separator, empty rows and lines outside a table.
     */
    private function decisionRow(string $line): ?string
    {
        $line = trim($line);

        if (! str_starts_with($line, '|')) {
            return null;
        }

        $cells = array_map('trim', explode('|', trim($line, '|')));
        $decision = $cells[1] ?? '';
        $reason = $cells[2] ?? '';

        if ($decision === '' || preg_match('/^:?-{2,}:?$/', $decision) === 1 || mb_strtolower($decision) === 'decisión') {
            return null;
        }

        return $reason === '' ? $decision : rtrim($decision, '. ').'. Motivo: '.$reason;
    }
}
