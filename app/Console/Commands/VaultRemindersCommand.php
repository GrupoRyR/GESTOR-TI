<?php

namespace App\Console\Commands;

use App\Actions\Ai\Vault\VaultHolders;
use App\Domain\Ai\VaultDueDates;
use App\Models\AiVaultItem;
use App\Notifications\VaultReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Vault reminders (PROMPT 6.4.10), daily: a credential whose expiry date or
 * scheduled rotation (VaultDueDates) falls exactly 30, 15 or 7 days from
 * today (Bogotá) sends `VaultReminder` to the vault holders. One run per
 * day, so each threshold is noticed once; names and dates only.
 */
class VaultRemindersCommand extends Command
{
    protected $signature = 'ai:vault-reminders';

    protected $description = 'Avisa al dueño de la bóveda 30, 15 y 7 días antes de que una credencial venza o toque rotarla';

    public function handle(VaultHolders $holders): int
    {
        $recipients = $holders->all();
        $today = VaultDueDates::today();
        $sent = 0;

        if ($recipients->isEmpty()) {
            $this->info('Nadie tiene acceso a la bóveda: no hay a quién avisar.');

            return self::SUCCESS;
        }

        foreach (AiVaultItem::query()->orderBy('id')->get() as $item) {
            foreach (VaultDueDates::all($item) as $kind => $date) {
                $days = VaultDueDates::daysUntil($date, $today);

                if (in_array($days, VaultDueDates::REMINDER_DAYS, true)) {
                    Notification::send($recipients, new VaultReminder($item->id, $item->name, $kind, $days, $date->toDateString()));
                    $sent++;
                }
            }
        }

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }
}
