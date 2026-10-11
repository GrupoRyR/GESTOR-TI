<?php

namespace App\Support\Ai\Presenters;

use App\Domain\Ai\VaultDueDates;
use App\Models\AiVaultAccessLog;
use App\Models\AiVaultItem;
use App\Support\Vault\AccessOrigin;

/**
 * Vault shapes of types/ai.ts. `item()` is metadata only: it never reads
 * `secret_ciphertext` (the only secret-carrying shape is `VaultRevealed`,
 * built by the reveal endpoint).
 */
final class VaultPresenter
{
    /** @var list<string> */
    public const RELATIONS = ['product', 'project'];

    /**
     * Design `VaultItem`. `expires_at` is the next due date: the expiry or
     * the scheduled rotation, whichever comes first (VaultDueDates::next).
     *
     * @return array<string, mixed>
     */
    public static function item(AiVaultItem $item): array
    {
        $next = VaultDueDates::next($item);

        return [
            'id' => $item->id,
            'name' => $item->name,
            'product' => $item->ownerLabel(),
            'environment' => $item->environment,
            'type' => $item->type,
            'username' => $item->username,
            'usage_url' => $item->url,
            'expires_at' => $next === null ? null : $next['date']->toDateString(),
            'expiry_note' => $item->expiry_note,
            'rotation_days' => $item->rotate_every_days,
            'notes' => $item->notes,
        ];
    }

    /**
     * Design `VaultAccessLog` (unlock/lock rows are not listed).
     *
     * @return array<string, mixed>
     */
    public static function log(AiVaultAccessLog $log): array
    {
        return [
            'id' => $log->id,
            'person' => $log->user === null
                ? ['id' => 0, 'name' => 'Persona eliminada', 'job_title' => null, 'avatar_url' => null]
                : RequestPresenter::person($log->user),
            'action' => AiVaultAccessLog::DESIGN_ACTIONS[$log->action] ?? $log->action,
            'item_name' => (string) ($log->item_name ?? $log->item?->name ?? ''),
            'at' => $log->created_at?->toIso8601String(),
            'from' => AccessOrigin::describe($log->user_agent ?? ($log->channel === 'console' ? 'console' : null), $log->ip),
        ];
    }
}
