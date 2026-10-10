<?php

namespace App\Actions\Ai;

use App\Domain\Ai\InvalidTransition;
use App\Models\AiRequirement;
use App\Notifications\AiRequirementReminder;

/**
 * Reminder of a pending requirement to its recipient ("Recordar" on the
 * requirements page and Mi semana, and `ai:requirement-reminders`):
 * notification plus `reminders_sent` / `last_reminded_at`.
 */
class RemindRequirement
{
    public function handle(AiRequirement $requirement): void
    {
        if (! $requirement->isOpen()) {
            throw new InvalidTransition('Este requerimiento ya fue respondido.');
        }

        $recipient = $requirement->recipient;

        if ($recipient === null || ! $recipient->isActive()) {
            throw new InvalidTransition('Aún no sabemos a quién pedírselo: elige la persona antes de recordar.');
        }

        $recipient->notify(AiRequirementReminder::for($requirement));

        $requirement->forceFill([
            'reminders_sent' => $requirement->reminders_sent + 1,
            'last_reminded_at' => now(),
        ])->save();
    }
}
