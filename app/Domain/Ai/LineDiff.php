<?php

namespace App\Domain\Ai;

/**
 * Line diff between two versions of a document (`DocCompare.lines`,
 * `DiffLine` of resources/js/types/ai.ts), older → newer: longest common
 * subsequence of lines; removed lines come before the added ones of the
 * same change. Line endings are normalized and a trailing newline does not
 * add an empty line.
 *
 * Large documents fall back to "everything removed, everything added"
 * above MAX_CELLS (the LCS table is rows × columns integers).
 */
final class LineDiff
{
    private const MAX_CELLS = 1_000_000;

    /**
     * @return list<array{kind: 'same'|'add'|'remove', old_line: ?int, new_line: ?int, text: string}>
     */
    public static function lines(string $old, string $new): array
    {
        $a = self::split($old);
        $b = self::split($new);
        $n = count($a);
        $m = count($b);

        if ($n * $m > self::MAX_CELLS) {
            return [...self::removed($a, 0, $n), ...self::added($b, 0, $m)];
        }

        // $lcs[$i][$j] = LCS length of a[i..] and b[j..].
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $lines = [];
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $lines[] = ['kind' => 'same', 'old_line' => $i + 1, 'new_line' => $j + 1, 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($i < $n && ($j >= $m || $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                $lines[] = ['kind' => 'remove', 'old_line' => $i + 1, 'new_line' => null, 'text' => $a[$i]];
                $i++;
            } else {
                $lines[] = ['kind' => 'add', 'old_line' => null, 'new_line' => $j + 1, 'text' => $b[$j]];
                $j++;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function split(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if ($text === '') {
            return [];
        }

        if (str_ends_with($text, "\n")) {
            $text = substr($text, 0, -1);
        }

        return explode("\n", $text);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{kind: 'remove', old_line: int, new_line: null, text: string}>
     */
    private static function removed(array $lines, int $from, int $to): array
    {
        $out = [];
        for ($i = $from; $i < $to; $i++) {
            $out[] = ['kind' => 'remove', 'old_line' => $i + 1, 'new_line' => null, 'text' => $lines[$i]];
        }

        return $out;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{kind: 'add', old_line: null, new_line: int, text: string}>
     */
    private static function added(array $lines, int $from, int $to): array
    {
        $out = [];
        for ($j = $from; $j < $to; $j++) {
            $out[] = ['kind' => 'add', 'old_line' => null, 'new_line' => $j + 1, 'text' => $lines[$j]];
        }

        return $out;
    }
}
