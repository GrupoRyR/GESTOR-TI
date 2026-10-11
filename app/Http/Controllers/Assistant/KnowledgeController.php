<?php

namespace App\Http\Controllers\Assistant;

use App\Actions\Assistant\DraftDecisions;
use App\Actions\Assistant\KnowledgeItems;
use App\Domain\Assistant\KnowledgeFilters;
use App\Domain\Assistant\KnowledgeSearch;
use App\Domain\Assistant\KnowledgeTypes;
use App\Domain\Assistant\Unanswered;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\KnowledgeItemRequest;
use App\Models\AiProduct;
use App\Models\Area;
use App\Models\Company;
use App\Models\KnowledgeDraft;
use App\Models\KnowledgeItem;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Assistant\AssistantTabCounts;
use App\Support\Assistant\Presenters\CurationPresenter;
use App\Support\Assistant\Presenters\KnowledgePresenter;
use App\Support\Paginated;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Base de conocimiento del Asistente TI (PROMPT 6.8.1,
 * types/pages/assistant-knowledge.ts).
 *
 * - `Assistant/Knowledge/Index` and `Show`: `assistant.use` (read-only) or
 *   `assistant.knowledge.manage` (KnowledgeItemPolicy::viewAny). Only cards
 *   in `KnowledgeItem::visibleTo()`; any other card answers 404.
 * - `Create`, `Edit`, store, update, destroy and review: route middleware
 *   `can:assistant.knowledge.manage`.
 * - `{card}` is the key (`K-031`).
 * - `Create` with `?borrador=` / `?pregunta=` sends `prefill`; the store
 *   then approves that draft or documents that question.
 */
class KnowledgeController extends Controller
{
    private const PER_PAGE = 8;

    public function __construct(
        private readonly KnowledgeItems $items,
        private readonly AssistantTabCounts $tabs,
    ) {}

    public function index(Request $http, KnowledgeSearch $search): Response
    {
        $user = $this->user($http);
        Gate::authorize('viewAny', KnowledgeItem::class);

        $today = $this->today();
        $filters = KnowledgeFilters::fromQuery($http->query(), KnowledgeItem::canManage($user));
        $visible = KnowledgeItem::query()->visibleTo($user);

        $query = (clone $visible)->with(KnowledgePresenter::RELATIONS);
        $filters->apply($query, $today);
        if ($filters->q !== null) {
            $search->constrain($query, $user, $filters->q);
        }

        $page = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Assistant/Knowledge/Index', [
            'cards' => Paginated::from($page, fn (KnowledgeItem $item) => KnowledgePresenter::summary($item, $today)),
            'filters' => $filters->toArray(),
            'counts' => $this->counts($visible, $today),
            'area_options' => $this->areasWithCards($visible),
            'type_examples' => $this->typeExamples($visible),
            'today' => $today,
            ...$this->tabs->for($user),
        ]);
    }

    public function show(Request $http, KnowledgeItem $card): Response
    {
        $user = $this->user($http);
        Gate::authorize('viewAny', KnowledgeItem::class);
        abort_unless($card->isVisibleTo($user), 404);

        $today = $this->today();
        $card->loadMissing([...KnowledgePresenter::RELATIONS, 'lastReviewedBy.employee']);

        return Inertia::render('Assistant/Knowledge/Show', [
            'card' => KnowledgePresenter::card($card, $user, $today),
            'today' => $today,
            'now' => now()->toIso8601String(),
            ...$this->tabs->for($user),
        ]);
    }

    public function create(Request $http): Response
    {
        $user = $this->user($http);

        return Inertia::render('Assistant/Knowledge/Create', [
            ...$this->formProps($user, null),
            'prefill' => $this->prefill($http),
        ]);
    }

    /**
     * A card from "Documentar" (`unanswered_id`: the question becomes
     * documented) or "Editar antes de aprobar" (`draft_id`: the draft is
     * approved with this card, source `learn`).
     */
    public function store(KnowledgeItemRequest $http, DraftDecisions $drafts, Unanswered $unanswered): RedirectResponse
    {
        $user = $this->user($http);
        $origin = $http->origin();
        $draft = $origin['draft_id'] !== null ? KnowledgeDraft::query()->pending()->find($origin['draft_id']) : null;
        $question = $origin['unanswered_id'] !== null ? UnansweredQuestion::query()->open()->find($origin['unanswered_id']) : null;

        $item = DB::transaction(function () use ($user, $http, $draft, $question, $drafts, $unanswered): KnowledgeItem {
            $item = $this->items->create($user, $http->payload(), $draft !== null ? 'learn' : 'manual');

            if ($draft !== null) {
                $drafts->approvedWith($draft, $item, $user);
            }
            if ($question !== null) {
                $unanswered->document($question, $item, $user);
            }

            return $item;
        });

        return redirect()->route('assistant.knowledge.show', $item)->with('success', "Ficha {$item->key} creada.");
    }

    public function edit(Request $http, KnowledgeItem $card): Response
    {
        $user = $this->user($http);
        abort_unless($card->isVisibleTo($user), 404);

        $today = $this->today();
        $card->loadMissing([...KnowledgePresenter::RELATIONS, 'lastReviewedBy.employee']);

        return Inertia::render('Assistant/Knowledge/Edit', [
            ...$this->formProps($user, $card),
            'card' => KnowledgePresenter::card($card, $user, $today),
        ]);
    }

    public function update(KnowledgeItemRequest $http, KnowledgeItem $card): RedirectResponse
    {
        abort_unless($card->isVisibleTo($this->user($http)), 404);

        $this->items->update($this->user($http), $card, $http->payload());

        return redirect()->route('assistant.knowledge.show', $card)->with('success', 'Cambios guardados.');
    }

    public function destroy(Request $http, KnowledgeItem $card): RedirectResponse
    {
        $user = $this->user($http);
        abort_unless($card->isVisibleTo($user), 404);

        $this->items->delete($user, $card);

        return redirect()->route('assistant.knowledge.index')->with('success', "Eliminaste la ficha {$card->key}.");
    }

    public function review(Request $http, KnowledgeItem $card): RedirectResponse
    {
        $user = $this->user($http);
        abort_unless($card->isVisibleTo($user), 404);

        $this->items->review($user, $card);

        return redirect()->route('assistant.knowledge.show', $card)->with('success', "Marcaste {$card->key} como revisada.");
    }

    /**
     * `KnowledgePrefill` of `?borrador=` (a pending draft: its proposed
     * values) or `?pregunta=` (an open question: the title); null otherwise.
     *
     * @return array{origin: array<string, mixed>, values: array<string, mixed>}|null
     */
    private function prefill(Request $http): ?array
    {
        $draftId = (int) $http->query('borrador', '0');
        $draft = $draftId > 0 ? KnowledgeDraft::query()->pending()->with('requestedBy.employee')->find($draftId) : null;

        if ($draft !== null) {
            $proposed = $draft->proposed;
            $type = (string) $proposed['type'];

            return [
                'origin' => [
                    'kind' => 'draft',
                    'draft_id' => $draft->id,
                    'author' => CurationPresenter::person($draft->requestedBy),
                    'channel' => $draft->source,
                    'message' => $draft->message,
                ],
                'values' => [
                    'type' => $type,
                    'title' => (string) $proposed['title'],
                    'description' => $proposed['description'] ?? null,
                    'fields' => KnowledgeTypes::normalize($type, (array) ($proposed['fields'] ?? [])),
                    'company_ids' => array_values(array_map('intval', (array) ($proposed['company_ids'] ?? []))),
                    'area_id' => isset($proposed['area_id']) ? (int) $proposed['area_id'] : null,
                    'owner_id' => isset($proposed['owner_id']) ? (int) $proposed['owner_id'] : null,
                    'visibility' => (string) $proposed['visibility'],
                    'review_at' => (string) $proposed['review_at'],
                ],
            ];
        }

        $questionId = (int) $http->query('pregunta', '0');
        $question = $questionId > 0 ? UnansweredQuestion::query()->open()->with('asks.user.employee')->find($questionId) : null;

        if ($question !== null) {
            $title = (string) preg_replace('/^[\s¿¡]+|[\s?!]+$/u', '', $question->question);

            return [
                'origin' => ['kind' => 'unanswered', 'question' => CurationPresenter::question($question)],
                'values' => ['title' => mb_substr($title, 0, 160)],
            ];
        }

        return null;
    }

    /**
     * Props shared by Create and Edit (`KnowledgeFormProps`).
     *
     * @return array<string, mixed>
     */
    private function formProps(User $user, ?KnowledgeItem $except): array
    {
        $visible = KnowledgeItem::query()->visibleTo($user);
        $cards = (clone $visible)
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except->id))
            ->orderBy('title')
            ->get(['id', 'key', 'type', 'title', 'visibility']);
        $products = AiProduct::query()->visibleTo($user)->orderBy('name')->get();

        return [
            'area_options' => Area::withoutGlobalScopes()
                ->where('is_active', true)
                ->whereIn('company_id', $this->accessibleCompanyIds($user))
                ->orderBy('name')->orderBy('company_id')
                ->get()
                ->map(fn (Area $area) => KnowledgePresenter::area($area))
                ->values()->all(),
            'owner_options' => User::query()->where('active', true)->with('employee')->orderBy('name')->get()
                ->map(fn (User $owner) => RequestPresenter::person($owner))
                ->values()->all(),
            'related_options' => [
                ...$cards->map(fn (KnowledgeItem $card) => ['kind' => 'card', 'card' => KnowledgePresenter::ref($card)])->all(),
                ...$products->map(fn (AiProduct $product) => ['kind' => 'product', 'product' => RequestPresenter::productRef($product)])->all(),
            ],
            'knowledge_managers' => $this->managers(),
            'type_examples' => $this->typeExamples($visible),
            'type_counts' => $this->counts($visible, $this->today())['by_type'],
            'today' => $this->today(),
            ...$this->tabs->for($user),
        ];
    }

    /**
     * `KnowledgeCounts` of the cards the person may see (no filters).
     *
     * @param  Builder<KnowledgeItem>  $visible
     * @return array{total: int, by_type: array<string, int>, by_visibility: array<string, int>, overdue: int}
     */
    private function counts(Builder $visible, string $today): array
    {
        $rows = (clone $visible)->get(['id', 'type', 'visibility', 'review_at']);
        $byType = array_fill_keys(KnowledgeTypes::TYPES, 0);
        $byVisibility = array_fill_keys(KnowledgeTypes::VISIBILITIES, 0);
        $overdue = 0;

        foreach ($rows as $row) {
            $byType[$row->type] = ($byType[$row->type] ?? 0) + 1;
            $byVisibility[$row->visibility] = ($byVisibility[$row->visibility] ?? 0) + 1;
            $overdue += $row->review_at->toDateString() < $today ? 1 : 0;
        }

        return ['total' => $rows->count(), 'by_type' => $byType, 'by_visibility' => $byVisibility, 'overdue' => $overdue];
    }

    /**
     * Areas that have visible cards (Área filter).
     *
     * @param  Builder<KnowledgeItem>  $visible
     * @return list<array{id: int, name: string, company_id: int}>
     */
    private function areasWithCards(Builder $visible): array
    {
        $ids = (clone $visible)->whereNotNull('area_id')->distinct()->pluck('area_id')->all();

        return Area::withoutGlobalScopes()->whereKey($ids)->orderBy('name')->orderBy('company_id')->get()
            ->map(fn (Area $area) => KnowledgePresenter::area($area))
            ->values()->all();
    }

    /**
     * The last edited visible card of each type, or null.
     *
     * @param  Builder<KnowledgeItem>  $visible
     * @return array<string, array<string, string>|null>
     */
    private function typeExamples(Builder $visible): array
    {
        $examples = [];

        foreach (KnowledgeTypes::TYPES as $type) {
            $card = (clone $visible)->where('type', $type)->orderByDesc('updated_at')->orderByDesc('id')->first(['id', 'key', 'type', 'title', 'visibility']);
            $examples[$type] = $card !== null ? KnowledgePresenter::ref($card) : null;
        }

        return $examples;
    }

    /**
     * Active holders of `assistant.knowledge.manage` ("Solo TI" explanation).
     *
     * @return list<array<string, mixed>>
     */
    private function managers(): array
    {
        try {
            $users = User::permission(KnowledgeItem::MANAGE_PERMISSION)->where('active', true)->with('employee')->orderBy('name')->get();
        } catch (PermissionDoesNotExist) {
            return [];
        }

        return $users->map(fn (User $manager) => RequestPresenter::person($manager))->values()->all();
    }

    /**
     * Companies whose areas the form offers: every active company with
     * `companies.view_all`, else the person's own.
     *
     * @return list<int>
     */
    private function accessibleCompanyIds(User $user): array
    {
        $query = Company::query()->active();

        if (! app(CurrentCompany::class)->canViewAll($user)) {
            $query->whereKey($user->company_id ?? 0);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Bogotá calendar day (`APP_TIMEZONE`), `YYYY-MM-DD`.
     */
    private function today(): string
    {
        return now()->toDateString();
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
