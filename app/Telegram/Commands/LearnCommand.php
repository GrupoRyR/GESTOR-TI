<?php

namespace App\Telegram\Commands;

use App\Domain\Assistant\AssistantEngine;
use App\Domain\Assistant\DraftRejected;
use App\Domain\Assistant\LearnDrafts;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Telegram\Assistant\DraftMessage;
use App\Telegram\Assistant\TelegramAssistant;
use App\Telegram\Assistant\TypingIndicator;
use App\Telegram\Handlers\LearnCallbackHandler;
use App\Telegram\TelegramHtml;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram `/aprender la VPN de Derivados usa FortiClient…` (PROMPT 6.8.1,
 * telegram-format.md §6): `assistant.knowledge.manage` only. Creates a
 * pending draft through App\Domain\Assistant\LearnDrafts (AI parser when
 * the AI is on, heuristic otherwise; source `telegram`, with the chat and
 * the id of the draft message) and answers with the draft and its
 * buttons (App\Telegram\Handlers\LearnCallbackHandler). Shares the
 * assistant rate limit of the chat.
 */
final class LearnCommand
{
    public const COMMAND = AssistantEngine::LEARN_COMMAND;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly LearnDrafts $drafts,
        private readonly TypingIndicator $typing,
    ) {}

    public function handle(User $user, string $chatId, string $text): void
    {
        if (! KnowledgeItem::canManage($user)) {
            $this->send($chatId, LearnCallbackHandler::NOT_ALLOWED);

            return;
        }

        if (! TelegramAssistant::attempt($chatId)) {
            $this->send($chatId, TelegramAssistant::RATE_LIMITED);

            return;
        }

        $this->typing->start($chatId);
        try {
            $draft = TelegramAssistant::actingAs($user, fn () => $this->drafts->fromText($user, $text, 'telegram', $chatId));
        } catch (DraftRejected $rejected) {
            $this->send($chatId, $rejected->getMessage());

            return;
        } catch (Throwable $error) {
            Log::error('Telegram: /aprender falló.', ['user_id' => $user->id, 'error' => class_basename($error)]);
            $this->send($chatId, AssistantEngine::UNAVAILABLE);

            return;
        } finally {
            $this->typing->stop();
        }

        $sent = $this->client->sendMessage($chatId, DraftMessage::text($draft), DraftMessage::keyboard($draft, $chatId), TelegramHtml::OPTIONS);

        if (is_int($sent['message_id'] ?? null)) {
            $draft->forceFill(['telegram_message_id' => $sent['message_id']])->save();
        }
    }

    private function send(string $chatId, string $text): void
    {
        $this->client->sendMessage($chatId, TelegramHtml::escape($text), [], TelegramHtml::OPTIONS);
    }
}
