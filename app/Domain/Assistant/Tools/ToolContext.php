<?php

namespace App\Domain\Assistant\Tools;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;

/**
 * State of one question's tool round trip: the conversation (tools that
 * answer by themselves add their message to it), the channel, and what the
 * tools returned (cards that may be cited, lookups, actions).
 */
final class ToolContext
{
    public int $lookups = 0;

    public int $actions = 0;

    public int $found = 0;

    /** @var list<string> */
    public array $cards = [];

    /** @var list<string> */
    public array $opened = [];

    /** Message the tools wrote to the chat (credential notice, choice…). */
    public ?AssistantMessage $reply = null;

    public function __construct(
        public readonly AssistantConversation $conversation,
        public readonly string $channel = 'app',
    ) {}

    public function absorb(ToolResult $result): void
    {
        $result->lookup ? $this->lookups++ : $this->actions++;
        $this->found += $result->found;
        $this->cards = array_values(array_unique([...$this->cards, ...$result->cards]));
        $this->opened = array_values(array_unique([...$this->opened, ...$result->opened]));
    }

    public function answered(AssistantMessage $message): void
    {
        $this->reply = $message;
    }

    public function settled(): bool
    {
        return $this->reply !== null;
    }

    /**
     * Lookups ran, none found anything and nothing else happened.
     */
    public function foundNothing(): bool
    {
        return $this->lookups > 0 && $this->found === 0 && $this->actions === 0;
    }
}
