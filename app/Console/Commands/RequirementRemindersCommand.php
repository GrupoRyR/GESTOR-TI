<?php

namespace App\Console\Commands;

use App\Actions\Ai\RemindRequirement;
use App\Domain\Ai\Week;
use App\Models\AiRequirement;
use App\Models\Scopes\CompanyScope;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Daily (08:00 Bogotá) reminder of pending requirements (PROMPT 6.4.3):
 * those due tomorrow or earlier (overdue included) whose recipient is
 * known, at most once every Settings `ai.requirements.reminder_every_days`
 * days (2) counting from the last reminder (manual "Recordar" included).
 */
class RequirementRemindersCommand extends Command
{
    protected $signature = 'ai:requirement-reminders';

    protected $description = 'Recuerda los requerimientos a usuarios que están por vencer o vencidos';

    public function handle(RemindRequirement $remind, Settings $settings): int
    {
        $every = max(1, (int) $settings->get('ai.requirements.reminder_every_days', 2));
        $today = Week::today();
        $count = 0;

        AiRequirement::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('status', AiRequirement::PENDING)
            ->whereNotNull('recipient_id')
            ->whereDate('due_date', '<=', $today->addDay()->toDateString())
            ->with(['recipient', 'project'])
            ->chunkById(100, function ($requirements) use ($remind, $every, $today, &$count): void {
                foreach ($requirements as $requirement) {
                    /** @var AiRequirement $requirement */
                    $last = $requirement->last_reminded_at?->copy()->timezone('America/Bogota')->startOfDay();

                    if ($last !== null && $last->greaterThan($today->subDays($every))) {
                        continue;
                    }

                    if ($requirement->recipient === null || ! $requirement->recipient->isActive()) {
                        continue;
                    }

                    $remind->handle($requirement);
                    $count++;
                }
            });

        $this->info("Recordatorios enviados: {$count}.");

        return self::SUCCESS;
    }
}
