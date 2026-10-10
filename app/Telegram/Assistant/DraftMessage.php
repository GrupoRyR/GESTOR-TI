<?php

namespace App\Telegram\Assistant;

use App\Domain\Assistant\Import\ImportFields;
use App\Domain\Assistant\KnowledgeTypes;
use App\Models\Area;
use App\Models\Company;
use App\Models\KnowledgeDraft;
use App\Telegram\Handlers\LearnCallbackHandler;
use App\Telegram\SignedCallback;
use App\Telegram\TelegramHtml;
use Illuminate\Support\Str;

/**
 * The `/aprender` draft as a Telegram message (telegram-format.md §6):
 *
 *     <b>Borrador de ficha · pendiente de aprobar</b>
 *     <b>Conectarse a la VPN de Derivados</b>
 *     <i>Procedimiento · Derivados · TI · Solo TI</i>
 *     • Pasos numerados: …
 *
 * Bullets are the proposed type fields (else the text). Buttons: Aprobar,
 * Descartar (signed callbacks bound to the chat) and "Editar en el Gestor
 * TI" (drafts page with `?borrador=&desde=telegram`). After a decision the
 * same text is shown with one more line and no buttons.
 */
final class DraftMessage
{
    private const VISIBILITY_LABELS = ['it' => 'Solo TI', 'company' => 'Toda la empresa'];

    public static function text(KnowledgeDraft $draft, ?string $footer = null): string
    {
        $proposed = $draft->proposed;
        $type = (string) ($proposed['type'] ?? '');
        $title = trim((string) ($proposed['title'] ?? ''));

        $meta = array_filter([
            ImportFields::TYPE_LABELS[$type] ?? null,
            self::companies((array) ($proposed['company_ids'] ?? [])),
            isset($proposed['area_id']) ? Area::withoutGlobalScopes()->whereKey((int) $proposed['area_id'])->value('name') : null,
            self::VISIBILITY_LABELS[$proposed['visibility'] ?? 'it'] ?? null,
        ], fn ($part) => filled($part));

        $lines = [
            '<b>Borrador de ficha · pendiente de aprobar</b>',
            TelegramHtml::bold($title === '' ? 'Sin título' : $title),
        ];
        if ($meta !== []) {
            $lines[] = TelegramHtml::italic(implode(' · ', $meta));
        }

        $bullets = [];
        if (in_array($type, KnowledgeTypes::TYPES, true)) {
            foreach (KnowledgeTypes::normalize($type, (array) ($proposed['fields'] ?? [])) as $key => $value) {
                $text = is_array($value) ? implode('; ', $value) : (string) $value;
                if (trim($text) !== '' && ! KnowledgeTypes::isPlaceholder($text)) {
                    $bullets[] = '• '.TelegramHtml::escape(KnowledgeTypes::label($type, $key).': '.Str::limit($text, 200));
                }
            }
        }
        if ($bullets === [] && filled($proposed['description'] ?? null)) {
            $bullets[] = '• '.TelegramHtml::escape(Str::limit((string) $proposed['description'], 300));
        }

        $lines = [...$lines, ...$bullets];
        if ($footer !== null) {
            $lines[] = TelegramHtml::escape($footer);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<list<array<string, string>>>
     */
    public static function keyboard(KnowledgeDraft $draft, string $chatId): array
    {
        return [
            [['text' => 'Aprobar', 'callback_data' => SignedCallback::make(LearnCallbackHandler::PREFIX, "ok:{$draft->id}", $chatId)]],
            [['text' => 'Descartar', 'callback_data' => SignedCallback::make(LearnCallbackHandler::PREFIX, "no:{$draft->id}", $chatId)]],
            [['text' => 'Editar en el Gestor TI', 'url' => route('assistant.drafts.index', ['borrador' => $draft->id, 'desde' => 'telegram'])]],
        ];
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private static function companies(array $ids): ?string
    {
        $names = Company::query()
            ->whereIn('id', array_map('intval', $ids))
            ->orderBy('name')
            ->get(['id', 'name', 'short_name'])
            ->map(fn (Company $company) => $company->short_name ?: $company->name);

        return $names->isEmpty() ? null : $names->implode(', ');
    }
}
