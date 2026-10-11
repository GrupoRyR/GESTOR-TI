<?php

namespace App\Support\Ai;

use App\Models\User;
use App\Services\Ai\AiRuntime;
use App\Support\Integrations\AiProviderSettings;

/**
 * `AiAvailability` and `AiUsage` (resources/js/types/suggestions.ts) for a
 * viewer of a page with AI suggestions (release page, request IT view):
 *
 * - `hidden` without `ai.manage` (requesters never see suggestions);
 * - otherwise App\Services\Ai\AiRuntime::status(): `ready`, `off_no_key`
 *   (no provider credential in `.env`) or `off_cap` (month spend reached
 *   the cap of Integraciones).
 *
 * `usage()` is only filled for `off_cap`: spend and cap in USD
 * ("US$ 10,50") and the day suggestions come back (first day of next
 * month in America/Bogota, "1 de noviembre").
 */
final class AiSuggestionAvailability
{
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        private readonly AiRuntime $runtime,
        private readonly AiProviderSettings $settings,
    ) {}

    /**
     * @return 'ready'|'off_no_key'|'off_cap'|'hidden'
     */
    public function for(User $viewer): string
    {
        return $viewer->can('ai.manage') ? $this->runtime->status() : 'hidden';
    }

    /**
     * @return array{spent_label: string, cap_label: string, resumes_on: string}|null
     */
    public function usage(User $viewer): ?array
    {
        if ($this->for($viewer) !== 'off_cap') {
            return null;
        }

        $resumes = now(AiProviderSettings::TIMEZONE)->addMonthNoOverflow()->startOfMonth();

        return [
            'spent_label' => self::usd($this->settings->monthSpend()),
            'cap_label' => self::usd((float) $this->settings->monthlyCap()),
            'resumes_on' => $resumes->day.' de '.self::MONTHS[$resumes->month - 1],
        ];
    }

    /**
     * "US$ 1.234,50".
     */
    public static function usd(float $amount): string
    {
        return 'US$ '.number_format($amount, 2, ',', '.');
    }
}
