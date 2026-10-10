<?php

namespace App\Domain\Assistant;

/**
 * Card types and their fields (types/assistant.ts `KnowledgeCardBody`,
 * resources/js/lib/knowledgeFields.ts `KNOWLEDGE_FIELDS`): the server copy of
 * the labels and of which fields are required. `steps` (procedure) is the
 * only list field; every other field is text.
 */
final class KnowledgeTypes
{
    public const TYPES = ['system', 'report', 'data_flow', 'procedure', 'glossary'];

    public const VISIBILITIES = ['it', 'company'];

    public const LIST_FIELD = 'steps';

    /** Months until the next review (PROMPT 6.8.1). */
    public const REVIEW_MONTHS = 6;

    /** Reviews due within this many days are "due soon" (lib/knowledge.ts). */
    public const REVIEW_SOON_DAYS = 30;

    /**
     * type => field => [label, required].
     *
     * @var array<string, array<string, array{0: string, 1: bool}>>
     */
    public const FIELDS = [
        'system' => [
            'what_is' => ['Qué es', true],
            'runs_on' => ['Dónde corre', false],
            'access' => ['Acceso', false],
            'version' => ['Versión', false],
        ],
        'report' => [
            'shows' => ['Qué muestra', true],
            'source_system' => ['Quién lo envía · sistema origen', false],
            'recipients' => ['Destinatarios', false],
            'schedule' => ['Horario', false],
            'format' => ['Formato', false],
        ],
        'data_flow' => [
            'origin_destination' => ['Origen y destino', true],
            'transport' => ['Cómo viaja', false],
            'frequency' => ['Frecuencia', false],
            'on_failure' => ['Qué pasa si falla', false],
        ],
        'procedure' => [
            'steps' => ['Pasos numerados', true],
            'who_can' => ['Quién lo puede hacer', false],
        ],
        'glossary' => [
            'term' => ['Término', true],
            'definition' => ['Definición', true],
            'also_called' => ['También se dice', false],
        ],
    ];

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function fields(string $type): array
    {
        return self::FIELDS[$type] ?? [];
    }

    public static function label(string $type, string $field): string
    {
        return self::FIELDS[$type][$field][0] ?? $field;
    }

    /**
     * Only the keys of `$type`, every key present: trimmed text (empty =
     * null) and the steps as a list without blanks.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string|list<string>|null>
     */
    public static function normalize(string $type, array $fields): array
    {
        $clean = [];

        foreach (array_keys(self::fields($type)) as $key) {
            $value = $fields[$key] ?? null;

            if ($key === self::LIST_FIELD) {
                $steps = is_array($value) ? $value : [];
                $clean[$key] = array_values(array_filter(
                    array_map(fn ($step) => is_scalar($step) ? trim((string) $step) : '', $steps),
                    fn (string $step) => $step !== '',
                ));

                continue;
            }

            $text = is_scalar($value) ? trim((string) $value) : '';
            $clean[$key] = $text === '' ? null : $text;
        }

        return $clean;
    }

    /**
     * Plain text of the fields (FULLTEXT `search_text`, fallback scoring).
     *
     * @param  array<string, mixed>  $fields
     */
    public static function text(string $type, array $fields): string
    {
        $parts = [];

        foreach (self::normalize($type, $fields) as $value) {
            if (is_array($value)) {
                array_push($parts, ...$value);
            } elseif ($value !== null) {
                $parts[] = $value;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * A whole value that is a bracket placeholder ("[VERSIÓN]"), never a fact.
     */
    public static function isPlaceholder(?string $value): bool
    {
        return $value !== null && preg_match('/^\[[^\]]+\]$/u', trim($value)) === 1;
    }
}
