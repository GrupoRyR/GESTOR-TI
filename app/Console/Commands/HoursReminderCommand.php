<?php

namespace App\Console\Commands;

use App\Domain\Ai\TriagePeople;
use App\Domain\Ai\Week;
use App\Models\AiProject;
use App\Models\AiProjectMember;
use App\Models\AiTimeEntry;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Notifications\AiHoursReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Friday 15:00 Bogotá (PROMPT 6.4.11): reminds the IT team (`ai.manage`)
 * and the people of active projects (lead and members of projects
 * planned, in development or in testing) who logged no hours from Monday
 * to today. Deactivated users are skipped.
 */
class HoursReminderCommand extends Command
{
    protected $signature = 'ai:hours-reminder';

    protected $description = 'Recuerda cargar horas a quien no registró tiempo en la semana';

    public function handle(TriagePeople $people): int
    {
        $today = Week::today();
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);

        $projects = AiProject::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('status', ['planned', 'in_development', 'in_testing'])
            ->get(['id', 'lead_id']);

        $ids = $people->team()->modelKeys();
        $ids = [...$ids, ...$projects->pluck('lead_id')->filter()->all()];
        $ids = [...$ids, ...AiProjectMember::query()->whereIn('ai_project_id', $projects->modelKeys())->pluck('user_id')->all()];

        $logged = AiTimeEntry::query()
            ->whereDate('date', '>=', $monday->toDateString())
            ->whereDate('date', '<=', $today->toDateString())
            ->distinct()
            ->pluck('user_id')
            ->all();

        $users = User::query()
            ->whereIn('id', array_values(array_diff(array_unique(array_map('intval', $ids)), array_map('intval', $logged))))
            ->where('active', true)
            ->get();

        foreach ($users as $user) {
            $user->notify(new AiHoursReminder);
        }

        $this->info("Recordatorios de horas enviados: {$users->count()}.");

        return self::SUCCESS;
    }
}
