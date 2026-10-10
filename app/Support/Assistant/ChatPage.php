<?php

namespace App\Support\Assistant;

use App\Models\AssistantConversation;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\Ai\AiCostCap;
use App\Services\Ai\AiRuntime;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Assistant\Presenters\ChatPresenter;
use App\Support\Assistant\Presenters\KnowledgePresenter;
use App\Support\Integrations\AiProviderSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Props of `Assistant/Chat` (types/pages/assistant-chat.ts). Every prop is
 * lazy, so partial reloads (`selected_source`, `revealed_credential`,
 * `conversation`) compute only what they ask for. `revealed_credential`
 * is filled only by the reveal response.
 */
final class ChatPage
{
    private const CONVERSATIONS_LIMIT = 50;

    public function __construct(
        private readonly AiRuntime $runtime,
        private readonly AiProviderSettings $settings,
        private readonly AssistantTabCounts $tabs,
    ) {}

    /**
     * @param  array{message_id: int, value: string, expires_in_seconds: int}|null  $revealed
     */
    public function render(Request $http, User $user, ?AssistantConversation $conversation, ?array $revealed = null): Response
    {
        $canUse = $user->can(KnowledgeItem::USE_PERMISSION);

        return Inertia::render('Assistant/Chat', [
            'status' => fn () => $this->status($conversation),
            'monthly_cap' => fn () => ($cap = $this->settings->monthlyCap()) === null ? null : AiCostCap::usd($cap),
            'conversations' => fn () => $canUse ? $this->conversations($user) : [],
            'conversation' => fn () => $conversation === null ? null : ChatPresenter::conversation($conversation, $user),
            'selected_source' => fn () => $this->source($http, $user),
            'revealed_credential' => $revealed,
            'first_open_suggestions' => fn () => array_values((array) config('gestor.assistant.suggestions', [])),
            'unanswered_count' => fn () => $this->tabs->for($user)['unanswered_count'],
            'drafts_count' => fn () => $this->tabs->for($user)['drafts_count'],
            'can_configure_ai' => fn () => $user->can('settings.manage'),
            'access_holders' => fn () => $canUse ? [] : $this->holders(),
            'now' => fn () => now()->toIso8601String(),
        ]);
    }

    /**
     * Off states first; then `unavailable` while the open conversation ends
     * with a provider error ("Reintentar").
     */
    private function status(?AssistantConversation $conversation): string
    {
        $status = $this->runtime->status();
        if ($status !== 'ready') {
            return $status;
        }

        $last = $conversation?->messages()->latest('id')->first();

        return $last !== null && $last->kind === 'error' ? 'unavailable' : 'ready';
    }

    /**
     * @return list<array{id: int, title: string, last_message_at: string}>
     */
    private function conversations(User $user): array
    {
        return AssistantConversation::query()->ownedBy($user)->where('channel', 'app')
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->limit(self::CONVERSATIONS_LIMIT)
            ->get()
            ->map(fn (AssistantConversation $conversation) => ChatPresenter::summary($conversation))
            ->values()
            ->all();
    }

    /**
     * `?fuente=K-031`: a card the person may see, with its description.
     *
     * @return array<string, mixed>|null
     */
    private function source(Request $http, User $user): ?array
    {
        $key = $http->query('fuente');
        if (! is_string($key) || ! $user->can(KnowledgeItem::USE_PERMISSION)) {
            return null;
        }

        $card = KnowledgeItem::query()->visibleTo($user)->with(KnowledgePresenter::RELATIONS)->where('key', mb_strtoupper(trim($key)))->first();

        return $card === null ? null : [
            ...KnowledgePresenter::summary($card, now()->toDateString()),
            'description' => $card->description,
        ];
    }

    /**
     * Holders of `assistant.use` (no-access state).
     *
     * @return list<array<string, mixed>>
     */
    private function holders(): array
    {
        try {
            $users = User::permission(KnowledgeItem::USE_PERMISSION)->where('active', true)->with('employee')->orderBy('name')->get();
        } catch (PermissionDoesNotExist) {
            return [];
        }

        return $users->map(fn (User $holder) => RequestPresenter::person($holder))->values()->all();
    }
}
