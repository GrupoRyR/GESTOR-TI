<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\RecordApprovalDecision;
use App\Actions\Ai\RemindPending;
use App\Domain\Ai\ApprovalRounds;
use App\Domain\Ai\WipLimit;
use App\Enums\Ai\ApprovalChannel;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use App\Http\Controllers\AI\Concerns\RedirectsTriageErrors;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\Ai\Presenters\ApprovalPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project approvals (PROMPT 6.4): "Esperan tu aprobación"
 * (`Ai/Approvals/Index`), one approval phone-first (`Ai/Approvals/Show`,
 * the Telegram "Ver detalle" lands here with `?desde=telegram`) and the
 * decision (`ai.approvals.approve|reject`, channel `web`).
 *
 * - Deciding needs `ai.projects.approve` (route) and `approveProject`
 *   (RecordApprovalDecision: pending, not the evaluator when Settings
 *   `ai.approval.require_other_if_evaluator` is on). Súper Admin does not
 *   inherit it.
 * - Index without the permission renders empty (`pending: []`, `selected:
 *   null`): the page shows NoPermission and no approval data leaves the
 *   server. Show without it answers 403 (its props are the approval).
 * - `ai.approvals.remind` (Mi semana) is an IT action (`ai.manage`).
 */
class ApprovalController extends Controller
{
    use RedirectsTriageErrors;

    public function __construct(
        private readonly ApprovalRounds $rounds,
        private readonly ApprovalPresenter $presenter,
        private readonly WipLimit $wip,
    ) {}

    public function index(Request $http): Response
    {
        $user = $this->user($http);
        $pending = $this->rounds->pendingFor($user);

        return Inertia::render('Ai/Approvals/Index', [
            'pending' => $pending->map(fn (AiRequest $request) => $this->presenter->summary($request, $user))->values()->all(),
            'selected' => fn () => $this->selected($http, $user, $pending->first()),
            'wip' => $this->wip->snapshot(),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function show(Request $http, AiRequest $request): Response
    {
        $user = $this->user($http);
        abort_unless($user->can('ai.projects.approve'), 403);
        abort_if(ApprovalRounds::current($request)->isEmpty(), 404);

        return Inertia::render('Ai/Approvals/Show', [
            'approval' => $this->presenter->detail($request, $user),
            'from_telegram' => $http->query('desde') === 'telegram',
            'wip' => $this->wip->snapshot(),
            'decision' => $this->rounds->decisionFor($request, $user),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function approve(Request $http, AiRequest $request, RecordApprovalDecision $decisions): RedirectResponse
    {
        $data = $http->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $decisions->handle($request, $this->user($http), ApprovalKind::Project, ApprovalDecision::Approved, $data['comment'] ?? null, ApprovalChannel::Web);

        return back()->with('success', "Aprobaste {$request->key}.");
    }

    public function reject(Request $http, AiRequest $request, RecordApprovalDecision $decisions): RedirectResponse
    {
        $data = $http->validate(
            ['comment' => ['required', 'string', 'max:2000']],
            ['comment.*' => 'Cuéntale al equipo por qué la rechazas.'],
        );

        $decisions->handle($request, $this->user($http), ApprovalKind::Project, ApprovalDecision::Rejected, $data['comment'], ApprovalChannel::Web);

        return back()->with('success', "Rechazaste {$request->key}.");
    }

    public function remind(Request $http, AiRequest $request, RemindPending $remind): RedirectResponse
    {
        return $this->attemptQuietly(function () use ($http, $request, $remind): string {
            $count = $remind->approvers($request, $this->user($http));

            return $count === 1 ? 'Recordatorio enviado al aprobador.' : "Recordatorio enviado a {$count} aprobadores.";
        });
    }

    /**
     * Detail of `?request=` (any request with a project round the viewer can
     * see) or the first pending one.
     *
     * @return array<string, mixed>|null
     */
    private function selected(Request $http, User $user, ?AiRequest $first): ?array
    {
        if (! $user->can('ai.projects.approve')) {
            return null;
        }

        $key = $http->query('request');
        $request = is_string($key) && $key !== '' ? AiRequest::query()->where('key', $key)->first() : null;

        if ($request !== null && ApprovalRounds::current($request)->isNotEmpty()) {
            return $this->presenter->detail($request, $user);
        }

        return $first === null ? null : $this->presenter->detail($first, $user);
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
