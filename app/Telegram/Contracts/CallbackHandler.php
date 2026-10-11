<?php

namespace App\Telegram\Contracts;

use App\Models\User;

/**
 * Handles the inline-button presses (`callback_query`) whose data starts
 * with the prefix it was registered under in `App\Telegram\CallbackRegistry`.
 * Only called for chats linked to a user; the handler must still check the
 * user's permissions.
 */
interface CallbackHandler
{
    /**
     * @param  string  $payload  callback data after `{prefix}:`
     * @param  array<string, mixed>  $callbackQuery  raw Telegram CallbackQuery
     * @return ?string short text shown to the user (answerCallbackQuery)
     */
    public function handle(User $user, string $payload, array $callbackQuery): ?string;
}
