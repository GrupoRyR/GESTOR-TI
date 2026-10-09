<?php

namespace App\Domain\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * "Solicitudes parecidas" while the requester types, and duplicate
 * candidates for TI. Rejected and closed requests are never offered (there
 * is nothing left to +1).
 *
 * - MySQL: FULLTEXT index on (title, problem), `MATCH … AGAINST` in natural
 *   language mode, ordered by relevance (verified on the server).
 * - Other drivers (SQLite in tests): keywords without Spanish stopwords and
 *   accents, scored in PHP over the newest candidates by how many keywords
 *   appear in title + problem.
 *
 * The company filter is explicit (null = every company) and the global
 * scope is lifted, so callers decide what the viewer may see.
 */
final class DuplicateFinder
{
    /** Candidates scored by the fallback (newest first). */
    private const FALLBACK_CANDIDATES = 500;

    private const MAX_KEYWORDS = 12;

    private const STOPWORDS = [
        'a', 'al', 'algo', 'ante', 'aqui', 'asi', 'cada', 'como', 'con', 'contra', 'cual', 'cuando', 'de', 'del',
        'desde', 'donde', 'dos', 'el', 'ella', 'ellos', 'en', 'entre', 'era', 'es', 'esa', 'ese', 'eso', 'esta',
        'estan', 'este', 'esto', 'estos', 'hace', 'hacer', 'hay', 'la', 'las', 'le', 'les', 'lo', 'los', 'mas',
        'me', 'mi', 'mis', 'muy', 'nada', 'ni', 'no', 'nos', 'nuestro', 'o', 'otra', 'otro', 'para', 'pero', 'poder',
        'por', 'porque', 'puede', 'que', 'quien', 'se', 'sea', 'ser', 'si', 'sin', 'sobre', 'solo', 'su', 'sus',
        'tambien', 'tan', 'tener', 'tiene', 'todo', 'todos', 'un', 'una', 'uno', 'unos', 'unas', 'y', 'ya', 'yo',
        'cuanto', 'cuantos', 'tenemos', 'queremos', 'necesitamos', 'necesito', 'quiero',
    ];

    /**
     * @return Collection<int, AiRequest>
     */
    public function similar(string $text, ?int $companyId, int $limit = 5, ?int $excludeId = null): Collection
    {
        $keywords = self::keywords($text);

        if ($keywords === [] || $limit < 1) {
            return new Collection;
        }

        $query = AiRequest::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereNotIn('status', RequestStatus::finishedValues())
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($excludeId !== null, fn (Builder $query) => $query->whereKeyNot($excludeId));

        if ($query->getConnection()->getDriverName() === 'mysql') {
            $terms = implode(' ', $keywords);

            return $query
                ->whereFullText(['title', 'problem'], $terms)
                ->orderByRaw('MATCH (title, problem) AGAINST (? IN NATURAL LANGUAGE MODE) DESC', [$terms])
                ->limit($limit)
                ->get();
        }

        $scored = [];

        foreach ($query->latest('id')->limit(self::FALLBACK_CANDIDATES)->get() as $request) {
            $score = self::matches($keywords, $request->title.' '.$request->problem);

            if ($score > 0) {
                $scored[] = [$request, $score];
            }
        }

        // Most keywords first; newest first on ties (input is newest first, usort is stable).
        usort($scored, fn (array $a, array $b) => $b[1] <=> $a[1]);

        return new Collection(array_map(fn (array $pair) => $pair[0], array_slice($scored, 0, $limit)));
    }

    /**
     * Distinct search words: lowercase, without accents, punctuation and
     * Spanish stopwords, at least 3 characters.
     *
     * @return list<string>
     */
    public static function keywords(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopwords = array_flip(self::STOPWORDS);

        $keywords = array_filter($words, fn (string $word) => mb_strlen($word) >= 3 && ! isset($stopwords[$word]));

        return array_slice(array_values(array_unique($keywords)), 0, self::MAX_KEYWORDS);
    }

    private static function normalize(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }

    /**
     * @param  list<string>  $keywords
     */
    private static function matches(array $keywords, string $text): int
    {
        $haystack = self::normalize($text);

        return count(array_filter($keywords, fn (string $keyword) => str_contains($haystack, $keyword)));
    }
}
