<?php

namespace App\Http\Controllers;

use App\Domain\Ai\Roadmap;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\MonitoredApp;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Ai\RequesterCopy;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inicio (`Pages/Home.tsx`, props `HomeProps` of resources/js/types/pages/home.ts).
 *
 * Two views: `it` for users with `ai.manage`, `requester` for everybody
 * else. The requester view lists the person's open requests (B3); the IT
 * approvals, attention items and credentials arrive in B4, so they are
 * still empty; monitoring lists the registered apps as placeholders until
 * the module is enabled.
 */
class HomeController extends Controller
{
    /** Requests shown on the requester home (the rest live in Mis solicitudes). */
    private const REQUESTER_LIMIT = 10;

    public function __construct(private readonly Roadmap $roadmap) {}

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
                'unanswered_requests' => [],
                'overdue_tasks' => [],
                'waiting_requirements' => [],
                // Only filled for `ai.vault.access` once the vault exists (Stage B).
                'expiring_credentials' => [],
            ],
            'pending_approvals' => [],
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
