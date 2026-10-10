<?php

namespace App\Domain\Suggestions;

use App\Enums\Ai\EffortSize;

/**
 * Defensive reading of the model's JSON (as App\Domain\Assistant\AiDraftParser):
 * text around the object is ignored, every value is type-checked, trimmed
 * and cut to its limit. A kind whose value is unusable comes back null
 * (the suggestion becomes an `error` with code `bad_answer`).
 */
final class SuggestionAnswer
{
    private const SUMMARY_LENGTH = 600;

    private const ITEM_LENGTH = 300;

    private const MAX_CRITERIA = 6;

    private const MAX_DUPLICATES = 3;

    private const MAX_REASONS = 5;

    private const TITLE_LENGTH = 80;

    private const BODY_LENGTH = 5000;

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @return array{text: string}|null
     */
    public static function summary(mixed $value): ?array
    {
        $text = self::string(is_array($value) ? ($value['text'] ?? null) : $value, self::SUMMARY_LENGTH);

        return $text === null ? null : ['text' => $text];
    }

    /**
     * @return array{items: list<array{id: int, text: string}>}|null
     */
    public static function criteria(mixed $value): ?array
    {
        $items = [];

        foreach (is_array($value) ? array_values($value) : [] as $item) {
            $text = self::string(is_array($item) ? ($item['text'] ?? null) : $item, self::ITEM_LENGTH);

            if ($text !== null && count($items) < self::MAX_CRITERIA) {
                $items[] = ['id' => count($items) + 1, 'text' => $text];
            }
        }

        return $items === [] ? null : ['items' => $items];
    }

    /**
     * @return array{size: string, reason: string}|null
     */
    public static function size(mixed $value): ?array
    {
        $size = is_array($value) ? ($value['size'] ?? null) : $value;
        $size = is_string($size) ? EffortSize::tryFrom(strtoupper(trim($size))) : null;

        if ($size === null) {
            return null;
        }

        return ['size' => $size->value, 'reason' => self::string(is_array($value) ? ($value['reason'] ?? null) : null, self::ITEM_LENGTH) ?? ''];
    }

    /**
     * Duplicates the model found, keyed by request key; only keys of
     * `$offered` (the open requests it received), at most three.
     *
     * @param  list<string>  $offered
     * @return array<string, array{level: string, reason_summary: string, reasons: list<string>, differences: list<string>}>
     */
    public static function duplicates(mixed $value, array $offered): array
    {
        $found = [];

        foreach (is_array($value) ? array_values($value) : [] as $item) {
            $key = is_array($item) && is_string($item['key'] ?? null) ? strtoupper(trim($item['key'])) : null;

            if ($key === null || ! in_array($key, $offered, true) || isset($found[$key]) || count($found) >= self::MAX_DUPLICATES) {
                continue;
            }

            $found[$key] = [
                'level' => ($item['level'] ?? null) === 'alto' ? 'alto' : 'medio',
                'reason_summary' => self::string($item['reason_summary'] ?? null, self::TITLE_LENGTH) ?? 'solicitud parecida',
                'reasons' => self::strings($item['reasons'] ?? null),
                'differences' => self::strings($item['differences'] ?? null),
            ];
        }

        return $found;
    }

    /**
     * @return array{title: string, body: string}|null
     */
    public static function releaseNotes(mixed $value): ?array
    {
        $title = self::string(is_array($value) ? ($value['title'] ?? null) : null, self::TITLE_LENGTH);
        $body = self::string(is_array($value) ? ($value['body'] ?? null) : null, self::BODY_LENGTH);

        return $body === null ? null : ['title' => $title ?? '', 'body' => $body];
    }

    private static function string(mixed $value, int $length): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $items = [];

        foreach (is_array($value) ? $value : [] as $item) {
            $text = self::string($item, self::ITEM_LENGTH);

            if ($text !== null && count($items) < self::MAX_REASONS) {
                $items[] = $text;
            }
        }

        return $items;
    }
}
