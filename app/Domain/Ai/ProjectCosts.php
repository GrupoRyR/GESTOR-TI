<?php

namespace App\Domain\Ai;

use App\Models\AiCostRate;
use App\Models\AiInteraction;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiProjectCost;
use App\Models\AiTimeEntry;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Real cost of a project (PROMPT 6.4.11), as the rows of the design
 * `ProjectCost` (only for holders of `ai.costs.view`):
 *
 * - `team` (computed): Σ hours × the rate in force for the person on the
 *   entry's date (`ai_cost_rates`); people without a rate count 0.
 * - Costs entered by hand (`ai_project_costs`), newest first.
 * - `ai_usage` (computed): one row per month with spend in
 *   `ai_interactions` whose source is the project or its product, from the
 *   month the project was approved (or created). USD → COP with Settings
 *   `ai.usd_cop_rate`.
 *
 * The real cost is the sum of the rows, as the costs page adds them
 * (`costsTotal`): a monthly cost counts its amount once.
 */
final class ProjectCosts
{
    /** Minutes a signed receipt link stays valid. */
    private const LINK_MINUTES = 30;

    public const AI_DETAIL = 'Se suma solo desde el registro de interacciones (ai_interactions); no se edita a mano';

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return Collection<int, AiTimeEntry>
     */
    private function entries(AiProject $project): Collection
    {
        return $project->timeEntries()->get(['id', 'user_id', 'date', 'hours']);
    }

    /**
     * Team cost in COP.
     */
    public function teamCost(AiProject $project): int
    {
        return $this->teamCostOf($this->entries($project));
    }

    /**
     * @param  Collection<int, AiTimeEntry>  $entries
     */
    private function teamCostOf(Collection $entries): int
    {
        $rates = AiCostRate::query()
            ->whereIn('user_id', $entries->pluck('user_id')->unique()->values()->all())
            ->orderByDesc('effective_from')
            ->get(['user_id', 'rate', 'effective_from'])
            ->groupBy('user_id');

        $total = 0.0;

        foreach ($entries as $entry) {
            $date = $entry->date->toDateString();
            $rate = $rates->get($entry->user_id)?->first(fn (AiCostRate $row) => $row->effective_from->toDateString() <= $date);
            $total += (float) $entry->hours * (int) ($rate?->rate ?? 0);
        }

        return (int) round($total);
    }

    /**
     * AI spend per month in COP, newest first ('2026-10' => 46000).
     *
     * @return array<string, int>
     */
    public function aiUsageByMonth(AiProject $project): array
    {
        $since = CarbonImmutable::parse(($project->approved_at ?? $project->created_at)->toDateTimeString(), BusinessDays::TIMEZONE)
            ->min(CarbonImmutable::parse(($project->start_date ?? $project->created_at)->toDateString(), BusinessDays::TIMEZONE))
            ->startOfMonth();
        $rate = (float) $this->settings->get('ai.usd_cop_rate', 4000);

        $months = AiInteraction::query()
            ->where(function ($query) use ($project): void {
                $query->where(fn ($q) => $q->where('source_type', $project->getMorphClass())->where('source_id', $project->id));

                if ($project->product_id !== null) {
                    $query->orWhere(fn ($q) => $q->where('source_type', (new AiProduct)->getMorphClass())->where('source_id', $project->product_id));
                }
            })
            ->where('created_at', '>=', $since)
            ->get(['cost', 'created_at'])
            ->groupBy(fn (AiInteraction $interaction) => $interaction->created_at->format('Y-m'))
            ->map(fn (Collection $rows) => (int) round($rows->sum(fn (AiInteraction $row) => (float) $row->cost) * $rate))
            ->filter(fn (int $amount) => $amount > 0)
            ->sortKeysDesc()
            ->all();

        return $months;
    }

    /**
     * Real cost in COP (sum of the rows).
     */
    public function total(AiProject $project): int
    {
        return $this->teamCost($project)
            + (int) $project->costs()->sum('amount')
            + array_sum($this->aiUsageByMonth($project));
    }

    /**
     * Rows of the design `ProjectCost`: team, costs by hand, AI per month.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(AiProject $project): array
    {
        $rows = [];
        $entries = $project->timeEntries()->with('user')->get(['id', 'user_id', 'date', 'hours']);
        $hours = round($entries->sum(fn (AiTimeEntry $entry) => (float) $entry->hours), 2);

        if ($hours > 0) {
            $people = $entries->groupBy('user_id')
                ->map(fn (Collection $rows) => ['name' => (string) $rows->first()->user?->name, 'hours' => round($rows->sum(fn (AiTimeEntry $e) => (float) $e->hours), 2)])
                ->sortByDesc('hours')
                ->map(fn (array $row) => $row['name'].' '.self::hours($row['hours']))
                ->implode(' · ');

            $rows[] = [
                'id' => -1,
                'type' => 'team',
                'concept' => 'Horas del equipo',
                'detail' => self::hours($hours).' con el costo por hora interno de cada persona · '.$people,
                'recurrence' => 'computed',
                'amount' => $this->teamCostOf($entries),
                'date' => Week::today()->toDateString(),
                'attachment' => null,
                'editable' => false,
            ];
        }

        foreach ($project->costs()->orderByDesc('date')->orderByDesc('id')->get() as $cost) {
            $rows[] = $this->row($project, $cost);
        }

        foreach ($this->aiUsageByMonth($project) as $month => $amount) {
            $date = CarbonImmutable::parse($month.'-01', BusinessDays::TIMEZONE);

            $rows[] = [
                'id' => -(int) str_replace('-', '', $month),
                'type' => 'ai_usage',
                'concept' => 'Interacciones con IA del proyecto en '.$date->locale('es')->translatedFormat('F'),
                'detail' => self::AI_DETAIL,
                'recurrence' => 'monthly',
                'amount' => $amount,
                'date' => $date->toDateString(),
                'attachment' => null,
                'editable' => false,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function row(AiProject $project, AiProjectCost $cost): array
    {
        return [
            'id' => $cost->id,
            'type' => $cost->type,
            'concept' => $cost->concept,
            'detail' => null,
            'recurrence' => $cost->recurrence,
            'amount' => $cost->amount,
            'date' => $cost->date->toDateString(),
            'attachment' => $cost->attachment_path === null ? null : [
                'id' => $cost->id,
                'name' => (string) $cost->attachment_name,
                'size' => (int) $cost->attachment_size,
                'mime' => (string) $cost->attachment_mime,
                'url' => URL::temporarySignedRoute('ai.costs.attachment', now()->addMinutes(self::LINK_MINUTES), [
                    'project' => $project->key,
                    'cost' => $cost->id,
                ]),
            ],
            'editable' => true,
        ];
    }

    /**
     * Hours per person on the whole project, most first.
     *
     * @return list<array{person: array<string, mixed>, hours: int|float}>
     */
    public static function hoursByPerson(AiProject $project): array
    {
        return $project->timeEntries()
            ->with('user.employee')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $entries) => [
                'user' => $entries->first()->user,
                'hours' => round($entries->sum(fn (AiTimeEntry $entry) => (float) $entry->hours), 2),
            ])
            ->filter(fn (array $row) => $row['user'] instanceof User)
            ->sortByDesc('hours')
            ->map(fn (array $row) => [
                'person' => RequestPresenter::person($row['user']),
                'hours' => RequestPresenter::number($row['hours']) ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * "92 h", "1,5 h".
     */
    public static function hours(float $hours): string
    {
        return str_replace('.', ',', (string) (RequestPresenter::number(round($hours, 2)) ?? 0)).' h';
    }
}
