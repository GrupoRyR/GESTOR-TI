<?php

namespace App\Support\Ai\Presenters;

use App\Domain\Ai\Board;
use App\Domain\Ai\ProjectBudget;
use App\Domain\Ai\WipLimit;
use App\Enums\Ai\AcceptanceResult;
use App\Models\AcceptanceCriterion;
use App\Models\AiProject;
use App\Models\AiProjectMember;
use App\Models\AiRequest;
use App\Models\AiRequirement;
use App\Models\Area;
use App\Models\User;

/**
 * Project shapes of the design: `ProjectHeader` (ProjectLayout),
 * `ProjectListItem` (list) and `Project` (summary). Money only through
 * App\Domain\Ai\ProjectBudget, null without `ai.costs.view`.
 */
final class ProjectPresenter
{
    public function __construct(
        private readonly ProjectBudget $budget,
        private readonly WipLimit $wip,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function header(AiProject $project): array
    {
        return [
            'key' => $project->key,
            'name' => $project->name,
            'status' => $project->status->value,
            'company_id' => (int) $project->company_id,
            'area' => RequestPresenter::area($project->area),
            'request_keys' => $this->requestKeys($project),
            'open_requirements' => $this->openRequirements($project),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listItem(AiProject $project, User $viewer): array
    {
        return [
            'key' => $project->key,
            'name' => $project->name,
            'status' => $project->status->value,
            'company_id' => (int) $project->company_id,
            'area' => RequestPresenter::area($project->area),
            'request_keys' => $this->requestKeys($project),
            'team' => $this->team($project),
            'budget' => $this->budget->for($project, $viewer->can('ai.costs.view')),
            'tasks_by_column' => $this->tasksByColumn($project),
            'blocked_tasks' => $this->blockedTasks($project),
            'open_requirements' => $this->openRequirements($project),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function project(AiProject $project, User $viewer): array
    {
        return [
            ...$this->listItem($project, $viewer),
            'objective' => (string) $project->objective,
            'benefited_areas' => $project->benefitedAreas()
                ->orderBy('areas.name')
                ->get()
                ->map(fn (Area $area) => ['area' => RequestPresenter::area($area), 'note' => $area->pivot?->getAttribute('note')])
                ->values()
                ->all(),
            'wip' => $this->wip->snapshot(),
            'repository' => $project->repository_url === null ? null : [
                'url' => $project->repository_url,
                'branch' => $project->repository_branch ?? 'main',
                // Stage D (GitHub) fills pull requests and the last change.
                'open_prs' => 0,
                'last_change_at' => $project->updated_at->toIso8601String(),
            ],
            'criteria' => $this->criteria($project),
            'product_slug' => $project->product?->slug,
            'started_at' => $project->started_at?->toDateString() ?? $project->start_date?->toDateString(),
        ];
    }

    /**
     * @return list<string>
     */
    public function requestKeys(AiProject $project): array
    {
        return $project->requests()->pluck('ai_requests.key')->map(fn ($key) => (string) $key)->values()->all();
    }

    public function openRequirements(AiProject $project): int
    {
        return $project->requirements()->where('status', AiRequirement::PENDING)->count();
    }

    public function blockedTasks(AiProject $project): int
    {
        return $project->tasks()->whereHas('blockingRequirements')->count();
    }

    /**
     * @return array<string, int>
     */
    public function tasksByColumn(AiProject $project): array
    {
        $counts = $project->tasks()
            ->join('ai_project_columns', 'ai_project_columns.id', '=', 'ai_tasks.ai_project_column_id')
            ->selectRaw('ai_project_columns.key as column_key, count(*) as total')
            ->groupBy('ai_project_columns.key')
            ->pluck('total', 'column_key')
            ->map(fn ($total) => (int) $total)
            ->all();

        return Board::counts($counts);
    }

    /**
     * Lead first ("Líder del proyecto"), then the members in order.
     *
     * @return list<array{person: array<string, mixed>, role: string}>
     */
    public function team(AiProject $project): array
    {
        $members = $project->members()->with('user.employee')->get()
            ->filter(fn (AiProjectMember $member) => $member->user !== null);
        $team = [];

        if ($project->lead_id !== null && ! $members->contains('user_id', $project->lead_id) && $project->lead !== null) {
            $team[] = ['person' => RequestPresenter::person($project->lead), 'role' => 'Líder del proyecto'];
        }

        foreach ($members as $member) {
            $team[] = ['person' => RequestPresenter::person($member->user), 'role' => $member->role];
        }

        return $team;
    }

    /**
     * Acceptance criteria of its requests (validated by the requester).
     *
     * @return list<array<string, mixed>>
     */
    public function criteria(AiProject $project): array
    {
        return $project->requests()->with('criteria.validatedBy')->get()
            ->flatMap(fn (AiRequest $request) => $request->criteria)
            ->map(fn (AcceptanceCriterion $criterion) => [
                'id' => $criterion->id,
                'text' => $criterion->text,
                'validated' => $criterion->result === AcceptanceResult::Pass && $criterion->validated_at !== null
                    ? ['by' => (string) $criterion->validatedBy?->name, 'at' => $criterion->validated_at->toIso8601String()]
                    : null,
                'note' => $criterion->note,
            ])
            ->values()
            ->all();
    }
}
