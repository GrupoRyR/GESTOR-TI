<?php

namespace App\Actions\Ai\Vault;

use App\Models\AiVaultAccessLog;
use App\Models\AiVaultItem;
use App\Models\User;
use App\Notifications\VaultAccessed;
use App\Services\Alerts\AlertService;
use App\Support\Vault\AccessOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Audit trail of the vault (PROMPT 6.4.10): every access is written to
 * `ai_vault_access_log` and to the activity log (log `ai`, event
 * `vault_{action}`, item name only), and the holders get a Telegram notice
 * (`VaultAccessed`) for view, copy, create, update and delete. Attempts
 * without permission (`denied`) raise a warning alert to the holders,
 * grouped per person and day. Nothing here ever receives a value.
 */
final class RecordVaultAccess
{
    /** Actions noticed to the holders by Telegram. */
    public const NOTIFIED = ['view', 'copy', 'create', 'update', 'delete'];

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(
        private readonly VaultHolders $holders,
        private readonly AlertService $alerts,
    ) {}

    public function handle(string $action, ?AiVaultItem $item, ?User $user, ?Request $request = null, string $channel = 'web'): AiVaultAccessLog
    {
        $log = AiVaultAccessLog::create([
            'ai_vault_item_id' => $item?->id,
            'item_name' => $item?->name,
            'user_id' => $user?->id,
            'action' => $action,
            'ip' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 255),
            'channel' => $channel,
            'created_at' => now(),
        ]);

        activity('ai')
            ->causedBy($user)
            ->event("vault_{$action}")
            ->withProperties(['item_id' => $item?->id, 'item' => $item?->name, 'channel' => $channel])
            ->log($item === null ? "Bóveda: {$action}" : "Bóveda: {$action} {$item->name}");

        if (in_array($action, self::NOTIFIED, true) && $item !== null) {
            $holders = $this->holders->all();

            if ($holders->isNotEmpty()) {
                Notification::send($holders, new VaultAccessed(
                    $action,
                    $item->id,
                    $item->name,
                    $user?->name ?? 'Alguien',
                    AccessOrigin::describe($log->user_agent ?? ($request === null ? 'console' : null), $log->ip),
                    self::when(),
                ));
            }
        }

        return $log;
    }

    /**
     * Somebody without `ai.vault.access` opened a vault URL.
     */
    public function denied(User $user, ?AiVaultItem $item, Request $request): AiVaultAccessLog
    {
        $log = $this->handle('denied', $item, $user, $request);
        $from = AccessOrigin::describe($log->user_agent, $log->ip);

        $this->alerts->raise(
            level: 'warning',
            source: 'vault',
            title: "Intento de entrar a la bóveda: {$user->name}",
            body: "{$user->name} intentó abrir la bóveda sin permiso desde {$from}, ".self::when().'.',
            subject: $user,
            groupKey: "ai.vault.denied:{$user->id}:".now()->timezone('America/Bogota')->toDateString(),
            recipients: $this->holders->all(),
        );

        return $log;
    }

    /**
     * "7 oct 2026, 09:40" (Bogotá).
     */
    private static function when(): string
    {
        $now = now()->timezone('America/Bogota');

        return $now->day.' '.self::MONTHS[$now->month - 1].' '.$now->year.', '.$now->format('H:i');
    }
}
