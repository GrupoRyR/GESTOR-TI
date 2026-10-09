<?php

namespace App\Http\Controllers;

use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inicio (`Pages/Home.tsx`, props `HomeProps` of resources/js/types/pages/home.ts).
 *
 * Two views: `it` for users with `ai.manage`, `requester` for everybody
 * else. Requests, approvals, attention items and credentials arrive in
 * Stage B, so Stage A sends them empty; monitoring lists the registered
 * apps as placeholders until the module is enabled.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $greeting = ['greeting_name' => Str::before(trim($user->name), ' ')];

        if (! $user->can('ai.manage')) {
            return Inertia::render('Home', [
                'view' => 'requester',
                ...$greeting,
                'requests' => [],
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
