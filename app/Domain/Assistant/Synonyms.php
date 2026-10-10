<?php

namespace App\Domain\Assistant;

use App\Domain\Ai\DuplicateFinder;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Glossary synonyms (PROMPT 6.8.2): each glossary card the person may see
 * is a group of phrases, its `term` plus every "También se dice" value
 * (separated by commas, semicolons, slashes or line breaks). Placeholders
 * ("[SINÓNIMOS]") are not synonyms.
 *
 * A query that contains every word of one phrase of a group also searches
 * the words of the other phrases of that group.
 */
final class Synonyms
{
    /**
     * Phrases per glossary card: lowercase, without accents, first the term.
     *
     * @return list<list<string>>
     */
    public function groups(User $user): array
    {
        $groups = [];

        $glossary = KnowledgeItem::query()->visibleTo($user)->where('type', 'glossary')->orderBy('id')->get(['id', 'type', 'fields']);

        foreach ($glossary as $item) {
            $fields = KnowledgeTypes::normalize('glossary', $item->fields ?? []);
            $phrases = [(string) $fields['term']];

            foreach (preg_split('/[,;\/\n]+/u', (string) $fields['also_called']) ?: [] as $phrase) {
                $phrases[] = $phrase;
            }

            $clean = [];
            foreach ($phrases as $phrase) {
                $phrase = trim($phrase);
                if ($phrase === '' || KnowledgeTypes::isPlaceholder($phrase)) {
                    continue;
                }
                $clean[] = trim((string) preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($phrase))));
            }

            $clean = array_values(array_unique(array_filter($clean, fn (string $phrase) => $phrase !== '')));
            if ($clean !== []) {
                $groups[] = $clean;
            }
        }

        return $groups;
    }

    /**
     * Extra keywords for `$keywords` (DuplicateFinder::keywords), in order
     * of appearance, none already in the query.
     *
     * @param  list<string>  $keywords
     * @param  list<list<string>>  $groups
     * @return list<string>
     */
    public function expand(array $keywords, array $groups): array
    {
        $asked = array_flip($keywords);
        $extra = [];

        foreach ($groups as $phrases) {
            $words = array_map(fn (string $phrase) => DuplicateFinder::keywords($phrase), $phrases);

            $matched = false;
            foreach ($words as $phraseWords) {
                if ($phraseWords !== [] && array_diff($phraseWords, $keywords) === []) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                continue;
            }

            foreach ($words as $phraseWords) {
                foreach ($phraseWords as $word) {
                    if (! isset($asked[$word])) {
                        $extra[$word] = true;
                    }
                }
            }
        }

        return array_map(fn (int|string $word) => (string) $word, array_keys($extra));
    }
}
