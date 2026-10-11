<?php

namespace App\Domain\Assistant;

use App\Domain\Ai\SecretGuard;
use App\Models\KnowledgeDraft;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * `/aprender` (PROMPT 6.8.1): "the only write the assistant makes". Stores
 * the raw text as a pending draft with the card a DraftParser proposes
 * (C2: HeuristicDraftParser, no AI). Nothing is published until someone
 * with `assistant.knowledge.manage` approves it
 * (App\Actions\Assistant\DraftDecisions).
 *
 * Used by the chat (C3, source `app`) and the Telegram bot (C4, source
 * `telegram` with the chat and message ids to edit the draft message).
 */
final class LearnDrafts
{
    public const MAX_LENGTH = 2000;

    public function __construct(
        private readonly DraftParser $parser,
        private readonly SecretGuard $guard,
    ) {}

    /**
     * @throws DraftRejected empty, too long or with a password
     */
    public function fromText(User $author, string $text, string $source = 'app', ?string $telegramChatId = null, ?int $telegramMessageId = null): KnowledgeDraft
    {
        $text = trim($text);

        if ($text === '') {
            throw new DraftRejected('Escribe qué quieres que aprenda después de /aprender.');
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new DraftRejected('El texto es muy largo: máximo '.number_format(self::MAX_LENGTH, 0, ',', '.').' caracteres.');
        }
        if ($this->guard->find($text) !== null) {
            throw new DraftRejected('Parece una contraseña o una llave. Las credenciales van en Accesos o en la bóveda, nunca en una ficha.');
        }

        $proposed = $this->parser->parse($text, $author);
        $proposed['review_at'] = CarbonImmutable::today()->addMonthsNoOverflow(KnowledgeTypes::REVIEW_MONTHS)->toDateString();

        return KnowledgeDraft::query()->create([
            'message' => $text,
            'source' => in_array($source, KnowledgeDraft::SOURCES, true) ? $source : 'app',
            'requested_by_id' => $author->id,
            'proposed' => $proposed,
            'missing' => self::missing($proposed),
            'status' => 'pending',
            'telegram_chat_id' => $telegramChatId,
            'telegram_message_id' => $telegramMessageId,
        ]);
    }

    /**
     * Required type fields without a value, then the owner.
     *
     * @param  array<string, mixed>  $proposed
     * @return list<string>
     */
    public static function missing(array $proposed): array
    {
        $missing = [];
        $fields = KnowledgeTypes::normalize((string) $proposed['type'], (array) ($proposed['fields'] ?? []));

        foreach (KnowledgeTypes::fields((string) $proposed['type']) as $key => [, $required]) {
            if ($required && ($fields[$key] === null || $fields[$key] === [])) {
                $missing[] = $key;
            }
        }

        if (($proposed['owner_id'] ?? null) === null) {
            $missing[] = 'owner';
        }

        return $missing;
    }
}
