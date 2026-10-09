<?php

namespace App\Domain\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Support\Settings;

/**
 * Work in progress limit (PROMPT 6.4.3): how many projects are "En
 * desarrollo" at the same time across every company, against Settings
 * `ai.wip_limit` (default 3). Until projects exist (B5) the requests in
 * development stand for them.
 */
final class WipLimit
{
    public function __construct(private readonly Settings $settings) {}

    public function limit(): int
    {
        return max(1, (int) $this->settings->get('ai.wip_limit', 3));
    }

    /**
     * Design `wip`: `{ in_development, limit }`.
     *
     * @return array{in_development: int, limit: int}
     */
    public function snapshot(): array
    {
        // TODO B5: count projects in development instead of requests.
        $inDevelopment = AiRequest::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', RequestStatus::InDevelopment->value)
            ->count();

        return ['in_development' => $inDevelopment, 'limit' => $this->limit()];
    }
}
