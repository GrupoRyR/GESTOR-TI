<?php

namespace App\Domain\Ai;

use App\Models\AiCostRate;
use Carbon\CarbonInterface;

/**
 * Hourly rate in force for a person on a date (PROMPT 6.4.11): the latest
 * `ai_cost_rates` row whose `effective_from` is on or before the date.
 * Callers decide who may see it (`ai.costs.view`).
 */
final class CostRates
{
    public function rateFor(int $userId, ?CarbonInterface $on = null): ?int
    {
        return $this->ratesFor([$userId], $on)[$userId] ?? null;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int> user id => rate (people without a rate are left out)
     */
    public function ratesFor(array $userIds, ?CarbonInterface $on = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $date = ($on ?? now())->copy()->timezone(BusinessDays::TIMEZONE)->toDateString();
        $rates = [];

        $rows = AiCostRate::query()
            ->whereIn('user_id', array_values(array_unique($userIds)))
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->get(['user_id', 'rate']);

        foreach ($rows as $row) {
            $rates[$row->user_id] ??= $row->rate;
        }

        return $rates;
    }
}
