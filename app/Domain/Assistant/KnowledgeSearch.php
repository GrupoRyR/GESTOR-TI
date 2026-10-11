<?php

namespace App\Domain\Assistant;

use App\Domain\Ai\DuplicateFinder;
use App\Domain\Ai\Highlighter;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Search of the knowledge base (PROMPT 6.8.2): the backend of the
 * assistant's `buscar_conocimiento` tool and of the "Buscar" box of the
 * knowledge list. UI-independent: it returns models, scores and
 * highlight ranges, never page props.
 *
 * - Visibility first: only `KnowledgeItem::visibleTo($user)` cards are
 *   searched, so "Solo TI" cards never reach other people (nor their
 *   glossary synonyms).
 * - Keywords: App\Domain\Ai\DuplicateFinder::keywords (no accents, no
 *   Spanish stopwords, 3+ letters), plus glossary synonyms
 *   (App\Domain\Assistant\Synonyms). A query like "K-031" finds that card.
 * - MySQL: FULLTEXT `MATCH (title, description, search_text) AGAINST (…
 *   IN NATURAL LANGUAGE MODE)` (verified on the server). Other drivers
 *   (SQLite in tests): the newest FALLBACK_CANDIDATES cards scored in PHP:
 *   3 points per keyword in the title, 1 in the description or fields;
 *   synonyms count half.
 * - Highlights: App\Domain\Ai\Highlighter (UTF-16 ranges, as JavaScript).
 *
 * Gestor IA products are not duplicated as cards: they are linked from
 * cards ("Relacionadas") and searched by their own tool (`buscar_productos`).
 */
final class KnowledgeSearch
{
    private const FALLBACK_CANDIDATES = 500;

    private const MAX_MATCHES = 200;

    private const TITLE_POINTS = 3.0;

    private const BODY_POINTS = 1.0;

    private const SYNONYM_FACTOR = 0.5;

    private const KEY_SCORE = 1000.0;

    public function __construct(private readonly Synonyms $synonyms) {}

    /**
     * Best cards first.
     *
     * @return list<array{
     *     item: KnowledgeItem,
     *     score: float,
     *     title: array{text: string, highlights: list<array{0: int, 1: int}>},
     *     snippet: array{text: string, highlights: list<array{0: int, 1: int}>},
     * }>
     */
    public function search(User $user, string $text, ?KnowledgeFilters $filters = null, int $limit = 10): array
    {
        $query = KnowledgeItem::query()->visibleTo($user)->with(['companies', 'area']);
        ($filters ?? new KnowledgeFilters)->apply($query, now()->toDateString());

        [$keywords, $extra] = $this->terms($user, $text);
        $all = [...$keywords, ...$extra];

        return array_map(fn (array $hit) => [
            'item' => $hit[0],
            'score' => $hit[1],
            'title' => Highlighter::highlight($hit[0]->title, $all),
            'snippet' => Highlighter::snippet(self::body($hit[0]), $all),
        ], array_slice($this->rank($query, $text, $keywords, $extra), 0, max(1, $limit)));
    }

    /**
     * Restricts a list query (already visible and filtered) to the cards
     * matching `$text`; the caller keeps its own order.
     *
     * @param  Builder<KnowledgeItem>  $query
     */
    public function constrain(Builder $query, User $user, string $text): void
    {
        [$keywords, $extra] = $this->terms($user, $text);
        $ids = array_map(fn (array $hit) => $hit[0]->id, $this->rank(clone $query, $text, $keywords, $extra));

        $query->whereKey($ids);
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function terms(User $user, string $text): array
    {
        $keywords = DuplicateFinder::keywords($text);

        return [$keywords, $keywords === [] ? [] : $this->synonyms->expand($keywords, $this->synonyms->groups($user))];
    }

    /**
     * @param  Builder<KnowledgeItem>  $query
     * @param  list<string>  $keywords
     * @param  list<string>  $extra
     * @return list<array{0: KnowledgeItem, 1: float}>
     */
    private function rank(Builder $query, string $text, array $keywords, array $extra): array
    {
        $hits = [];

        $key = self::keyIn($text);
        if ($key !== null) {
            $item = (clone $query)->where('key', $key)->first();
            if ($item !== null) {
                $hits[$item->id] = [$item, self::KEY_SCORE];
            }
        }

        if ($keywords !== []) {
            $matches = $query->getConnection()->getDriverName() === 'mysql'
                ? $this->fullText($query, [...$keywords, ...$extra])
                : $this->fallback($query, $keywords, $extra);

            foreach ($matches as $match) {
                $hits[$match[0]->id] ??= $match;
            }
        }

        return array_values($hits);
    }

    /**
     * @param  Builder<KnowledgeItem>  $query
     * @param  list<string>  $terms
     * @return list<array{0: KnowledgeItem, 1: float}>
     */
    private function fullText(Builder $query, array $terms): array
    {
        $against = implode(' ', $terms);
        $match = 'MATCH (title, description, search_text) AGAINST (? IN NATURAL LANGUAGE MODE)';

        return (clone $query)
            ->select('knowledge_items.*')
            ->selectRaw("{$match} AS relevance", [$against])
            ->whereFullText(['title', 'description', 'search_text'], $against)
            ->orderByDesc('relevance')
            ->orderByDesc('updated_at')
            ->limit(self::MAX_MATCHES)
            ->get()
            ->map(fn (KnowledgeItem $item) => [$item, (float) $item->getAttribute('relevance')])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<KnowledgeItem>  $query
     * @param  list<string>  $keywords
     * @param  list<string>  $extra
     * @return list<array{0: KnowledgeItem, 1: float}>
     */
    private function fallback(Builder $query, array $keywords, array $extra): array
    {
        $scored = [];
        $rows = (clone $query)->orderByDesc('updated_at')->orderByDesc('id')->limit(self::FALLBACK_CANDIDATES)->get();

        foreach ($rows as $item) {
            $title = self::fold($item->title.' '.$item->key);
            $body = self::fold(self::body($item));

            $score = self::points($keywords, $title, $body) + self::SYNONYM_FACTOR * self::points($extra, $title, $body);

            if ($score > 0) {
                $scored[] = [$item, $score];
            }
        }

        // Stable: ties keep the newest first.
        usort($scored, fn (array $a, array $b) => $b[1] <=> $a[1]);

        return array_slice($scored, 0, self::MAX_MATCHES);
    }

    /**
     * @param  list<string>  $words
     */
    private static function points(array $words, string $title, string $body): float
    {
        $points = 0.0;

        foreach ($words as $word) {
            $points += (str_contains($title, $word) ? self::TITLE_POINTS : 0.0) + (str_contains($body, $word) ? self::BODY_POINTS : 0.0);
        }

        return $points;
    }

    /**
     * "K-031", "k031" or "K-31" as a card key.
     */
    private static function keyIn(string $text): ?string
    {
        return preg_match('/^\s*k-?(\d{1,6})\s*$/i', $text, $match) === 1 ? KnowledgeItem::formatKey((int) $match[1]) : null;
    }

    private static function body(KnowledgeItem $item): string
    {
        return trim(($item->description ?? '')."\n".($item->search_text ?? ''));
    }

    private static function fold(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }
}
