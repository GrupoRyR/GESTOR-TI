<?php

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\Unanswered;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeItem;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Support\Assistant\AssistantTabCounts;
use App\Support\Assistant\Presenters\CurationPresenter;
use App\Support\Assistant\Presenters\KnowledgePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Preguntas sin respuesta" (PROMPT 6.8.1, `Assistant/Unanswered/Index`,
 * types/pages/assistant-curation.ts).
 *
 * - `index`: `assistant.use` or `assistant.knowledge.manage`; without the
 *   latter the page renders NoPermission (empty keys). Open questions,
 *   most asked first (`?orden=recientes`: most recent first).
 * - "Documentar" opens `assistant.knowledge.create?pregunta=`; storing that
 *   card marks the question documented (KnowledgeController).
 * - link ("Ya está documentada") and discard: route middleware
 *   `can:assistant.knowledge.manage`.
 */
class UnansweredController extends Controller
{
    public function __construct(
        private readonly Unanswered $unanswered,
        private readonly AssistantTabCounts $tabs,
    ) {}

    public function index(Request $http): Response
    {
        $user = $this->user($http);
        abort_unless($user->can(KnowledgeItem::USE_PERMISSION) || KnowledgeItem::canManage($user), 403);

        $manage = KnowledgeItem::canManage($user);
        $order = $http->query('orden') === 'recientes' ? 'recent' : 'times';

        $questions = $manage
            ? UnansweredQuestion::query()->open()->with('asks.user.employee')
                ->when($order === 'times', fn ($query) => $query->orderByDesc('times_asked'))
                ->orderByDesc('last_asked_at')->orderByDesc('id')
                ->get()
            : collect();

        $cards = $manage
            ? KnowledgeItem::query()->visibleTo($user)->orderBy('title')->get(['id', 'key', 'type', 'title', 'visibility'])
            : collect();

        return Inertia::render('Assistant/Unanswered/Index', [
            'questions' => $questions->map(fn (UnansweredQuestion $question) => CurationPresenter::question($question))->values()->all(),
            'order' => $order,
            'card_options' => $cards->map(fn (KnowledgeItem $card) => KnowledgePresenter::ref($card))->values()->all(),
            'now' => now()->toIso8601String(),
            ...$this->tabs->for($user),
        ]);
    }

    public function link(Request $http, UnansweredQuestion $question): RedirectResponse
    {
        $user = $this->user($http);
        if (! $question->isOpen()) {
            return $this->closed();
        }

        $key = $http->validate(['card' => ['required', 'string', 'max:20']], ['card.*' => 'Elige una ficha de la lista.'])['card'];
        $card = KnowledgeItem::query()->visibleTo($user)->where('key', $key)->first();
        if ($card === null) {
            throw ValidationException::withMessages(['card' => 'Elige una ficha de la lista.']);
        }

        $this->unanswered->document($question, $card, $user);

        return redirect()->route('assistant.unanswered.index')->with('success', "La pregunta quedó enlazada a {$card->key}.");
    }

    public function discard(Request $http, UnansweredQuestion $question): RedirectResponse
    {
        if (! $question->isOpen()) {
            return $this->closed();
        }

        $this->unanswered->dismiss($question, $this->user($http));

        return redirect()->route('assistant.unanswered.index')->with('success', 'Pregunta descartada.');
    }

    private function closed(): RedirectResponse
    {
        return redirect()->route('assistant.unanswered.index')->with('error', 'Esta pregunta ya no está pendiente.');
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
