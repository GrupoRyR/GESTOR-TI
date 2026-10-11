<?php

namespace App\Actions\Ai\Vault;

use App\Models\AiVaultItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Create, change and delete vault credentials (PROMPT 6.4.10), each one
 * logged and noticed through RecordVaultAccess. A new value on update
 * stamps `last_rotated_at` (the rotation clock restarts).
 *
 * Called by the item form (VaultItemController, C-VaultItemForm), which
 * checks `ai.vault.access` (middleware `vault`) and the open vault session.
 */
final class ManageVaultItems
{
    public function __construct(private readonly RecordVaultAccess $record) {}

    /**
     * @param  array<string, mixed>  $data  fillable metadata
     */
    public function create(User $actor, array $data, string $secret, ?Request $request = null): AiVaultItem
    {
        $item = DB::transaction(function () use ($actor, $data, $secret): AiVaultItem {
            $item = new AiVaultItem([...$data, 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id]);
            $item->setSecret($secret);
            $item->save();

            return $item;
        });

        $this->record->handle('create', $item, $actor, $request);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $data  fillable metadata to change
     */
    public function update(User $actor, AiVaultItem $item, array $data, ?string $secret = null, ?Request $request = null): AiVaultItem
    {
        $item->fill([...$data, 'updated_by_id' => $actor->id]);

        if ($secret !== null) {
            $item->setSecret($secret);
            $item->last_rotated_at = now();
        }

        $item->save();

        $this->record->handle('update', $item, $actor, $request);

        return $item;
    }

    public function delete(User $actor, AiVaultItem $item, ?Request $request = null): void
    {
        $item->forceFill(['updated_by_id' => $actor->id])->save();
        $item->delete();

        $this->record->handle('delete', $item, $actor, $request);
    }
}
