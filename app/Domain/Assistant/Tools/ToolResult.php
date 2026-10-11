<?php

namespace App\Domain\Assistant\Tools;

/**
 * What a tool returns. `data` is what the AI provider receives (after the
 * privacy filter); the rest stays in the app:
 *
 * - `lookup`: a search or a read (knowledge, products, requests) versus an
 *   action (password delivery). A run whose lookups found nothing and that
 *   did nothing else is answered "No lo tengo documentado".
 * - `found`: how many records it returned.
 * - `cards`: keys of the knowledge cards it returned (the only keys an
 *   answer may cite); `opened`: cards read in full (`ver_ficha`).
 *
 * A tool that answers the person by itself (credential notice, person
 * choice, vault link, refusal) records that message with
 * `ToolContext::answered()`: no AI text follows it.
 */
final class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $cards
     * @param  list<string>  $opened
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $lookup = true,
        public readonly int $found = 0,
        public readonly array $cards = [],
        public readonly array $opened = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $cards
     * @param  list<string>  $opened
     */
    public static function lookup(array $data, int $found, array $cards = [], array $opened = []): self
    {
        return new self($data, true, $found, $cards, $opened);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function action(array $data): self
    {
        return new self($data, false);
    }
}
