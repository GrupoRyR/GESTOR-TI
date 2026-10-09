<?php

namespace App\Domain\Ai;

use App\Models\AiRequest;
use App\Services\Alerts\AlertService;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * First-response window of a request (PROMPT 6.4.1): creation + Settings
 * `ai.first_response_business_days` business days (Bogotá). TI answers by
 * saving an evaluation, a public reply or the first status move; that
 * stamps `first_responded_at` and resolves the overdue alert
 * (`ai:first-response-alerts`, group key `ai.first_response:{key}`).
 */
final class FirstResponse
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AlertService $alerts,
    ) {}

    public static function alertGroup(AiRequest $request): string
    {
        return 'ai.first_response:'.$request->key;
    }

    public function businessDays(): int
    {
        return max(1, (int) $this->settings->get('ai.first_response_business_days', 3));
    }

    public function dueAt(DateTimeInterface $createdAt): CarbonImmutable
    {
        return BusinessDays::add($createdAt, $this->businessDays(), array_values((array) $this->settings->get('ai.holidays', [])));
    }

    /**
     * Stamps the first response once (later answers keep the first date).
     * Does not save: callers save the request with their own changes.
     */
    public function mark(AiRequest $request): void
    {
        if ($request->first_responded_at !== null) {
            return;
        }

        $request->first_responded_at = now();
        $this->alerts->resolve(self::alertGroup($request));
    }
}
