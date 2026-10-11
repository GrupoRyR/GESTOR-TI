<?php

namespace App\Domain\Ai\Brief;

use App\Domain\Ai\SecretGuard;
use Illuminate\Support\Str;

/**
 * Free text a brief may carry (problem, objective, descriptions,
 * subtasks, criteria, decisions). A text is dropped whole, never
 * partially masked, when it looks like a credential (SecretGuard),
 * carries a money amount ("$ 1.200", "COP", "USD"), or quotes an
 * internal note of the related requests. Links are dropped when they
 * point to the vault (same rule as lib/brief.ts) or are not http(s).
 */
final class BriefText
{
    /** Amounts with a currency, as App\Domain\GitHub\IssueText. */
    private const MONEY = '/(?:\$\s?\d|\b(?:COP|USD)\s?\$?\s?\d)/i';

    /** lib/brief.ts VAULT_PATTERN. */
    private const VAULT = '/vault|b[oó]veda/iu';

    /** Internal notes shorter than this are not matched (common words). */
    private const NOTE_MIN_LENGTH = 15;

    /** @var list<string> lowercase internal notes */
    private array $notes;

    /**
     * @param  iterable<string>  $internalNotes  bodies of the internal notes of the related requests
     */
    public function __construct(private readonly SecretGuard $secrets, iterable $internalNotes = [])
    {
        $notes = [];

        foreach ($internalNotes as $note) {
            $note = Str::lower(trim((string) $note));

            if (mb_strlen($note) >= self::NOTE_MIN_LENGTH) {
                $notes[] = $note;
            }
        }

        $this->notes = $notes;
    }

    /**
     * The trimmed text, or null when it is empty or unsafe.
     */
    public function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' || ! $this->safe($text) ? null : $text;
    }

    public function safe(string $text): bool
    {
        if ($this->secrets->find($text) !== null || preg_match(self::MONEY, $text) === 1) {
            return false;
        }

        $haystack = Str::lower($text);

        foreach ($this->notes as $note) {
            if (str_contains($haystack, $note)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `BriefLink`, or null for vault, non-http or unsafe links.
     *
     * @return array{label: string, url: string}|null
     */
    public function link(string $label, ?string $url): ?array
    {
        $label = trim($label);
        $url = trim((string) $url);

        if ($label === '' || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        if (preg_match(self::VAULT, $label) === 1 || preg_match(self::VAULT, $url) === 1 || $this->secrets->find($url) !== null) {
            return null;
        }

        return ['label' => $label, 'url' => $url];
    }
}
