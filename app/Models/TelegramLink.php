<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Telegram chat linked to a user (one per user, one user per chat).
 *
 * Linking: the user generates a single-use token (`issueToken()`, valid
 * `TOKEN_TTL_MINUTES`), opens `https://t.me/{bot}?start={token}` and the bot
 * webhook consumes it (`App\Telegram\UpdateHandler`). Only the SHA-256 of
 * the token is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property ?string $chat_id
 * @property ?string $telegram_username
 * @property ?string $link_token
 * @property ?Carbon $token_expires_at
 * @property ?Carbon $linked_at
 */
#[Fillable(['user_id', 'chat_id', 'telegram_username', 'link_token', 'token_expires_at', 'linked_at'])]
#[Hidden(['link_token'])]
class TelegramLink extends Model
{
    public const TOKEN_TTL_MINUTES = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'linked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLinked(): bool
    {
        return $this->chat_id !== null && $this->linked_at !== null;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Creates (or replaces) the pending link token of the user and returns
     * the plain token. An already linked chat stays linked until the new
     * token is used.
     */
    public static function issueToken(User $user): string
    {
        $token = Str::random(40);

        static::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'link_token' => self::hashToken($token),
                'token_expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
            ],
        );

        return $token;
    }

    /**
     * Pending link whose token matches and has not expired.
     */
    public static function findByValidToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return static::query()
            ->where('link_token', self::hashToken($token))
            ->where('token_expires_at', '>', now())
            ->first();
    }

    public static function findLinkedChat(string $chatId): ?self
    {
        return static::query()
            ->where('chat_id', $chatId)
            ->whereNotNull('linked_at')
            ->first();
    }
}
