<?php

namespace App\Telegram\Contracts;

use App\Models\User;

/**
 * Takes the message that answers a question the bot asked
 * (App\Telegram\PendingReplies), e.g. a rejection or discard reason.
 * Handles `/cancelar` itself and forgets the pending reply when done.
 */
interface PendingReplyHandler
{
    /**
     * @param  array<string, mixed>  $data  what was stored with the pending reply
     * @return bool false when the message is not for it (it goes on to the normal flow)
     */
    public function handleReply(User $user, string $chatId, string $text, array $data): bool;
}
