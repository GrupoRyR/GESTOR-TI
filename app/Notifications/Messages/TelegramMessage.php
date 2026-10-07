<?php

namespace App\Notifications\Messages;

/**
 * Message for the `telegram` notification channel: plain text (no parse
 * mode, so user data needs no escaping) plus optional inline buttons.
 * Callback buttons are routed by `App\Telegram\CallbackRegistry`
 * (`{prefix}:{payload}`, max 64 bytes).
 */
class TelegramMessage
{
    /**
     * @var list<list<array{text: string, callback_data?: string, url?: string}>>
     */
    public array $keyboard = [];

    public function __construct(public string $text = '') {}

    public static function make(string $text = ''): self
    {
        return new self($text);
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Adds a callback button on its own row.
     */
    public function button(string $text, string $callbackData): self
    {
        $this->keyboard[] = [['text' => $text, 'callback_data' => $callbackData]];

        return $this;
    }

    /**
     * Adds a link button on its own row.
     */
    public function urlButton(string $text, string $url): self
    {
        $this->keyboard[] = [['text' => $text, 'url' => $url]];

        return $this;
    }

    /**
     * Adds a row of buttons as given (Telegram InlineKeyboardButton arrays).
     *
     * @param  list<array{text: string, callback_data?: string, url?: string}>  $buttons
     */
    public function row(array $buttons): self
    {
        $this->keyboard[] = $buttons;

        return $this;
    }
}
