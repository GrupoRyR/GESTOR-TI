<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\Brief\BriefInputs;
use App\Domain\Ai\DuplicateFinder;
use App\Domain\Ai\FirstResponse;
use App\Domain\Ai\Roadmap;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\AiRequestComment;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Ai\AiSuggestionAvailability;
use App\Support\Ai\Presenters\ItDetailPresenter;
use App\Support\Ai\Presenters\RequesterDetailPresenter;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\Presenters\SuggestionPresenter;
use App\Support\Ai\Presenters\TriagePresenter;
use App\Support\Ai\RequesterCopy;
use App\Support\Paginated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

    /** Possible duplicates shown on the IT view. */
    private const DUPLICATES = 3;

    public function __construct(
        private readonly Roadmap $roadmap,
        private readonly DuplicateFinder $duplicates,
        private readonly TriagePresenter $triage,
        private readonly FirstResponse $firstResponse,
        private readonly BriefInputs $briefs,
        private readonly SuggestionPresenter $suggestions,
        private readonly AiSuggestionAvailability $ai,
    ) {}

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

    public function show(Request $http, AiRequest $request): Response
    {
        /** @var User $user */
        $user = $http->user();

        if (Gate::allows('viewInternal', $request)) {
            return $this->itView($http, $request, $user);
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
     * `ItShowProps`: full detail with internal notes, evaluation, possible
     * duplicates (the first one is `similar_to`) and the action data.
     * `merge_candidates` only on its partial reload (`{ q }`). Stage D6:
     * `suggestions` (null unless the AI is ready), `ai_availability`,
     * `ai_usage` and `merge_into`; the requester view never has them.
     */
    private function itView(Request $http, AiRequest $request, User $user): Response
    {
        $duplicates = $request->status->isFinished() ? new EloquentCollection : $this->duplicates->similar(
            $request->title.' '.$request->problem,
            (int) $request->company_id,
            self::DUPLICATES,
            $request->id,
        );
        $duplicates->load(RequestPresenter::RELATIONS);
        $withCosts = $user->can('ai.costs.view');
        $due = $request->first_response_due_at ?? $request->created_at;

        return Inertia::render('Ai/Requests/Show', [
            ...$this->triage->actionData($user),
            'view' => 'it',
            'request' => ItDetailPresenter::detail($request, $user, $this->roadmap->rank($request), $duplicates->first()),
            'evaluation' => ItDetailPresenter::evaluation($request, $user),
            'duplicates' => $duplicates->map(fn (AiRequest $duplicate) => RequestPresenter::summary($duplicate))->values()->all(),
            'area_hour_value' => $withCosts ? $request->area?->hour_value : null,
            'first_response' => [
                'due_at' => $due->toIso8601String(),
                'answered_at' => $request->first_responded_at?->toIso8601String(),
            ],
            'first_response_business_days' => $this->firstResponse->businessDays(),
            'brief' => $brief = $this->briefs->forRequest($request, $user),
            'brief_state' => $brief === null ? null : BriefInputs::state($http),
            // Stage D6: closure so the page reloads `only: ['suggestions']` while generating.
            'suggestions' => fn () => $this->suggestions->forRequest($request, $user),
            'ai_availability' => $this->ai->for($user),
            'ai_usage' => $this->ai->usage($user),
            'merge_into' => $this->mergeInto($http, $request),
            'now' => now()->toIso8601String(),
            'merge_candidates' => Inertia::optional(fn () => InboxController::mergeCandidates((string) $http->query('q', ''), $request)),
        ]);
    }

    /**
     * `?unir=GIA-121` from Compare: that request preselected in MergeDialog,
     * only when it is another open request in the viewer's company scope.
     */
    private function mergeInto(Request $http, AiRequest $request): ?string
    {
        $key = strtoupper(trim((string) $http->query('unir', '')));

        if ($key === '' || $key === $request->key) {
            return null;
        }

        $other = AiRequest::query()->where('key', $key)->first();

        return $other === null || $other->status->isFinished() || $other->merged_into_id !== null ? null : $other->key;
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
