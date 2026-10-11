<?php

namespace App\Support\Assistant\Presenters;

use App\Models\AiVaultItem;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Employee;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The chat as the shapes of types/assistant.ts: `ChatConversationSummary`,
 * `ChatConversation` and every `ChatMessage` kind. Sources are the cards
 * the viewer may still see (deleted or hidden ones are dropped). A
 * credential notice carries whose and which system, never the value.
 */
final class ChatPresenter
{
    private const ENVIRONMENT_LABELS = ['production' => 'producción', 'testing' => 'pruebas'];

    /**
     * @return array{id: int, title: string, last_message_at: string}
     */
    public static function summary(AssistantConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'last_message_at' => $conversation->last_activity_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function conversation(AssistantConversation $conversation, User $viewer): array
    {
        /** @var Collection<int, AssistantMessage> $messages */
        $messages = $conversation->messages()->orderBy('id')->get();

        $keys = $messages->where('kind', 'answer')->flatMap(fn (AssistantMessage $m) => (array) $m->data('sources', []))->unique()->values();
        $cards = $keys->isEmpty() ? collect() : KnowledgeItem::query()->visibleTo($viewer)->whereIn('key', $keys)->get(['id', 'key', 'type', 'title', 'visibility'])->keyBy('key');

        $employeeIds = $messages->flatMap(fn (AssistantMessage $m) => match ($m->kind) {
            'credential_notice' => [$m->data('employee_id')],
            'person_choice' => array_column((array) $m->data('options', []), 'employee_id'),
            default => [],
        })->filter()->unique()->values();
        $employees = $employeeIds->isEmpty() ? collect() : Employee::query()->withoutGlobalScopes()->with(['area' => fn ($query) => $query->withoutGlobalScopes(), 'company'])->whereIn('id', $employeeIds)->get()->keyBy('id');

        $itemIds = $messages->where('kind', 'vault_link')->map(fn (AssistantMessage $m) => $m->data('item_id'))->filter()->unique()->values();
        $items = $itemIds->isEmpty() ? collect() : AiVaultItem::withTrashed()->with('product:id,name')->whereIn('id', $itemIds)->get(['id', 'name', 'ai_product_id', 'environment'])->keyBy('id');

        return [
            ...self::summary($conversation),
            'started_at' => $conversation->created_at->toIso8601String(),
            'messages' => $messages
                ->map(fn (AssistantMessage $message) => self::message($message, $cards, $employees, $items))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<string, KnowledgeItem>  $cards
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, AiVaultItem>  $items
     * @return array<string, mixed>|null
     */
    private static function message(AssistantMessage $message, Collection $cards, Collection $employees, Collection $items): ?array
    {
        $base = ['id' => $message->id, 'at' => $message->created_at->toIso8601String(), 'role' => $message->role, 'kind' => $message->kind];
        $text = (string) $message->body;

        return match ($message->kind) {
            'text' => [...$base, 'text' => $text],
            'answer' => [
                ...$base,
                'text' => $text,
                'sources' => collect((array) $message->data('sources', []))
                    ->map(fn (string $key) => $cards->get($key))
                    ->filter()
                    ->map(fn (KnowledgeItem $card) => KnowledgePresenter::ref($card))
                    ->values()
                    ->all(),
                'suggestions' => array_values((array) $message->data('suggestions', [])),
            ],
            'no_answer' => [
                ...$base,
                'text' => $text,
                'explanation' => $message->data('explanation'),
                'times_asked' => (int) $message->data('times_asked', 1),
                'suggestions' => array_values((array) $message->data('suggestions', [])),
                'suggestions_label' => $message->data('suggestions_label'),
            ],
            'person_choice' => [
                ...$base,
                'text' => $text,
                'options' => collect((array) $message->data('options', []))
                    ->map(fn (array $option) => $employees->get($option['employee_id'] ?? 0))
                    ->filter()
                    ->map(fn (Employee $employee) => self::person($employee))
                    ->values()
                    ->all(),
                'resolved_option_id' => $message->data('resolved_option_id'),
            ],
            'credential_notice' => ($person = $employees->get((int) $message->data('employee_id'))) === null ? null : [
                ...$base,
                'credential_id' => (int) $message->data('access_id'),
                'system' => (string) $message->data('system'),
                'person' => self::person($person),
                'username' => (string) $message->data('username'),
                'reveal_seconds' => (int) config('gestor.assistant.credentials.reveal_seconds', 30),
            ],
            'vault_link' => ($item = $items->get((int) $message->data('item_id'))) === null ? [...$base, 'kind' => 'refused', 'text' => $text] : [
                ...$base,
                'text' => $text,
                'item' => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'product' => $item->product?->name ?? '',
                    'environment_label' => self::ENVIRONMENT_LABELS[$item->environment] ?? $item->environment,
                ],
            ],
            'refused' => [...$base, 'text' => $text],
            'error' => [...$base, 'text' => $text, 'retryable' => (bool) $message->data('retryable', false)],
            default => null,
        };
    }

    /**
     * `PersonOption`.
     *
     * @return array{id: int, name: string, job_title: string, area: string, company_id: int, company_name: string}
     */
    public static function person(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'job_title' => (string) $employee->job_title,
            'area' => (string) $employee->area?->name,
            'company_id' => (int) $employee->company_id,
            'company_name' => (string) ($employee->company?->short_name ?: $employee->company?->name),
        ];
    }
}
