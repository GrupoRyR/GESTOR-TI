<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One access to the vault (PROMPT 6.4.10): append-only. Actions: view, copy,
 * create, update, delete, denied (no permission), unlock and lock (channel
 * `auto` for the 10-minute auto-lock). Written by
 * App\Actions\Ai\Vault\RecordVaultAccess; never stores a value.
 *
 * @property int $id
 * @property ?int $ai_vault_item_id
 * @property ?string $item_name
 * @property ?int $user_id
 * @property string $action
 * @property ?string $ip
 * @property ?string $user_agent
 * @property string $channel
 * @property ?Carbon $created_at
 */
#[Fillable(['ai_vault_item_id', 'item_name', 'user_id', 'action', 'ip', 'user_agent', 'channel', 'created_at'])]
class AiVaultAccessLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['view', 'copy', 'create', 'update', 'delete', 'denied', 'unlock', 'lock'];

    /** Stored action => design `VaultAction` (unlock and lock are not listed on the page). */
    public const DESIGN_ACTIONS = [
        'view' => 'viewed',
        'copy' => 'copied',
        'update' => 'changed',
        'create' => 'created',
        'delete' => 'deleted',
        'denied' => 'blocked',
    ];

    protected $table = 'ai_vault_access_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiVaultItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(AiVaultItem::class, 'ai_vault_item_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
