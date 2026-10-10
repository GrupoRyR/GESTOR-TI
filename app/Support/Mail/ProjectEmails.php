<?php

namespace App\Support\Mail;

use App\Domain\Ai\BudgetRules;
use App\Domain\Ai\Money;
use App\Domain\Ai\TriagePeople;
use App\Enums\Ai\RequestStatus;
use App\Mail\TemplateMail;
use App\Models\AiRequest;
use App\Models\AiRequirement;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/**
 * Builds the Gestor IA project emails from the approved templates:
 *
 * - `requirement`, to the person outside IT who must answer it (when it is
 *   registered and on each reminder). It never asks for keys by email:
 *   `asks_for_secrets` shows the "No envíes las claves por correo" warning
 *   when the requirement mentions keys, passwords, tokens or credentials.
 * - `project-report` data from the report content (App\Domain\Ai\
 *   ProjectReportContent). The time-and-cost variables are added only when
 *   the content carries costs (the recipient may see them); the other group
 *   gets data without any of them (TemplateMail drops them as well).
 */
final class ProjectEmails
{
    private const SECRETS = '/\b(claves?|contraseñas?|credenciales?|passwords?|tokens?|secretos?|llaves?|api ?keys?)\b/iu';

    private const ORDINALS = [1 => 'primero', 2 => 'segundo', 3 => 'tercero', 4 => 'cuarto', 5 => 'quinto', 6 => 'sexto', 7 => 'séptimo', 8 => 'octavo', 9 => 'noveno', 10 => 'décimo'];

    private const REPORT_PHASES = [1 => 'Evaluación', 2 => 'Aprobación', 3 => 'Plan', 4 => 'Construcción', 5 => 'Entrega'];

    private const NO_APPROVERS = 'los aprobadores de proyectos';

    /**
     * `requirement`. `$reminder` is the number of this reminder (1 = first),
     * null for the notice sent when the requirement is registered.
     */
    public static function requirement(AiRequirement $requirement, ?int $reminder = null): TemplateMail
    {
        $requirement->loadMissing(['project.company', 'project.area', 'requestedBy', 'blocksTask', 'company']);
        $project = $requirement->project;
        /** @var AiRequest|null $request */
        $request = $project?->requests()->with('area')->orderBy('ai_requests.id')->first();

        $today = CarbonImmutable::now(MailDates::TIMEZONE)->startOfDay();
        $due = CarbonImmutable::parse($requirement->due_date->toDateString(), MailDates::TIMEZONE);
        $created = CarbonImmutable::parse(($requirement->created_at ?? now())->copy()->setTimezone(MailDates::TIMEZONE)->toDateString(), MailDates::TIMEZONE);
        $overdue = $requirement->displayStatus($today) === AiRequirement::OVERDUE;
        $late = (int) $due->diffInDays($today);
        $left = (int) $today->diffInDays($due);
        $task = $requirement->blocksTask;

        return TemplateMail::make(EmailTemplate::Requirement, $project?->company ?? $requirement->company, [
            'action_url' => Route::has('ai.requirements.respond')
                ? route('ai.requirements.respond', $requirement->id)
                : route('ai.projects.show', (string) $project?->key),
            'project_key' => (string) $project?->key,
            'request_key' => (string) ($request?->key ?? $project?->key),
            'request_title' => (string) ($request?->title ?? $project?->name),
            'area_name' => (string) ($request?->area?->name ?? $project?->area?->name ?? $requirement->area_name),
            'requirement_title' => self::lowerFirst($requirement->title),
            'requested_by_name' => (string) ($requirement->requestedBy?->name ?? 'Una persona'),
            'is_overdue' => $overdue,
            'days_waiting' => max(0, (int) $created->diffInDays($today)),
            'due_date' => MailDates::day($due),
            'due_note' => match (true) {
                $overdue => $late === 1 ? 'venció ayer' : "venció hace {$late} días",
                $left <= 0 => 'vence hoy',
                $left === 1 => 'vence mañana',
                default => "vence en {$left} días",
            },
            'due_summary' => ($overdue ? 'venció el ' : 'vence el ').MailDates::short($due),
            'blocked_task' => $task !== null ? "{$task->key} · {$task->title}" : 'que depende de este dato',
            'needs' => self::needs($requirement),
            'asks_for_secrets' => preg_match(self::SECRETS, $requirement->title.' '.$requirement->detail) === 1,
            'reminder_text' => $reminder === null
                ? 'Si sigue pendiente, te lo recordamos cerca de la fecha límite'
                : 'Este es el '.(self::ORDINALS[$reminder] ?? "número {$reminder}"),
        ]);
    }

    /**
     * `project-report` data for one recipient group. `$content` is the
     * design `ReportContent`: its `budget` carries money only for the group
     * that may see it.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function projectReport(array $content, array $sections): array
    {
        $status = RequestStatus::tryFrom((string) ($content['project']['status'] ?? '')) ?? RequestStatus::Planned;
        [$glyph, $family] = RequestEmails::statusStyle($status);
        [$bg, $fg] = RequestEmails::FAMILY_COLORS[$family];
        $asOf = CarbonImmutable::parse((string) $content['as_of'], MailDates::TIMEZONE);
        $tasks = $content['tasks'];
        $criteria = $content['criteria'];
        $blockers = in_array('blockers', $sections, true) ? $content['blockers'] : [];
        $budget = $content['budget'] ?? null;
        $withCosts = is_array($budget) && is_array($budget['cost'] ?? null);

        $summary = "Lleva {$tasks['done']} de {$tasks['total']} tareas hechas"
            .($criteria['total'] > 0 ? " y {$criteria['done']} de {$criteria['total']} criterios de aceptación cumplidos" : '')
            .'.'
            .match (count($blockers)) {
                0 => '',
                1 => ' Esperamos un dato de otra área.',
                default => ' Esperamos '.count($blockers).' datos de otras áreas.',
            };

        $data = [
            'action_url' => (string) $content['project_url'],
            'can_view_costs' => $withCosts,
            'project_key' => (string) $content['project']['key'],
            'project_name' => (string) $content['project']['name'],
            'project_title' => (string) $content['project']['name'],
            'request_key' => (string) ($content['request_key'] ?? $content['project']['key']),
            'requested_by' => (string) $content['requester_name'],
            'report_date' => MailDates::day($asOf),
            'report_date_short' => MailDates::short($asOf),
            'status_label' => $status->label(),
            'status_glyph' => $glyph,
            'status_family' => $family,
            'status_bg' => $bg,
            'status_fg' => $fg,
            'summary' => $summary,
            'phases' => self::reportPhases($status, $glyph, $fg),
            'tasks_done' => (int) $tasks['done'],
            'tasks_total' => (int) $tasks['total'],
            'tasks_percent' => self::percent($tasks['done'], $tasks['total']),
            'criteria_met' => (int) $criteria['done'],
            'criteria_total' => (int) $criteria['total'],
            'criteria_percent' => self::percent($criteria['done'], $criteria['total']),
            'criteria_met_marks' => str_repeat('✓', (int) $criteria['done']),
            'criteria_pending_marks' => str_repeat('○', max(0, (int) $criteria['total'] - (int) $criteria['done'])),
            'done_items' => array_values($content['done']),
            'next_items' => array_values($content['next']),
            'risks' => array_map(fn (string $line): array => ['title' => 'Esperamos un dato.', 'text' => $line], array_values($blockers)),
        ];

        return $withCosts ? [...$data, ...self::costs($budget, $data)] : $data;
    }

    /**
     * Names joined for the templates: ["A y a B", "A o B"]; "A, a B y a C" /
     * "A, B o C" for three or more.
     *
     * @param  list<string>  $names
     * @return array{0: string, 1: string}
     */
    public static function joinApprovers(array $names): array
    {
        $names = array_values($names);

        if ($names === []) {
            return [self::NO_APPROVERS, self::NO_APPROVERS];
        }

        $last = array_pop($names);

        if ($names === []) {
            return [$last, $last];
        }

        return [implode(', a ', $names).' y a '.$last, implode(', ', $names).' o '.$last];
    }

    /**
     * Time and cost variables (only for the group that may see costs),
     * plus the date risk, which only this group can compute.
     *
     * @param  array<string, mixed>  $budget  design `ProjectBudget` with `cost`
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function costs(array $budget, array $data): array
    {
        $hoursUsed = (float) $budget['hours']['actual'];
        $hoursBudget = (float) $budget['hours']['estimate'];
        $costUsed = (int) $budget['cost']['actual'];
        $costBudget = (int) $budget['cost']['estimate'];
        $costPercent = $costBudget > 0 ? (int) round($costUsed / $costBudget * 100) : 0;
        $approved = CarbonImmutable::parse((string) $budget['due_date'], MailDates::TIMEZONE);
        $projected = CarbonImmutable::parse((string) $budget['projected_due_date'], MailDates::TIMEZONE);
        $daysLate = max(0, (int) $approved->diffInDays($projected, false));
        $threshold = (float) app(Settings::class)->get('ai.reapproval_threshold', BudgetRules::DEFAULT_REAPPROVAL_THRESHOLD);
        [$and, $or] = self::joinApprovers(app(TriagePeople::class)->approvers()->map(fn (User $user): string => $user->name)->all());

        $summary = $data['summary']
            .($costBudget > 0 ? " El costo va en el {$costPercent} % del presupuesto." : '')
            .($daysLate > 0 ? ' La entrega proyectada es el '.MailDates::short($projected).", {$daysLate} días después de lo aprobado." : '');

        $dateRisk = $daysLate > 0 ? [[
            'title' => 'Fecha en riesgo.',
            'text' => 'La entrega proyectada es el '.MailDates::short($projected).", {$daysLate} días después de lo aprobado (".MailDates::short($approved).').',
        ]] : [];

        return [
            'risks' => [...$dateRisk, ...$data['risks']],
            'summary_with_costs' => $summary,
            'hours_used' => self::hours($hoursUsed),
            'hours_budget' => self::hours($hoursBudget),
            'hours_left' => self::hours(max(0, $hoursBudget - $hoursUsed)),
            'hours_percent' => self::percent($hoursUsed, $hoursBudget),
            'cost_used' => Money::cop($costUsed),
            'cost_budget' => Money::cop($costBudget),
            'cost_percent' => $costPercent,
            'cost_bar_color' => match (true) {
                $costPercent >= 100 => '#B42318',
                $costPercent >= 80 => '#B26B00',
                default => '#17191C',
            },
            'cost_alert' => $costPercent >= 80,
            'projected_date' => MailDates::short($projected),
            'approved_date' => MailDates::short($approved),
            'days_late' => $daysLate,
            'cost_reapproval_limit' => Money::cop($costBudget * (1 + $threshold)),
            'approvers_and' => $and,
            'approvers_or' => $or,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function reportPhases(RequestStatus $status, string $glyph, string $fg): array
    {
        $current = RequestEmails::phase($status);
        $phases = [];

        foreach (self::REPORT_PHASES as $number => $label) {
            $state = $number < $current ? 'done' : ($number === $current ? 'current' : 'pending');
            $phases[] = [
                'state' => $state,
                'glyph' => match ($state) {
                    'done' => '✓',
                    'current' => $glyph,
                    'pending' => '○',
                },
                'label' => $state === 'current' ? "{$label} (ahora)" : $label,
                'bar_color' => match ($state) {
                    'done' => '#17191C',
                    'current' => $fg,
                    'pending' => '#E5E5E0',
                },
                'color' => match ($state) {
                    'done' => '#17191C',
                    'current' => $fg,
                    'pending' => '#5E6168',
                },
                'weight' => $state === 'pending' ? '500' : '700',
            ];
        }

        return $phases;
    }

    /**
     * What exactly is needed: the detail lines (bullets stripped), else the
     * requirement itself.
     *
     * @return list<string>
     */
    private static function needs(AiRequirement $requirement): array
    {
        $lines = preg_split('/\R/u', (string) $requirement->detail) ?: [];
        $needs = array_values(array_filter(
            array_map(fn (string $line): string => trim((string) preg_replace('/^\s*[-•*]\s*/u', '', $line)), $lines),
            fn (string $line): bool => $line !== '',
        ));

        return $needs !== [] ? $needs : [$requirement->title];
    }

    /**
     * "Las credenciales…" reads "TI necesita las credenciales…"; acronyms
     * ("API de…", "SAP…") keep their case.
     */
    private static function lowerFirst(string $text): string
    {
        if (preg_match('/^\p{Lu}\p{Ll}/u', $text) !== 1) {
            return $text;
        }

        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * Bar width 1–100 (a cell needs some width).
     */
    private static function percent(int|float $part, int|float $total): int
    {
        return $total > 0 ? max(1, min(100, (int) round($part / $total * 100))) : 1;
    }

    private static function hours(float $hours): string
    {
        return str_replace('.', ',', (string) round($hours, 1));
    }
}
