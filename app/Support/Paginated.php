<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * `Paginated<T>` of resources/js/types/shared.ts: `{data, links, meta}`.
 */
class Paginated
{
    /**
     * @param  LengthAwarePaginator<array-key, mixed>  $paginator
     * @param  callable(mixed): array<string, mixed>  $map
     * @return array{data: list<array<string, mixed>>, links: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public static function from(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => array_values(array_map($map, $paginator->items())),
            'links' => $paginator->linkCollection()->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
