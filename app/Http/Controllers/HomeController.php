<?php

namespace App\Http\Controllers;

use App\Domain\Ai\ApprovalRounds;
use App\Domain\Ai\BusinessDays;
use App\Domain\Ai\DueLabel;
use App\Domain\Ai\Inbox;
use App\Domain\Ai\Roadmap;
use App\Domain\Ai\Week;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequirement;
use App\Models\AiTask;
use App\Models\MonitoredApp;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Ai\Presenters\ApprovalPresenter;
use App\Support\Ai\RequesterCopy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inicio (`Pages/Home.tsx`, props `HomeProps` of resources/js/types/pages/home.ts).
 *
 * Two views: `it` for users with `ai.manage`, `requester` for everybody
 * else. The requester view lists the person's open requests (B3). The IT
 * view (B4) lists unanswered requests past or near their first-response
 * deadline and, for project approvers, the approvals waiting for them;
 * since B5 also overdue tasks and requirements waiting on users;
 * credentials (B8) are still empty;
 * monitoring lists the registered apps as placeholders until the module is
 * enabled.
 */
class HomeController extends Controller
{
    /** Requests shown on the requester home (the rest live in Mis solicitudes). */
    private const REQUESTER_LIMIT = 10;

    /** Unanswered requests shown under "Requiere atención". */
    private const ATTENTION_LIMIT = 10;

    public function __construct(
        private readonly Roadmap $roadmap,
        private readonly Inbox $inbox,
        private readonly ApprovalRounds $rounds,
        private readonly ApprovalPresenter $approvals,
    ) {}

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $greeting = ['greeting_name' => Str::before(trim($user->name), ' ')];

        if (! $user->can('ai.manage')) {
            return Inertia::render('Home', [
                'view' => 'requester',
                ...$greeting,
                'requests' => $this->requesterRequests($user),
            ]);
        }

        return Inertia::render('Home', [
            'view' => 'it',
            ...$greeting,
            'attention' => [
                'unanswered_requests' => $this->unansweredRequests(),
                'overdue_tasks' => $this->overdueTasks(),
                'waiting_requirements' => $this->waitingRequirements(),
                // TODO B8: only filled for `ai.vault.access` once the vault exists.
                'expiring_credentials' => [],
            ],
            'pending_approvals' => $this->pendingApprovals($user),
            'monitoring' => [
                'enabled' => false,
                'apps' => $this->monitoredApps(),
            ],
        ]);
    }

    /**
     * `RequesterRequest` rows: the person's requests that are not rejected
     * or closed, latest activity first.
     *
     * @return list<array{key: string, title: string, status: string, next_step: ?string, can_rate: bool, roadmap_position: ?string}>
     */
    private function requesterRequests(User $user): array
    {
        $requests = AiRequest::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('requester_id', $user->id)
            ->whereNotIn('status', RequestStatus::finishedValues())
            ->with('area')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::REQUESTER_LIMIT)
            ->get();
        $ranks = $this->roadmap->ranks($requests);

        return $requests->map(function (AiRequest $request) use ($user, $ranks): array {
            $rank = $ranks[$request->id] ?? null;
            $canRate = $user->can('rate', $request);

            return [
                'key' => $request->key,
                'title' => $request->title,
                'status' => $request->status->value,
                'next_step' => RequesterCopy::nextStep($request, $rank, $canRate),
                'can_rate' => $canRate,
                'roadmap_position' => $rank === null ? null : RequesterCopy::rankLabel($request, $rank),
            ];
        })->values()->all();
    }

    /**
     * `AttentionItem` rows: requests of the company in view without first
     * response whose deadline passed (`late`) or falls within 24 hours
     * (`attention`), most overdue first.
     *
     * @return list<array<string, mixed>>
     */
    private function unansweredRequests(): array
    {
        return Inbox::quick($this->inbox->query(), 'unanswered')
            ->whereNotNull('first_response_due_at')
            ->where('first_response_due_at', '<=', now()->addDay())
            ->orderBy('first_response_due_at')
            ->orderBy('id')
            ->limit(self::ATTENTION_LIMIT)
            ->get()
            ->map(function (AiRequest $request): array {
                $due = DueLabel::for($request->first_response_due_at);

                return [
                    'id' => 'request-'.$request->id,
                    'title' => "{$request->key} · {$due['label']}",
                    'detail' => $request->title,
                    'href_route' => 'ai.requests.show',
                    'href_params' => ['request' => $request->key],
                    'severity' => $due['level'] === 'late' ? 'late' : 'attention',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * `AttentionItem` rows: open tasks of the company in view past their
     * due date (Bogotá calendar), most overdue first.
     *
     * @return list<array<string, mixed>>
     */
    private function overdueTasks(): array
    {
        $today = Week::today();

        return Week::openTasks()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today->toDateString())
            ->with('project')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::ATTENTION_LIMIT)
            ->get()
            ->map(function (AiTask $task) use ($today): array {
                $days = (int) CarbonImmutable::parse($task->due_date->toDateString(), BusinessDays::TIMEZONE)->diffInDays($today);
                $key = (string) $task->project?->key;

                return [
                    'id' => 'task-'.$task->id,
                    'title' => "{$key} · {$task->key} · vencida hace ".($days === 1 ? '1 día' : "{$days} días"),
                    'detail' => $task->title,
                    'href_route' => 'ai.tasks.show',
                    'href_params' => ['project' => $key, 'task' => $task->key],
                    'severity' => 'late',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * `AttentionItem` rows: pending requirements of the company in view,
     * soonest due first; `late` once overdue.
     *
     * @return list<array<string, mixed>>
     */
    private function waitingRequirements(): array
    {
        $today = Week::today();

        return AiRequirement::query()
            ->where('status', AiRequirement::PENDING)
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::ATTENTION_LIMIT)
            ->get()
            ->map(function (AiRequirement $requirement) use ($today): array {
                $asked = $requirement->created_at->copy()->timezone(BusinessDays::TIMEZONE)->startOfDay();
                $days = (int) $asked->diffInDays($today);

                return [
                    'id' => 'requirement-'.$requirement->id,
                    'title' => "{$requirement->title} · {$requirement->area_name}",
                    'detail' => "{$requirement->recipient_label} · pedido ".match (true) {
                        $days <= 0 => 'hoy',
                        $days === 1 => 'hace 1 día',
                        default => "hace {$days} días",
                    },
                    'href_route' => 'ai.requirements.show',
                    'href_params' => ['requirement' => $requirement->id],
                    'severity' => $requirement->displayStatus($today) === AiRequirement::OVERDUE ? 'late' : 'attention',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * `ApprovalItem` rows for holders of `ai.projects.approve`: what waits
     * for their decision (App\Domain\Ai\ApprovalRounds). `cost_cop` is the
     * estimate total (0 while unknown or without `ai.costs.view`).
     *
     * @return list<array{key: string, title: string, estimate_hours: int|float, cost_cop: int, due_date: string}>
     */
    private function pendingApprovals(User $user): array
    {
        return $this->rounds->pendingFor($user)
            ->map(function (AiRequest $request) use ($user): array {
                $summary = $this->approvals->summary($request, $user);

                return [
                    'key' => $summary['key'],
                    'title' => $summary['title'],
                    'estimate_hours' => $summary['hours_total'],
                    'cost_cop' => (int) ($summary['total_cost'] ?? 0),
                    'due_date' => $summary['due_date'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, kind: 'web'|'local'}>
     */
    private function monitoredApps(): array
    {
        return MonitoredApp::query()
            ->orderBy('id')
            ->get(['name', 'type'])
            ->map(fn (MonitoredApp $app) => [
                'name' => $app->name,
                'kind' => $app->type === MonitoredApp::TYPE_LOCAL ? MonitoredApp::TYPE_LOCAL : MonitoredApp::TYPE_WEB,
            ])
            ->values()
            ->all();
    }
}
