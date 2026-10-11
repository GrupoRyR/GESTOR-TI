<?php

namespace App\Models;

use App\Support\Vault\VaultEncrypter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A credential of the vault (PROMPT 6.4.10): API keys, database users,
 * cPanel/Vercel accesses, Microsoft app secrets, service accounts, webhooks.
 *
 * The value lives only in `secret_ciphertext`, encrypted with the vault key
 * (App\Support\Vault\VaultEncrypter), never with APP_KEY and never through
 * `$casts`. It is not fillable, it is `$hidden` from every serialization
 * (arrays, JSON, Inertia props, exports, search, briefs, MCP, mails) and
 * the model does not log activity: the only way out is `revealSecret()`,
 * called by the reveal endpoint after reauthentication. Changes and reads go
 * through App\Actions\Ai\Vault\* so each one is logged and noticed.
 *
 * Not company-scoped: only the vault holder (`ai.vault.access`) sees it.
 *
 * @property int $id
 * @property string $name
 * @property ?int $ai_project_id
 * @property ?int $ai_product_id
 * @property string $environment
 * @property string $type
 * @property ?string $username
 * @property string $secret_ciphertext
 * @property ?string $url
 * @property ?Carbon $expires_at
 * @property ?string $expiry_note
 * @property ?int $rotate_every_days
 * @property ?Carbon $last_rotated_at
 * @property ?string $notes
 * @property ?int $created_by_id
 * @property ?int $updated_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'name', 'ai_project_id', 'ai_product_id', 'environment', 'type', 'username', 'url', 'expires_at', 'expiry_note',
    'rotate_every_days', 'last_rotated_at', 'notes', 'created_by_id', 'updated_by_id',
])]
#[Hidden(['secret_ciphertext'])]
class AiVaultItem extends Model
{
    use SoftDeletes;

    public const ENVIRONMENTS = ['production', 'testing'];

    /** Design `VaultSecretType`. */
    public const TYPES = ['api_key', 'user_password', 'connection_string', 'token'];

    /** Design `VaultItem.product` of an item without product or project. */
    public const NO_OWNER = 'Sin proyecto ni producto';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'last_rotated_at' => 'datetime',
            'rotate_every_days' => 'integer',
        ];
    }

    /**
     * Encrypts the value with the vault key (fails closed without it).
     */
    public function setSecret(string $value): static
    {
        $this->attributes['secret_ciphertext'] = VaultEncrypter::fromConfig()->encrypt($value);

        return $this;
    }

    /**
     * The plain value. Only for the reveal endpoint.
     *
     * @throws DecryptException
     */
    public function revealSecret(): string
    {
        return VaultEncrypter::fromConfig()->decrypt((string) $this->getRawOriginal('secret_ciphertext', $this->attributes['secret_ciphertext'] ?? ''));
    }

    /**
     * @return BelongsTo<AiProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'ai_project_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<AiProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(AiProduct::class, 'ai_product_id')->withTrashed();
    }

    /**
     * @return HasMany<AiVaultAccessLog, $this>
     */
    public function accessLog(): HasMany
    {
        return $this->hasMany(AiVaultAccessLog::class, 'ai_vault_item_id');
    }

    /**
     * Design `VaultItem.product`: the product, else the project, else a dash.
     */
    public function ownerLabel(): string
    {
        return $this->product?->name ?? $this->project?->name ?? self::NO_OWNER;
    }
}
