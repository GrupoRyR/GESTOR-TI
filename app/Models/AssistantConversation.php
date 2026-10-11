<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat thread of the Asistente TI (PROMPT 6.8.2). Private to its owner:
 * every route resolves it through `ownedBy()` (others get 404). The
 * assistant keeps 30 minutes of context (`gestor.assistant.context_minutes`).
 *
 * @property int $id
 * @property int $user_id
 * @property string $channel
 * @property ?string $telegram_chat_id
 * @property string $title
 * @property CarbonImmutable $last_activity_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'channel', 'telegram_chat_id', 'title', 'last_activity_at'])]
class AssistantConversation extends Model
{
    public const CHANNELS = ['app', 'telegram'];

    public const DEFAULT_TITLE = 'Nueva conversación';

    public const TITLE_LENGTH = 80;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_activity_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<AssistantConversation>  $query
     */
    public function scopeOwnedBy($query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AssistantMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id');
    }

    /**
     * The first question names the conversation ("Informe de ventas diario").
     */
    public static function titleFrom(string $question): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $question));
        $text = rtrim($text, ' ?¿!¡.');
        $text = ltrim($text, '¿¡ ');

        if (mb_strlen($text) > self::TITLE_LENGTH) {
            $cut = mb_substr($text, 0, self::TITLE_LENGTH);
            $space = mb_strrpos($cut, ' ');
            $text = rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, ' ,').'…';
        }

        return $text === '' ? self::DEFAULT_TITLE : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
