<?php

namespace App\Telegram\Assistant;

use App\Domain\Assistant\AssistantEngine;
use App\Domain\Assistant\CredentialDelivery;
use App\Models\AiVaultItem;
use App\Models\AssistantMessage;
use App\Models\Employee;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Telegram\Handlers\CredentialCallbackHandler;
use App\Telegram\SignedCallback;
use App\Telegram\TelegramHtml;
use Illuminate\Support\Str;

/**
 * An assistant message as a Telegram HTML message + inline keyboard
 * (docs/design/telegram-format.md §1–§5). Credential notices are not
 * formatted here: App\Telegram\Assistant\CredentialSender sends them.
 *
 * - `answer`: the model text escaped, `**negrita**` as `<b>`, list lines as
 *   `• `; at most 4 paragraphs and 3,500 characters (cut after the last
 *   complete paragraph + "Ver respuesta completa en el Gestor TI"), then
 *   `<b>Fuentes:</b>` with one link per card (never cut).
 * - `no_answer`: fixed text; knowledge managers also get the
 *   "Ver preguntas sin respuesta" button.
 * - `error`: the same "no disponible" text for everyone.
 * - `person_choice`: one signed button per person (`cred:pick:…`).
 * - `vault_link`: fixed text + "Abrir en la bóveda" (the vault is app only).
 */
final class ReplyFormatter
{
    public const NOT_DOCUMENTED = 'No lo tengo documentado. Registré la pregunta para que TI la documente.';

    public const MAX_PARAGRAPHS = 4;

    public const MAX_CHARACTERS = 3500;

    /** Telegram's hard limit is 4,096; leave room for entities. */
    private const HARD_LIMIT = 4000;

    /**
     * @return array{text: string, keyboard: list<list<array<string, string>>>}
     */
    public function format(User $user, AssistantMessage $message, string $chatId): array
    {
        return match ($message->kind) {
            'answer' => ['text' => $this->answer($message), 'keyboard' => []],
            'no_answer' => [
                'text' => TelegramHtml::escape(self::NOT_DOCUMENTED),
                'keyboard' => KnowledgeItem::canManage($user)
                    ? [[['text' => 'Ver preguntas sin respuesta', 'url' => route('assistant.unanswered.index')]]]
                    : [],
            ],
            'error' => ['text' => TelegramHtml::escape(AssistantEngine::UNAVAILABLE), 'keyboard' => []],
            'person_choice' => $this->choice($message, $chatId),
            'vault_link' => $this->vault($message),
            default => ['text' => TelegramHtml::escape(trim((string) $message->body)), 'keyboard' => []],
        };
    }

    private function answer(AssistantMessage $message): string
    {
        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\n\s*\n/u', trim((string) $message->body)) ?: []),
            fn (string $paragraph) => $paragraph !== '',
        ));

        $sources = $this->sources((array) $message->data('sources', []));
        $more = TelegramHtml::link(route('assistant.index', ['conversation' => $message->conversation_id]), 'Ver respuesta completa en el Gestor TI');
        $budget = min(self::MAX_CHARACTERS, self::HARD_LIMIT - mb_strlen($sources) - mb_strlen($more) - 4);

        $kept = [];
        $length = 0;
        foreach ($paragraphs as $paragraph) {
            $html = self::paragraph($paragraph);
            $added = mb_strlen($html) + ($kept === [] ? 0 : 2);

            if (count($kept) === self::MAX_PARAGRAPHS || $length + $added > $budget) {
                break;
            }

            $kept[] = $html;
            $length += $added;
        }

        if ($kept === [] && $paragraphs !== []) {
            $kept[] = self::shortened($paragraphs[0], $budget);
        }

        $parts = $kept === [] ? [] : [implode("\n\n", $kept)];
        if (count($kept) < count($paragraphs) || ($paragraphs !== [] && $kept[0] !== self::paragraph($paragraphs[0]))) {
            $parts[] = $more;
        }
        if ($sources !== '') {
            $parts[] = $sources;
        }

        return implode("\n\n", $parts);
    }

    /**
     * One paragraph: escaped, `**bold**` → `<b>`, list lines → `• `,
     * Markdown headings without `#`.
     */
    public static function paragraph(string $paragraph): string
    {
        $lines = array_map(function (string $line): string {
            $line = rtrim($line);

            if (preg_match('/^\s*[-*•]\s+(.*)$/u', $line, $match) === 1) {
                return '• '.self::inline($match[1]);
            }

            return self::inline((string) preg_replace('/^\s*#+\s*/u', '', $line));
        }, preg_split('/\n/u', $paragraph) ?: []);

        return implode("\n", $lines);
    }

    private static function inline(string $text): string
    {
        return (string) preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', TelegramHtml::escape($text));
    }

    /**
     * A first paragraph longer than the whole budget: cut at a word.
     */
    private static function shortened(string $paragraph, int $budget): string
    {
        $limit = max(100, $budget - 50);

        do {
            $html = self::paragraph(Str::limit(str_replace('**', '', $paragraph), $limit, '…', preserveWords: true));
            $limit -= 200;
        } while (mb_strlen($html) > $budget && $limit > 100);

        return $html;
    }

    /**
     * @param  array<int, mixed>  $keys
     */
    private function sources(array $keys): string
    {
        $keys = array_values(array_filter($keys, 'is_string'));
        if ($keys === []) {
            return '';
        }

        $cards = KnowledgeItem::query()->whereIn('key', $keys)->get(['id', 'key', 'title'])->keyBy('key');
        $links = collect($keys)
            ->map(fn (string $key) => $cards->get($key))
            ->filter()
            ->map(fn (KnowledgeItem $card) => TelegramHtml::link(route('assistant.knowledge.show', ['card' => $card->key]), $card->title))
            ->values();

        return match ($links->count()) {
            0 => '',
            1 => '<b>Fuentes:</b> '.$links->first(),
            default => "<b>Fuentes:</b>\n".$links->implode("\n"),
        };
    }

    /**
     * @return array{text: string, keyboard: list<list<array<string, string>>>}
     */
    private function choice(AssistantMessage $message, string $chatId): array
    {
        $options = array_values((array) $message->data('options', []));
        $employees = Employee::query()->withoutGlobalScopes()
            ->with(['area' => fn ($query) => $query->withoutGlobalScopes(), 'company'])
            ->whereIn('id', array_map(fn ($option) => (int) ($option['employee_id'] ?? 0), $options))
            ->get()
            ->keyBy('id');

        $keyboard = [];
        foreach ($options as $index => $option) {
            $employee = $employees->get((int) ($option['employee_id'] ?? 0));
            if ($employee === null) {
                continue;
            }

            $keyboard[] = [[
                'text' => CredentialDelivery::personLine($employee),
                'callback_data' => SignedCallback::make(CredentialCallbackHandler::PREFIX, "pick:{$message->id}:{$index}", $chatId),
            ]];
        }

        return ['text' => TelegramHtml::escape((string) $message->body), 'keyboard' => $keyboard];
    }

    /**
     * @return array{text: string, keyboard: list<list<array<string, string>>>}
     */
    private function vault(AssistantMessage $message): array
    {
        $item = AiVaultItem::query()->find((int) $message->data('item_id'));

        if ($item === null) {
            return ['text' => TelegramHtml::escape(CredentialDelivery::REFUSED), 'keyboard' => []];
        }

        return [
            'text' => TelegramHtml::escape(CredentialDelivery::VAULT),
            'keyboard' => [[['text' => 'Abrir en la bóveda', 'url' => route('ai.vault.index', ['item' => $item->id])]]],
        ];
    }
}
