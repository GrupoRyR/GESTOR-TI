<?php

namespace App\Telegram;

use Carbon\CarbonInterface;

/**
 * Telegram `parse_mode: HTML` helpers (docs/design/telegram-format.md §1):
 * every AI or user text is escaped BEFORE it is wrapped in tags, and only
 * `<b>`, `<i>`, `<a href>` (and `<code>` in the password message) appear.
 */
final class TelegramHtml
{
    /** sendMessage options of every HTML message (no link previews of Gestor pages). */
    public const OPTIONS = ['parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];

    /** Answer of a tap whose data is unknown, tampered, foreign or expired. */
    public const UNAVAILABLE_ACTION = 'Esta acción ya no está disponible.';

    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    public static function bold(string $text): string
    {
        return '<b>'.self::escape($text).'</b>';
    }

    public static function italic(string $text): string
    {
        return '<i>'.self::escape($text).'</i>';
    }

    public static function link(string $url, string $text): string
    {
        return '<a href="'.str_replace('"', '&quot;', self::escape($url)).'">'.self::escape($text).'</a>';
    }

    /**
     * "4:19 p. m." in Bogotá time (approval lines).
     */
    public static function time(CarbonInterface $at): string
    {
        $local = $at->copy()->timezone('America/Bogota');

        return $local->format('g:i').($local->hour < 12 ? ' a. m.' : ' p. m.');
    }
}
