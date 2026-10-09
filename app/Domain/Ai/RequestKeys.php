<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\DB;

/**
 * Request keys `GIA-{n}`. `n` comes from the `sequences` row `ai_requests`,
 * incremented inside a transaction with `lockForUpdate()` (MySQL row lock),
 * so concurrent requests never get the same number and numbers are never
 * reused (soft-deleted requests keep theirs). Shared by every company.
 */
final class RequestKeys
{
    public const PREFIX = 'GIA-';

    private const SEQUENCE = 'ai_requests';

    public function next(): string
    {
        return self::PREFIX.$this->increment();
    }

    /**
     * Moves the sequence forward so the next key is above `$number`
     * (e.g. after importing GIA-1…GIA-147 from the old tracker).
     */
    public function ensureAtLeast(int $number): void
    {
        DB::transaction(function () use ($number): void {
            $current = $this->lockedValue();

            if ($current < $number) {
                DB::table('sequences')->where('name', self::SEQUENCE)->update(['value' => $number]);
            }
        });
    }

    private function increment(): int
    {
        return DB::transaction(function (): int {
            $next = $this->lockedValue() + 1;

            DB::table('sequences')->where('name', self::SEQUENCE)->update(['value' => $next]);

            return $next;
        });
    }

    private function lockedValue(): int
    {
        DB::table('sequences')->insertOrIgnore(['name' => self::SEQUENCE, 'value' => 0]);

        return (int) DB::table('sequences')->where('name', self::SEQUENCE)->lockForUpdate()->value('value');
    }
}
