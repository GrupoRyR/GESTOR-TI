<?php

namespace App\Http\Controllers\Assistant;

use App\Actions\Assistant\DraftDecisions;
use App\Domain\Assistant\KnowledgeTypes;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeDraft;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Support\Assistant\AssistantTabCounts;
use App\Support\Assistant\Presenters\CurationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Borradores de /aprender" (PROMPT 6.8.1, `Assistant/Drafts/Index`,
 * types/pages/assistant-curation.ts).
 *
 * - `index`: `assistant.use` or `assistant.knowledge.manage`; without the
 *   latter the page renders NoPermission (no drafts leave the server).
 *   Pending drafts, newest first; `?borrador=` selects one (`requested`),
 *   `&desde=telegram` comes from the bot button.
 * - approve / merge / discard: route middleware `can:assistant.knowledge.manage`
 *   (App\Actions\Assistant\DraftDecisions). A decided draft answers with a
 *   flash error (it may have been decided from Telegram meanwhile).
 * - Drafts are not split by company: the managers are global today
 *   (Jhon and Leonardo); approving drops companies the approver cannot act on.
 */
class DraftController extends Controller
{
    public function __construct(
        private readonly DraftDecisions $decisions,
        private readonly AssistantTabCounts $tabs,
    ) {}

    public function index(Request $http, CurationPresenter $presenter): Response
    {
        $user = $this->user($http);
        abort_unless($user->can(KnowledgeItem::USE_PERMISSION) || KnowledgeItem::canManage($user), 403);

        $drafts = KnowledgeItem::canManage($user)
            ? KnowledgeDraft::query()->pending()->with('requestedBy.employee')->latest('created_at')->latest('id')->get()
            : collect();

        $wanted = (int) $http->query('borrador', '0');
        $requested = $drafts->firstWhere('id', $wanted);
        $selected = $requested ?? $drafts->first();
        $shown = $drafts->map(fn (KnowledgeDraft $draft) => $presenter->draft($draft, $user))->values();

        return Inertia::render('Assistant/Drafts/Index', [
            'drafts' => $shown->all(),
            'selected' => $selected !== null ? $shown->firstWhere('id', $selected->id) : null,
            'requested' => $requested !== null,
            'opened_from' => $http->query('desde') === 'telegram' ? 'telegram' : null,
            'now' => now()->toIso8601String(),
            ...$this->tabs->for($user),
        ]);
    }

    public function approve(Request $http, KnowledgeDraft $draft): RedirectResponse
    {
        $user = $this->user($http);
        if (! $draft->isPending()) {
            return $this->decided();
        }

        $data = $http->validate([
            'visibility' => ['required', 'string', Rule::in(KnowledgeTypes::VISIBILITIES)],
            'review_at' => ['required', 'date_format:Y-m-d'],
        ], [
            'visibility.*' => 'Elige quién puede ver la ficha.',
            'review_at.*' => 'Escribe la fecha de revisión.',
        ]);

        $blocker = $this->decisions->blocker($draft, $user);
        if ($blocker !== null) {
            return redirect()->route('assistant.drafts.index', ['borrador' => $draft->id])->with('error', $blocker);
        }

        $card = $this->decisions->approve($draft, $user, (string) $data['visibility'], (string) $data['review_at']);

        return redirect()->route('assistant.drafts.index')->with('success', "Ficha {$card->key} publicada.");
    }

    public function merge(Request $http, KnowledgeDraft $draft, KnowledgeItem $card): RedirectResponse
    {
        $user = $this->user($http);
        abort_unless($card->isVisibleTo($user), 404);
        if (! $draft->isPending()) {
            return $this->decided();
        }

        $this->decisions->merge($draft, $card, $user);

        return redirect()->route('assistant.knowledge.show', $card)->with('success', "Agregaste lo nuevo a {$card->key}.");
    }

    public function discard(Request $http, KnowledgeDraft $draft): RedirectResponse
    {
        if (! $draft->isPending()) {
            return $this->decided();
        }

        $data = $http->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Escribe por qué lo descartas.', 'reason.*' => 'El motivo puede tener hasta 500 caracteres.'],
        );

        $this->decisions->discard($draft, $this->user($http), (string) $data['reason']);

        return redirect()->route('assistant.drafts.index')->with('success', 'Borrador descartado.');
    }

    private function decided(): RedirectResponse
    {
        return redirect()->route('assistant.drafts.index')->with('error', 'Este borrador ya se decidió.');
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
