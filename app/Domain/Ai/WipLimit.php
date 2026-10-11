<?php

namespace App\Domain\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use App\Support\Settings;

/**
 * Work in progress limit (PROMPT 6.4.3): how many projects are "En
 * desarrollo" at the same time across every company, against Settings
 * `ai.wip_limit` (default 3). A planned project cannot start (first task
 * to "En progreso", App\Actions\Ai\MoveTask) while the limit is reached.
 */
final class WipLimit
{
    public function __construct(private readonly Settings $settings) {}

    public function limit(): int
    {
        return max(1, (int) $this->settings->get('ai.wip_limit', 3));
    }

    public function inDevelopment(): int
    {
        return AiProject::query()
            ->withoutGlobalScopes()
            ->where('status', RequestStatus::InDevelopment->value)
            ->count();
    }

    public function isFull(): bool
    {
        return $this->inDevelopment() >= $this->limit();
    }

    /**
     * Design `wip`: `{ in_development, limit }`.
     *
     * @return array{in_development: int, limit: int}
     */
    public function snapshot(): array
    {
        return ['in_development' => $this->inDevelopment(), 'limit' => $this->limit()];
    }
}
