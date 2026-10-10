<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Personal token of the MCP connector (Profile/Mcp, Claude Code and
 * scripts). The value (`gti_mcp_` + 40 random url-safe characters) exists
 * only in memory when it is issued: the table keeps its SHA-256 and the
 * last four characters. It works until the end of `expires_at` (Bogotá)
 * unless revoked.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $scope
 * @property string $token_hash
 * @property string $last_four
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_client
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property Carbon $created_at
 * @property-read User $user
 */
class McpToken extends Model
{
    public const PREFIX = 'gti_mcp_';

    public const RANDOM_LENGTH = 40;

    public const SCOPE_READ = 'read';

    public const SCOPE_READ_WRITE = 'read_write';

    public const SCOPES = [self::SCOPE_READ, self::SCOPE_READ_WRITE];

    /** Expiry choices of "Crear token" (types/mcp.ts McpExpiryDays). */
    public const EXPIRY_DAYS = [30, 90, 180];

    /** Calendar of `expires_at`. */
    public const TIMEZONE = 'America/Bogota';

    protected $fillable = ['user_id', 'name', 'scope', 'token_hash', 'last_four', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Creates a token and returns it with its value (the only time the
     * value exists).
     *
     * @return array{token: McpToken, value: string}
     */
    public static function issue(User $user, string $name, string $scope, int $days): array
    {
        $value = self::PREFIX.self::randomPart();

        $token = self::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'scope' => $scope,
            'token_hash' => self::hashOf($value),
            'last_four' => substr($value, -4),
            'expires_at' => now(self::TIMEZONE)->addDays($days)->toDateString(),
        ]);

        return ['token' => $token, 'value' => $value];
    }

    public static function hashOf(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * The token whose value is `$value` (looked up by its hash, then
     * compared in constant time), whatever its state.
     */
    public static function findByValue(string $value): ?self
    {
        if (! str_starts_with($value, self::PREFIX)) {
            return null;
        }

        $hash = self::hashOf($value);
        $token = self::query()->where('token_hash', $hash)->first();

        return $token instanceof self && hash_equals($token->token_hash, $hash) ? $token : null;
    }

    /**
     * Cryptographically secure url-safe characters (`A-Z a-z 0-9 - _`).
     */
    private static function randomPart(): string
    {
        return substr(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 0, self::RANDOM_LENGTH);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return now(self::TIMEZONE)->toDateString() > $this->expires_at->toDateString();
    }

    public function canWrite(): bool
    {
        return $this->scope === self::SCOPE_READ_WRITE;
    }

    /**
     * Not revoked and not past its date.
     *
     * @param  Builder<McpToken>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->whereDate('expires_at', '>=', now(self::TIMEZONE)->toDateString());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(McpCall::class);
    }

    /** Name as typed, trimmed and with inner spaces collapsed. */
    public static function normalizeName(string $name): string
    {
        return Str::squish($name);
    }
}
