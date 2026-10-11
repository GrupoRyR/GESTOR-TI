<?php

namespace App\Domain\Assistant;

use App\Services\Ai\RetryTransientStep;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

/**
 * The Asistente TI as a Laravel AI SDK agent (PROMPT 6.8.3): fixed
 * instructions and tools first (the cached prefix), then the last 30
 * minutes of the conversation, then the question. At most 5 model rounds;
 * each provider call gets one retry on 429/5xx.
 */
#[MaxSteps(AssistantAgent::MAX_STEPS)]
final class AssistantAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;

    public const MAX_STEPS = 5;

    /**
     * @param  list<Message>  $history
     * @param  list<Tool>  $tools
     */
    public function __construct(
        private readonly array $history,
        private readonly array $tools,
    ) {}

    public function instructions(): string
    {
        return AssistantEngine::instructions();
    }

    public function messages(): iterable
    {
        return $this->history;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    public function middleware(): array
    {
        return [new RetryTransientStep];
    }
}
