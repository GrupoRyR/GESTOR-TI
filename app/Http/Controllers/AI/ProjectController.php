<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\ConvertRequestToProject;
use App\Domain\Ai\ApprovalRounds;
use App\Domain\Ai\Board;
use App\Domain\Ai\Brief\BriefInputs;
use App\Domain\Ai\InvalidTransition;
use App\Domain\Ai\ProjectBudget;
use App\Domain\Ai\TriagePeople;
use App\Domain\Ai\Week;
use App\Domain\Ai\WipLimit;
use App\Domain\GitHub\RepoLinkForm;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AiProject;
use App\Models\AiProjectActivity;
use App\Models\AiRequest;
use App\Models\AiRequirement;
use App\Models\User;
use App\Support\Ai\Presenters\ProjectPresenter;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\Presenters\RequirementPresenter;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects (PROMPT 6.4.3, types/pages/ai-projects.ts):
 *
 * - `Ai/Projects/Index` (`ai.projects.index`, `ai.manage`): projects of the
 *   company in view, active first; filters `status` and `company_id`
 *   (only in "Todas"); global WIP.
 * - `Ai/Projects/Show` (`ai.projects.show`, AiProjectPolicy::view): the
 *   summary with the origin request, open requirements, the last 6
 *   activity entries and the re-approval data. Stage D: `repo_link` and
 *   `repo_create` (App\Domain\GitHub\RepoLinkForm), null without
 *   `ai.github.manage` or once the project has a repository.
 * - `ai.requests.convert` ("Convertir en proyecto", `ai.manage`):
 *   App\Actions\Ai\ConvertRequestToProject, then the new summary.
 *
 * Money (`budget.cost`) is null without `ai.costs.view`.
 */
class ProjectController extends Controller
{
    /** Activity entries on the summary. */
    private const ACTIVITY_LIMIT = 6;

    public function __construct(
        private readonly ProjectPresenter $presenter,
        private readonly WipLimit $wip,
        private readonly ProjectBudget $budget,
        private readonly TriagePeople $people,
        private readonly RepoLinkForm $repoLink,
        private readonly BriefInputs $briefs,
    ) {}

    public function index(Request $http, CurrentCompany $companies): Response
    {
        $user = $this->user($http);
        Gate::authorize('viewAny', AiProject::class);

        $status = in_array($http->query('status'), AiProject::STATUSES, true) ? (string) $http->query('status') : null;
        $companyId = $companies->isAll() && is_numeric($http->query('company_id')) ? (int) $http->query('company_id') : null;

        $order = array_flip(AiProject::ACTIVE_ORDER);
        $projects = AiProject::query()
            ->with(['area', 'lead.employee', 'product'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderByDesc('id')
            ->get()
            ->sortBy(fn (AiProject $project) => [$order[$project->status->value] ?? count($order), -$project->id])
            ->values();

        return Inertia::render('Ai/Projects/Index', [
            'projects' => $projects->map(fn (AiProject $project) => $this->presenter->listItem($project, $user))->all(),
            'filters' => ['status' => $status, 'company_id' => $companyId],
            'wip' => $this->wip->snapshot(),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function show(Request $http, AiProject $project): Response
    {
        $user = $this->user($http);
        Gate::authorize('view', $project);

        return Inertia::render('Ai/Projects/Show', [
            'project' => $this->presenter->project($project, $user),
            'origin_request' => $this->originRequest($project),
            'open_requirements' => $project->requirements()
                ->where('status', AiRequirement::PENDING)
                ->with(RequirementPresenter::RELATIONS)
                ->orderBy('due_date')
                ->orderBy('id')
                ->get()
                ->map(fn (AiRequirement $requirement) => RequirementPresenter::requirement($requirement))
                ->values()
                ->all(),
            'activity' => $project->activities()
                ->with('user.employee')
                ->latest('id')
                ->limit(self::ACTIVITY_LIMIT)
                ->get()
                ->map(fn (AiProjectActivity $activity) => [
                    'id' => $activity->id,
                    'kind' => $activity->kind,
                    'actor' => $activity->user !== null ? RequestPresenter::person($activity->user) : null,
                    'text' => $activity->text,
                    'at' => ($activity->created_at ?? now())->toIso8601String(),
                ])
                ->values()
                ->all(),
            'approvers' => $this->people->approvers()->map(fn (User $approver) => RequestPresenter::person($approver))->values()->all(),
            'reapproval_threshold' => $this->budget->threshold(),
            'reapproval_requested_at' => $project->reapproval_requested_at?->toIso8601String(),
            'next_step' => $this->nextStep($project),
            'today' => Week::today()->toDateString(),
            'repo_link' => $this->repoLink->setup($project, $user, false, (array) $http->old(), $http->session()->get('errors')),
            'repo_create' => $this->repoLink->createSetup($project, $user),
            'brief' => $brief = $this->briefs->forProject($project, $user),
            'brief_omitted' => $brief === null ? null : $this->briefs->omitted($project, $user),
            'brief_state' => $brief === null ? null : BriefInputs::state($http),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function convert(Request $http, AiRequest $request, ConvertRequestToProject $convert): RedirectResponse
    {
        $data = $http->validate([
            'name' => ['required', 'string', 'max:160'],
            'start_date' => ['required', 'date_format:Y-m-d'],
        ], [
            'name.required' => 'Escribe el nombre del proyecto.',
            'start_date.required' => 'Elige cuándo empieza.',
        ]);

        try {
            $project = $convert->handle($request, $this->user($http), $data['name'], CarbonImmutable::parse($data['start_date']));
        } catch (InvalidTransition $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('ai.projects.show', $project)->with('success', "{$request->key} ahora es el proyecto {$project->key}.");
    }

    /**
     * The first request with who approved it (latest approved round).
     *
     * @return array<string, mixed>|null
     */
    private function originRequest(AiProject $project): ?array
    {
        /** @var AiRequest|null $request */
        $request = $project->requests()->with([...RequestPresenter::RELATIONS, 'approvals.approver.employee'])->first();

        if ($request === null) {
            return null;
        }

        $decided = ApprovalRounds::decided(ApprovalRounds::current($request));
        $approved = $decided !== null && $decided->decision === ApprovalDecision::Approved ? $decided : null;

        return [
            ...RequestPresenter::summary($request),
            'approved_by' => $approved?->approver !== null ? RequestPresenter::person($approved->approver) : null,
            'approved_at' => $approved?->decided_at?->toIso8601String(),
        ];
    }

    /**
     * One plain sentence about what comes next; null when nothing to add.
     */
    private function nextStep(AiProject $project): ?string
    {
        $blocked = $this->presenter->blockedTasks($project);

        return match ($project->status) {
            RequestStatus::Planned => 'Sigue mover la primera tarea a «'.Board::COLUMNS[Board::IN_PROGRESS].'» para empezar el desarrollo.',
            RequestStatus::InDevelopment => $blocked > 0
                ? 'Sigue destrabar '.($blocked === 1 ? '1 tarea bloqueada' : "{$blocked} tareas bloqueadas").' por requerimientos.'
                : null,
            RequestStatus::InTesting => 'Sigue Entrega, con pruebas de '.($project->area?->name ?? 'el área').'.',
            default => null,
        };
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
