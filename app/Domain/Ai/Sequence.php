<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\DB;

/**
 * Named counters of the `sequences` table, incremented inside a
 * transaction with `lockForUpdate()` (MySQL row lock): concurrent callers
 * never get the same number. Same mechanism as App\Domain\Ai\RequestKeys.
 */
final class Sequence
{
    public static function next(string $name): int
    {
        return DB::transaction(function () use ($name): int {
            DB::table('sequences')->insertOrIgnore(['name' => $name, 'value' => 0]);
            $next = (int) DB::table('sequences')->where('name', $name)->lockForUpdate()->value('value') + 1;
            DB::table('sequences')->where('name', $name)->update(['value' => $next]);

            return $next;
        });
    }
}
