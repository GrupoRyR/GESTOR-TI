<?php

namespace App\Domain\Assistant;

use App\Models\KnowledgeItem;
use App\Models\UnansweredAsk;
use App\Models\UnansweredQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Preguntas sin respuesta" (PROMPT 6.8.1): every time the assistant
 * answers "No lo tengo documentado" (C3 chat, C4 Telegram) it calls
 * `record()`. The same question (same normalized text: no accents, case,
 * punctuation or extra spaces) counts again on its open row; a question
 * already documented or dismissed opens a new row when asked again.
 * The list says what the knowledge base lacks.
 */
final class Unanswered
{
    public function record(?User $user, string $question, string $channel = 'app', ?int $conversationId = null, ?int $messageId = null): UnansweredQuestion
    {
        $question = trim((string) preg_replace('/\s+/u', ' ', $question));
        $hash = self::hash($question);
        $now = now();

        return DB::transaction(function () use ($user, $question, $hash, $now, $channel, $conversationId, $messageId): UnansweredQuestion {
            $row = UnansweredQuestion::query()->open()->where('hash', $hash)->lockForUpdate()->first()
                ?? UnansweredQuestion::query()->create([
                    'question' => mb_substr($question, 0, 2000),
                    'hash' => $hash,
                    'first_asked_at' => $now,
                    'last_asked_at' => $now,
                ]);

            $row->forceFill(['times_asked' => $row->times_asked + 1, 'last_asked_at' => $now])->save();

            $row->asks()->create([
                'user_id' => $user?->id,
                'channel' => in_array($channel, UnansweredAsk::CHANNELS, true) ? $channel : 'app',
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'asked_at' => $now,
            ]);

            return $row;
        });
    }

    /**
     * Grouping key: lowercase, no accents, only letters and digits.
     */
    public static function hash(string $question): string
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($question))));

        return hash('sha256', $normalized);
    }

    /**
     * The card that answers it ("Ya está documentada" or a new card).
     */
    public function document(UnansweredQuestion $question, KnowledgeItem $card, User $by): void
    {
        $question->forceFill([
            'status' => 'documented',
            'knowledge_item_id' => $card->id,
            'resolved_by_id' => $by->id,
            'resolved_at' => now(),
        ])->save();

        $this->log($by, $question, 'unanswered_documented', "enlazó la pregunta a {$card->key}");
    }

    public function dismiss(UnansweredQuestion $question, User $by): void
    {
        $question->forceFill([
            'status' => 'dismissed',
            'resolved_by_id' => $by->id,
            'resolved_at' => now(),
        ])->save();

        $this->log($by, $question, 'unanswered_dismissed', 'descartó una pregunta sin respuesta');
    }

    private function log(User $by, UnansweredQuestion $question, string $event, string $description): void
    {
        activity('knowledge')
            ->causedBy($by)
            ->performedOn($question)
            ->event($event)
            ->withProperties(['question_id' => $question->id])
            ->log($description);
    }
}
