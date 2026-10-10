<?php

namespace App\Mcp\Support;

use App\Domain\Ai\Money;
use App\Domain\GitHub\RepoActivity;
use App\Enums\Ai\CommentVisibility;
use App\Models\AcceptanceCriterion;
use App\Models\AiDocument;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiRequestComment;
use App\Models\AiTask;
use App\Models\User;
use App\Support\Ai\Presenters\ItDetailPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * What `ver_solicitud` returns, with the app's visibility rules:
 *
 * - The request (AiRequestPolicy::view): problem, current process,
 *   expected result, acceptance criteria, evaluation (size, urgency, risk,
 *   priority) and the public comments.
 * - Internal notes only with AiRequestPolicy::viewInternal.
 * - Linked projects the viewer may open (AiProjectPolicy::view) with their
 *   repository and tasks (column, assignees, GitHub issue and PR).
 * - Documents of those projects and of the request's product that the
 *   viewer may open (AiDocumentPolicy::view), Markdown cut at
 *   DOCUMENT_CHARS.
 * - `costos` (monthly impact, latest estimate) only with `ai.costs.view`;
 *   the estimate never carries hourly rates (TriagePresenter).
 *
 * Never read: vault items, rates, time entries, salaries, task comments.
 * Free text goes through SafeText.
 */
final class RequestDetail
{
    public const DOCUMENT_CHARS = 20000;

    /**
     * @return array<string, mixed>
     */
    public function build(AiRequest $request, User $viewer): array
    {
        $gate = Gate::forUser($viewer);
        $internal = $gate->allows('viewInternal', $request);
        $request->loadMissing(['company', 'area', 'product', 'requester', 'criteria']);

        /** @var Collection<int, AiProject> $projects */
        $projects = $request->projects()->orderBy('ai_projects.id')->get()
            ->filter(fn (AiProject $project) => $gate->allows('view', $project))
            ->values();
        $requestIds = $projects->flatMap(fn (AiProject $project) => $project->requests()->pluck('ai_requests.id'))
            ->push($request->id)->map(fn ($id) => (int) $id)->unique()->values()->all();
        $text = SafeText::for($viewer, $internal, $requestIds);

        $detail = [
            'clave' => $request->key,
            'titulo' => $request->title,
            'url' => route('ai.requests.show', $request->key),
            'estado' => $request->status->label(),
            'estado_clave' => $request->status->value,
            'empresa' => (string) ($request->company?->short_name ?: $request->company?->name),
            'area' => $request->area?->name,
            'solicitante' => $request->requester?->name,
            'producto' => $request->product === null ? null : [
                'nombre' => $request->product->name,
                'version' => $request->product_version ?? $request->product->current_version,
            ],
            'creada' => $request->created_at->toIso8601String(),
            'problema' => $text->clean($request->problem),
            'proceso_actual' => $text->clean($request->current_process),
            'resultado_esperado' => $text->clean($request->expected_result),
            'criterios_aceptacion' => $request->criteria
                ->map(fn (AcceptanceCriterion $criterion) => [
                    'texto' => $text->clean($criterion->text),
                    'resultado' => $criterion->result?->value,
                ])
                ->values()->all(),
            'evaluacion' => [
                'tamano' => $request->size?->value,
                'urgencia' => $request->urgency?->value,
                'riesgo' => $request->risk?->value,
                'prioridad_sugerida' => $request->priority_score,
                'prioridad_final' => $request->final_priority,
            ],
            'proyectos' => $projects->map(fn (AiProject $project) => $this->project($project))->all(),
            'comentarios' => $this->comments($request, CommentVisibility::Public, $text),
        ];

        if ($internal) {
            $detail['notas_internas'] = $this->comments($request, CommentVisibility::Internal, $text);
        }

        $detail['documentos'] = $this->documents($request, $projects, $viewer, $text);

        if ($viewer->can('ai.costs.view')) {
            $money = Money::impactPerMonth($request);
            $detail['costos'] = [
                'impacto_mensual' => $money === null ? null : Money::cop($money).' al mes',
                'estimado' => ItDetailPresenter::estimate($request, true),
            ];
        }

        return $detail;
    }

    /**
     * @return array<string, mixed>
     */
    private function project(AiProject $project): array
    {
        $repository = RepoActivity::repositoryOf($project);
        $tasks = $project->tasks()->with(['column', 'assignees'])->get()
            ->sortBy(fn (AiTask $task) => [$task->column?->position, $task->position, $task->id]);

        return [
            'clave' => $project->key,
            'nombre' => $project->name,
            'estado' => $project->status->label(),
            'url' => route('ai.projects.show', $project->key),
            'repositorio' => $repository !== null ? rtrim($repository->html_url, '/') : $project->repository_url,
            'tareas' => $tasks->map(fn (AiTask $task) => [
                'clave' => $task->key,
                'titulo' => $task->title,
                'columna' => (string) $task->column?->name,
                'responsables' => $task->assignees->pluck('name')->values()->all(),
                'issue' => $task->github_issue_url,
                'pull_request' => $task->github_pr_url,
                'url' => route('ai.tasks.show', [$project->key, $task->key]),
            ])->values()->all(),
        ];
    }

    /**
     * @return list<array{autor: ?string, fecha: string, texto: ?string}>
     */
    private function comments(AiRequest $request, CommentVisibility $visibility, SafeText $text): array
    {
        return $request->comments()
            ->where('visibility', $visibility->value)
            ->with('author')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AiRequestComment $comment) => [
                'autor' => $comment->author?->name,
                'fecha' => $comment->created_at->toIso8601String(),
                'texto' => $text->clean($comment->body),
            ])
            ->values()->all();
    }

    /**
     * @param  Collection<int, AiProject>  $projects
     * @return list<array<string, mixed>>
     */
    private function documents(AiRequest $request, Collection $projects, User $viewer, SafeText $text): array
    {
        $projectIds = $projects->pluck('id')->all();
        $productId = $request->product_id;

        if ($projectIds === [] && $productId === null) {
            return [];
        }

        return AiDocument::query()
            ->where(function ($query) use ($projectIds, $productId): void {
                $query->whereIn('ai_project_id', $projectIds)
                    ->when($productId !== null, fn ($query) => $query->orWhere('ai_product_id', $productId));
            })
            ->where('not_applicable', false)
            ->orderByRaw('CASE WHEN ai_project_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('id')
            ->get()
            ->filter(fn (AiDocument $document) => Gate::forUser($viewer)->allows('view', $document))
            ->map(fn (AiDocument $document) => [
                'id' => $document->id,
                'titulo' => $document->title,
                'plantilla' => $document->template,
                'version' => $document->version,
                'url' => route('ai.docs.edit', $document->id),
                'contenido' => $this->body($text->clean($document->body)),
            ])
            ->values()->all();
    }

    private function body(?string $body): ?string
    {
        if ($body === null || mb_strlen($body) <= self::DOCUMENT_CHARS) {
            return $body;
        }

        return mb_substr($body, 0, self::DOCUMENT_CHARS)."\n\n[Documento recortado: ábrelo en el Gestor TI para leerlo completo.]";
    }
}
