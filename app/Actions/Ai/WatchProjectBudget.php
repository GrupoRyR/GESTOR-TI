<?php

namespace App\Actions\Ai;

use App\Domain\Ai\BudgetRules;
use App\Domain\Ai\ProjectBudget;
use App\Domain\Ai\TriagePeople;
use App\Models\AiProject;
use App\Models\Alert;
use App\Models\Company;
use App\Models\User;
use App\Notifications\AiBudgetAlert;
use App\Services\Alerts\AlertService;

/**
 * Budget alerts (PROMPT 6.4.11 "alerta a los aprobadores al pasar el 80 %
 * y el 100 % de lo aprobado"), run after every change of hours or costs.
 *
 * - Ratio = the highest of real ÷ estimated hours and cost (only parts with
 *   an estimate); projects without an estimate never alert.
 * - 80 % (`warning`, ratio ≥ 0.8) and 100 % (`critical`, ratio > 1), the
 *   BudgetRules states. Each crossing opens an alert of AlertService with
 *   the group key `ai.budget:{P-n}:{80|100}` (no AlertService notice: the
 *   approvers get AiBudgetAlert, event `ai.project.budget`) and an
 *   "Actividad" entry. While that alert stays unresolved nothing is sent
 *   again; going back below the line resolves it, so a new crossing alerts
 *   again (e.g. after an approved re-approval raises the estimate).
 */
class WatchProjectBudget
{
    public const LEVELS = [80, 100];

    public function __construct(
        private readonly ProjectBudget $budget,
        private readonly AlertService $alerts,
        private readonly TriagePeople $people,
    ) {}

    public static function groupKey(AiProject $project, int $level): string
    {
        return "ai.budget:{$project->key}:{$level}";
    }

    public function handle(AiProject $project): void
    {
        $figures = $this->budget->figures($project);

        if (! $figures['has_estimate']) {
            return;
        }

        $hours = $figures['estimate_hours'] > 0 ? BudgetRules::ratio($figures['actual_hours'], $figures['estimate_hours']) : 0.0;
        $cost = $figures['estimate_cost'] > 0 ? BudgetRules::ratio($figures['actual_cost'], $figures['estimate_cost']) : 0.0;
        $ratio = max($hours, $cost);

        foreach (self::LEVELS as $level) {
            $key = self::groupKey($project, $level);
            $crossed = $level === 80 ? $ratio >= BudgetRules::WARNING_RATIO : $ratio > 1;

            if (! $crossed) {
                $this->alerts->resolve($key);

                continue;
            }

            if (Alert::query()->where('group_key', $key)->unresolved()->exists()) {
                continue;
            }

            $notice = new AiBudgetAlert($project->key, $project->name, $level, self::percent($hours), self::percent($cost),
                $figures['actual_hours'], $figures['estimate_hours']);
            $notice->forCompany($project->company_id);

            $this->alerts->raise(
                level: $level === 80 ? Alert::LEVEL_WARNING : Alert::LEVEL_CRITICAL,
                source: 'ai',
                title: $notice->title(),
                body: $notice->body(),
                company: Company::query()->find($project->company_id),
                subject: $project,
                groupKey: $key,
                recipients: [],
            );

            $project->recordActivity('budget', $level === 80
                ? 'Aviso del 80 % de lo aprobado enviado a los aprobadores'
                : 'Aviso: se pasó el 100 % de lo aprobado; avisamos a los aprobadores');

            $this->people->approvers()->each(fn (User $approver) => $approver->notify(clone $notice));
        }
    }

    /**
     * Resolves both alerts (new baseline after a re-approval).
     */
    public function reset(AiProject $project): void
    {
        foreach (self::LEVELS as $level) {
            $this->alerts->resolve(self::groupKey($project, $level));
        }
    }

    private static function percent(float $ratio): ?int
    {
        return $ratio > 0 && is_finite($ratio) ? (int) round($ratio * 100) : null;
    }
}
