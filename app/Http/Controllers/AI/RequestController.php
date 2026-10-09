<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\Roadmap;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\AiRequestComment;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Ai\Presenters\RequesterDetailPresenter;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\RequesterCopy;
use App\Support\Paginated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mis solicitudes" (`Ai/Requests/Mine`) and the request detail
 * (`Ai/Requests/Show`). The requester view (`view: 'requester'`) is for
 * everybody who can view the request without `ai.manage`: the requester,
 * voters, approvers and, from the roadmap, any module user of the company
 * (public information only). Holders of `ai.manage` get the IT view in B4;
 * until then they are sent back with the Stage B notice.
 */
class RequestController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(private readonly Roadmap $roadmap) {}

    public function mine(Request $http): Response
    {
        /** @var User $user */
        $user = $http->user();
        $filter = in_array($http->query('filter'), ['active', 'done', 'all'], true) ? (string) $http->query('filter') : 'active';
        $finished = RequestStatus::finishedValues();

        $base = fn (): Builder => AiRequest::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('requester_id', $user->id);

        $query = $base()
            ->when($filter === 'active', fn (Builder $query) => $query->whereNotIn('status', $finished))
            ->when($filter === 'done', fn (Builder $query) => $query->whereIn('status', $finished))
            // What waits for the requester first, then the latest activity.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [RequestStatus::NeedsInfo->value])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->with([...RequestPresenter::RELATIONS, 'ratings']);

        $page = $query->paginate(self::PER_PAGE)->withQueryString();
        $ranks = $this->roadmap->ranks($page->items());

        return Inertia::render('Ai/Requests/Mine', [
            'requests' => Paginated::from($page, fn (AiRequest $request) => $this->row($request, $user, $ranks[$request->id] ?? null)),
            'filter' => $filter,
            'counts' => [
                'active' => $base()->whereNotIn('status', $finished)->count(),
                'done' => $base()->whereIn('status', $finished)->count(),
                'all' => $base()->count(),
            ],
        ]);
    }

    public function show(Request $http, AiRequest $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $http->user();

        // TEMPORARY (B4): the IT view (`ItShowProps`) of ai-triage.ts.
        if (Gate::allows('viewInternal', $request)) {
            return redirect()->route('home')->with('error', StageBPlaceholderController::NOTICE);
        }

        Gate::authorize('view', $request);

        $rank = $this->roadmap->rank($request);

        return Inertia::render('Ai/Requests/Show', [
            'view' => 'requester',
            'request' => RequesterDetailPresenter::detail($request, $rank),
            'where' => RequesterCopy::where($request),
            'roadmap_position' => RequesterCopy::roadmapPosition($request, $rank),
            'following' => $request->followers()->whereKey($user->id)->exists(),
            'can_comment' => Gate::allows('comment', $request),
        ]);
    }

    /**
     * `MyRequestRow`.
     *
     * @return array<string, mixed>
     */
    private function row(AiRequest $request, User $user, ?int $rank): array
    {
        $canRate = $user->can('rate', $request);

        return [
            ...RequestPresenter::summary($request, $rank),
            'next_step' => RequesterCopy::nextStep($request, $rank, $canRate),
            'needs_reply' => $request->status === RequestStatus::NeedsInfo,
            'question' => $request->status === RequestStatus::NeedsInfo ? $this->question($request) : null,
            'can_rate' => $canRate,
        ];
    }

    /**
     * The open question while "Necesita información": TI's latest public
     * message, else the note of the move to that status.
     *
     * @return array{text: string, asked_by: string, asked_at: string}|null
     */
    private function question(AiRequest $request): ?array
    {
        $comment = $request->comments()
            ->where('visibility', CommentVisibility::Public->value)
            ->where('user_id', '!=', $request->requester_id)
            ->with('author')
            ->latest('id')
            ->first();

        if ($comment instanceof AiRequestComment) {
            return [
                'text' => $comment->body,
                'asked_by' => (string) $comment->author?->name,
                'asked_at' => $comment->created_at->toIso8601String(),
            ];
        }

        $change = $request->statusChanges()
            ->reorder()
            ->where('to_status', RequestStatus::NeedsInfo->value)
            ->whereNotNull('note')
            ->with('user')
            ->latest('id')
            ->first();

        return $change === null ? null : [
            'text' => (string) $change->note,
            'asked_by' => (string) ($change->user?->name ?? 'TI'),
            'asked_at' => ($change->created_at ?? now())->toIso8601String(),
        ];
    }
}
