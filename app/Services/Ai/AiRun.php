<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Outcome of App\Services\Ai\AiRuntime::run(): the SDK response (null on
 * failure), the `ai_interactions` rows written for it and the failure.
 */
final class AiRun
{
    /**
     * @param  list<AiInteraction>  $interactions
     */
    public function __construct(
        public readonly ?AgentResponse $response,
        public readonly array $interactions,
        public readonly ?Throwable $error,
    ) {}

    public function failed(): bool
    {
        return $this->error !== null || $this->response === null;
    }

    public function text(): string
    {
        return trim((string) $this->response?->text);
    }

    public function lastInteraction(): ?AiInteraction
    {
        return $this->interactions === [] ? null : $this->interactions[array_key_last($this->interactions)];
    }
}
