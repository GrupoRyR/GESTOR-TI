<?php

namespace App\Telegram\Handlers;

use App\Actions\Assistant\DraftDecisions;
use App\Domain\Assistant\KnowledgeTypes;
use App\Models\KnowledgeDraft;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Telegram\Assistant\DraftMessage;
use App\Telegram\Assistant\TelegramAssistant;
use App\Telegram\Contracts\CallbackHandler;
use App\Telegram\Contracts\PendingReplyHandler;
use App\Telegram\PendingReplies;
use App\Telegram\SignedCallback;
use App\Telegram\TelegramHtml;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Aprobar / Descartar of a `/aprender` draft sent by Telegram (prefix
 * `learn`, telegram-format.md §6). Data `learn:{ok|no}:{draft}:{signature}`
 * (App\Telegram\SignedCallback, bound to the chat).
 *
 * - `assistant.knowledge.manage` is checked again on every tap.
 * - Aprobar: needs a title and a company (else says what is missing and
 *   points to "Editar en el Gestor TI"); publishes the card with the
 *   proposed visibility and review date (App\Actions\Assistant\DraftDecisions),
 *   edits the draft message (no buttons, "Aprobado por ti · 4:19 p. m.")
 *   and links the new card.
 * - Descartar: asks for the reason; the next message of the chat within
 *   15 minutes is the reason (`handleReply()`, App\Telegram\PendingReplies),
 *   `/cancelar` keeps the draft.
 * - A decided draft answers "Este borrador ya se decidió." and changes nothing.
 */
class LearnCallbackHandler implements CallbackHandler, PendingReplyHandler
{
    public const PREFIX = 'learn';

    public const REPLY_KIND = 'learn-discard';

    public const NOT_ALLOWED = 'Solo quien administra la base de conocimiento puede usar /aprender y decidir borradores. Pídeselo a TI.';

    public const NEEDS_TITLE = 'Falta el título. Complétalo con «Editar en el Gestor TI» y apruébalo ahí.';

    public const NEEDS_COMPANY = 'Falta la empresa. Complétala con «Editar en el Gestor TI» y apruébalo ahí.';

    public const DECIDED = 'Este borrador ya se decidió.';

    public const CANCEL_COMMAND = '/cancelar';

    private const REASON_LENGTH = 500;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly DraftDecisions $decisions,
        private readonly PendingReplies $replies,
    ) {}

    public function handle(User $user, string $payload, array $callbackQuery): ?string
    {
        $chatId = SignedCallback::chatOf($callbackQuery);
        $data = SignedCallback::verify(self::PREFIX, $payload, $chatId);
        $parts = $data === null ? [] : explode(':', $data);

        if (count($parts) !== 2 || ! in_array($parts[0], ['ok', 'no'], true) || ! ctype_digit($parts[1])) {
            Log::warning('Telegram: botón de borrador con datos inválidos.', ['user_id' => $user->id]);

            return TelegramHtml::UNAVAILABLE_ACTION;
        }

        if (! KnowledgeItem::canManage($user)) {
            return self::NOT_ALLOWED;
        }

        $draft = KnowledgeDraft::query()->find((int) $parts[1]);
        if ($draft === null) {
            return TelegramHtml::UNAVAILABLE_ACTION;
        }
        if (! $draft->isPending()) {
            return self::DECIDED;
        }

        $messageId = $callbackQuery['message']['message_id'] ?? null;
        $messageId = is_int($messageId) ? $messageId : null;

        return $parts[0] === 'ok'
            ? $this->approve($user, $chatId, $draft, $messageId)
            : $this->askReason($chatId, $draft, $messageId);
    }

    public function handleReply(User $user, string $chatId, string $text, array $data): bool
    {
        $reason = trim($text);

        if (mb_strtolower($reason) === self::CANCEL_COMMAND) {
            $this->replies->forget($chatId);
            $this->send($chatId, 'Listo, no se descartó. El borrador sigue pendiente.');

            return true;
        }

        if ($reason === '') {
            $this->send($chatId, 'Escribe el motivo en un mensaje de texto, o /cancelar.');

            return true;
        }

        if (mb_strlen($reason) > self::REASON_LENGTH) {
            $this->send($chatId, 'El motivo puede tener hasta '.self::REASON_LENGTH.' caracteres. Escríbelo más corto.');

            return true;
        }

        $this->replies->forget($chatId);

        if (! KnowledgeItem::canManage($user)) {
            $this->send($chatId, self::NOT_ALLOWED);

            return true;
        }

        $draft = KnowledgeDraft::query()->find((int) ($data['draft_id'] ?? 0));
        if ($draft === null || ! $draft->isPending()) {
            $this->send($chatId, self::DECIDED);

            return true;
        }

        TelegramAssistant::actingAs($user, fn () => $this->decisions->discard($draft, $user, $reason));

        $this->edit($chatId, $data['message_id'] ?? null, DraftMessage::text($draft, 'Descartado por ti · '.TelegramHtml::time(now())));
        $this->send($chatId, 'Listo, descarté el borrador.');

        return true;
    }

    private function approve(User $user, string $chatId, KnowledgeDraft $draft, ?int $messageId): string
    {
        $blocker = $this->decisions->blocker($draft, $user);
        if ($blocker !== null) {
            $this->send($chatId, $blocker === DraftDecisions::NEEDS_TITLE ? self::NEEDS_TITLE : self::NEEDS_COMPANY);

            return 'Falta información para aprobarlo.';
        }

        $visibility = (string) ($draft->proposed['visibility'] ?? 'it');
        $reviewAt = (string) ($draft->proposed['review_at'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewAt) !== 1) {
            $reviewAt = CarbonImmutable::today()->addMonthsNoOverflow(KnowledgeTypes::REVIEW_MONTHS)->toDateString();
        }

        $card = TelegramAssistant::actingAs($user, fn () => $this->decisions->approve(
            $draft,
            $user,
            in_array($visibility, KnowledgeTypes::VISIBILITIES, true) ? $visibility : 'it',
            $reviewAt,
        ));

        $this->edit($chatId, $messageId, DraftMessage::text($draft, 'Aprobado por ti · '.TelegramHtml::time(now())));
        $this->client->sendMessage(
            $chatId,
            "Ficha {$card->key} creada: ".TelegramHtml::link(route('assistant.knowledge.show', ['card' => $card->key]), $card->title).'. Desde ya la uso para responder.',
            [],
            TelegramHtml::OPTIONS,
        );

        return "Ficha {$card->key} creada.";
    }

    private function askReason(string $chatId, KnowledgeDraft $draft, ?int $messageId): string
    {
        $this->replies->put($chatId, self::REPLY_KIND, ['draft_id' => $draft->id, 'message_id' => $messageId]);

        $title = trim((string) ($draft->proposed['title'] ?? ''));
        $this->send($chatId, 'Escribe en un mensaje por qué descartas el borrador'.($title === '' ? '' : " «{$title}»").'. Si no quieres descartarlo, escribe '.self::CANCEL_COMMAND.'.');

        return 'Escribe el motivo.';
    }

    private function edit(string $chatId, mixed $messageId, string $html): void
    {
        if (is_int($messageId)) {
            $this->client->editMessageText($chatId, $messageId, $html, [], TelegramHtml::OPTIONS);
        }
    }

    private function send(string $chatId, string $text): void
    {
        $this->client->sendMessage($chatId, TelegramHtml::escape($text), [], TelegramHtml::OPTIONS);
    }
}
