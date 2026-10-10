<?php

namespace App\Domain\Ai;

use Illuminate\Support\Str;

/**
 * Matched ranges of the search (`Highlighted` of types/pages/ai-knowledge.ts):
 * `[start, end)` in UTF-16 code units, as JavaScript strings count them.
 *
 * A keyword (already lowercase ASCII, App\Domain\Ai\DuplicateFinder::keywords)
 * matches at the start of a word, ignoring case and accents: "asesor"
 * marks the first six letters of "Asesores", never the inside of a word.
 */
final class Highlighter
{
    /**
     * @param  list<string>  $keywords
     * @return list<array{0: int, 1: int}>
     */
    public static function ranges(string $text, array $keywords): array
    {
        if ($text === '' || $keywords === []) {
            return [];
        }

        [$folded, $offsets] = self::fold($text);
        $ranges = [];

        foreach ($keywords as $keyword) {
            $length = mb_strlen($keyword);
            if ($length === 0) {
                continue;
            }

            $position = 0;
            while (($index = mb_strpos($folded, $keyword, $position)) !== false) {
                $before = $index === 0 ? ' ' : mb_substr($folded, $index - 1, 1);
                if (preg_match('/[\p{L}\p{N}]/u', $before) !== 1) {
                    $ranges[] = [$offsets[$index], $offsets[$index + $length]];
                }
                $position = $index + $length;
            }
        }

        usort($ranges, fn (array $a, array $b) => $a[0] <=> $b[0]);

        // Merge overlapping or touching ranges.
        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $range[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }

    /**
     * `Highlighted` for a short text (titles, names).
     *
     * @param  list<string>  $keywords
     * @return array{text: string, highlights: list<array{0: int, 1: int}>}
     */
    public static function highlight(string $text, array $keywords): array
    {
        return ['text' => $text, 'highlights' => self::ranges($text, $keywords)];
    }

    /**
     * `Highlighted` excerpt of a long text around its first match: blank
     * space collapsed, "… " / " …" where it was cut.
     *
     * @param  list<string>  $keywords
     * @return array{text: string, highlights: list<array{0: int, 1: int}>}
     */
    public static function snippet(string $text, array $keywords, int $before = 60, int $length = 220): array
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', $text));
        [$folded] = self::fold($plain);

        $first = null;
        foreach ($keywords as $keyword) {
            $index = mb_strpos($folded, $keyword);
            if ($index !== false && ($first === null || $index < $first)) {
                $first = $index;
            }
        }

        $total = mb_strlen($plain);
        $start = $first === null ? 0 : max(0, $first - $before);
        // Do not cut a word at the start.
        if ($start > 0) {
            $space = mb_strpos($plain, ' ', $start);
            $start = $space !== false && $space < ($first ?? $start) ? $space + 1 : $start;
        }
        $end = min($total, $start + $length);

        $excerpt = mb_substr($plain, $start, $end - $start);
        $excerpt = ($start > 0 ? '… ' : '').$excerpt.($end < $total ? ' …' : '');

        return self::highlight($excerpt, $keywords);
    }

    /**
     * Lowercase, accent-free copy with one character per original
     * character, plus the UTF-16 offset of every character position
     * (one more entry for the end).
     *
     * @return array{0: string, 1: list<int>}
     */
    private static function fold(string $text): array
    {
        $chars = mb_str_split($text);
        $folded = '';
        $offsets = [];
        $offset = 0;

        foreach ($chars as $char) {
            $offsets[] = $offset;
            $offset += mb_ord($char) > 0xFFFF ? 2 : 1;

            $ascii = Str::lower(Str::ascii($char));
            $folded .= mb_strlen($ascii) === 1 ? $ascii : mb_strtolower($char);
        }
        $offsets[] = $offset;

        return [$folded, $offsets];
    }
}
