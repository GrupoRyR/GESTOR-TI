<?php

namespace App\Domain\Ai;

use App\Mail\ProjectReportMail;
use App\Models\AiProject;
use App\Models\AiProjectReport;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Project report (PROMPT 6.4.3 "informes por correo… manuales o
 * programados", 6.4.11): who receives it, the weekly schedule, the PDF
 * and the sending.
 *
 * Time and cost ("budget" section) reach a recipient only when all hold:
 * internal version, the section is chosen, the recipient has
 * `ai.costs.view` and `include_costs`, and whoever saved the settings had
 * `ai.costs.view` (saving without it stores `include_costs: false`).
 *
 * Default recipients (nothing saved yet): the team, then the requesters of
 * its requests. Candidates: followers of its requests, the area approver,
 * the project approvers and the team, minus the recipients.
 */
final class ProjectReports
{
    public function __construct(private readonly ProjectReportContent $content) {}

    public function settings(AiProject $project): ?AiProjectReport
    {
        return $project->report()->first();
    }

    /**
     * Sections shown to the viewer: saved ones or all; never "budget"
     * without `ai.costs.view`.
     *
     * @return list<string>
     */
    public function sections(AiProject $project, User $viewer): array
    {
        $sections = $this->settings($project)?->sections ?? AiProjectReport::SECTIONS;

        return array_values(array_filter(
            array_intersect(AiProjectReport::SECTIONS, $sections),
            fn (string $section) => $section !== 'budget' || $viewer->can('ai.costs.view'),
        ));
    }

    /**
     * Design `ReportRecipient` rows of the saved list (or the defaults).
     *
     * @return list<array<string, mixed>>
     */
    public function recipients(AiProject $project): array
    {
        $settings = $this->settings($project);

        if ($settings !== null) {
            $rows = collect($settings->recipients)->keyBy('user_id');
            $users = User::query()->with('employee')->whereIn('id', $rows->keys())->get()->keyBy('id');

            return $rows->map(fn (array $row, $id) => isset($users[$id]) ? $this->recipient($project, $users[$id], (bool) $row['include_costs']) : null)
                ->filter()
                ->values()
                ->all();
        }

        return $this->defaultRecipients($project)
            ->map(fn (User $user) => $this->recipient($project, $user, $user->can('ai.costs.view')))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function candidates(AiProject $project): array
    {
        $taken = collect($this->recipients($project))->pluck('person.id')->all();
        $users = collect();
        $requests = $project->requests()->with('followers.employee')->get();

        foreach ($requests as $request) {
            $users = $users->merge($request->followers);
        }

        if ($project->area?->approver_user_id !== null) {
            $users->push(User::query()->with('employee')->find($project->area->approver_user_id));
        }

        $users = $users->merge(app(TriagePeople::class)->approvers())->merge($this->team($project));

        return $users->filter()
            ->unique('id')
            ->reject(fn (User $user) => in_array($user->id, $taken, true) || ! $user->isActive())
            ->map(fn (User $user) => $this->recipient($project, $user, false))
            ->values()
            ->all();
    }

    /**
     * Design `ReportSchedule`.
     *
     * @return array<string, mixed>
     */
    public function schedule(AiProject $project): array
    {
        $settings = $this->settings($project);

        return [
            'periodicity' => $settings->periodicity ?? 'manual',
            'weekday' => $settings->weekday ?? 1,
            'time' => $settings->time ?? '07:00',
            'last_sent_at' => $settings?->last_sent_at?->toIso8601String(),
            'next_send_at' => $settings?->next_send_at?->toIso8601String(),
        ];
    }

    /**
     * Saves recipients, sections, version and schedule (`ReportPayload`).
     *
     * @param  array{recipients: list<array{user_id: int, include_costs: bool}>, periodicity: string, weekday: int, time: string, sections: list<string>, version: string}  $payload
     */
    public function save(AiProject $project, User $actor, array $payload): AiProjectReport
    {
        $canCosts = $actor->can('ai.costs.view');
        $recipients = collect($payload['recipients'])
            ->unique('user_id')
            ->map(fn (array $row) => ['user_id' => (int) $row['user_id'], 'include_costs' => $canCosts && (bool) $row['include_costs']])
            ->values()
            ->all();

        $report = $project->report()->firstOrNew();
        $report->fill([
            'periodicity' => $payload['periodicity'],
            'weekday' => (int) $payload['weekday'],
            'time' => $payload['time'],
            'sections' => array_values(array_intersect(AiProjectReport::SECTIONS, $payload['sections'])),
            'version' => $payload['version'],
            'recipients' => $recipients,
            'updated_by_id' => $actor->id,
        ]);
        $report->next_send_at = $payload['periodicity'] === 'weekly'
            ? self::nextSend((int) $payload['weekday'], $payload['time'])
            : null;
        $report->save();

        return $report;
    }

    /**
     * Sends the report to every saved recipient now; returns how many.
     */
    public function send(AiProject $project, AiProjectReport $report): int
    {
        $users = User::query()->whereIn('id', collect($report->recipients)->pluck('user_id'))->get()->keyBy('id');
        $sent = 0;

        foreach ($report->recipients as $row) {
            $user = $users[$row['user_id']] ?? null;

            if ($user === null || ! $user->isActive() || blank($user->email)) {
                continue;
            }

            $withCosts = $report->version === 'internal'
                && in_array('budget', $report->sections, true)
                && (bool) $row['include_costs']
                && $user->can('ai.costs.view');

            Mail::to($user)->send(new ProjectReportMail(
                $this->content->for($project, $withCosts),
                $report->sections,
                $report->version,
                $project->company?->sender_email ?: null,
                $project->company?->sender_name ?: null,
            ));
            $sent++;
        }

        $report->forceFill(['last_sent_at' => now()])->save();

        return $sent;
    }

    /**
     * Next weekly send after now: weekday 1 = lunes … 7 = domingo, Bogotá.
     */
    public static function nextSend(int $weekday, string $time, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now(BusinessDays::TIMEZONE);
        [$hour, $minute] = array_map('intval', explode(':', $time) + [0, 0]);
        $candidate = $now->timezone(BusinessDays::TIMEZONE)->startOfDay()->setTime($hour, $minute);
        $days = ($weekday - $candidate->dayOfWeekIso + 7) % 7;
        $candidate = $candidate->addDays($days);

        if ($candidate->lessThanOrEqualTo($now)) {
            $candidate = $candidate->addWeek();
        }

        return $candidate;
    }

    /**
     * PDF bytes of the content (same data as the e-mail).
     *
     * @param  array<string, mixed>  $content
     * @param  list<string>  $sections
     * @return array{bytes: string, pages: int}
     */
    public static function pdf(array $content, array $sections): array
    {
        $pdf = Pdf::loadView('pdf.project-report', ['content' => $content, 'sections' => $sections])->setPaper('letter');
        $bytes = (string) $pdf->output();

        return ['bytes' => $bytes, 'pages' => max(1, (int) $pdf->getDomPDF()->getCanvas()->get_page_count())];
    }

    /**
     * @return Collection<int, User>
     */
    private function defaultRecipients(AiProject $project): Collection
    {
        $requesters = $project->requests()->with('requester.employee')->get()
            ->map(fn (AiRequest $request) => $request->requester);

        return $this->team($project)->merge($requesters)->filter()->unique('id')->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function team(AiProject $project): Collection
    {
        $users = collect();

        if ($project->lead !== null) {
            $users->push($project->lead);
        }

        foreach ($project->members()->with('user.employee')->get() as $member) {
            $users->push($member->user);
        }

        return $users->filter()->unique('id')->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function recipient(AiProject $project, User $user, bool $includeCosts): array
    {
        $canCosts = $user->can('ai.costs.view');

        return [
            'person' => RequestPresenter::person($user),
            'role_label' => $this->roleLabel($project, $user),
            'can_view_costs' => $canCosts,
            'include_costs' => $canCosts && $includeCosts,
        ];
    }

    private function roleLabel(AiProject $project, User $user): string
    {
        $member = $project->members()->where('user_id', $user->id)->first();

        if ($member !== null) {
            return $member->role;
        }

        if ((int) $project->lead_id === $user->id) {
            return 'Líder del proyecto';
        }

        $employee = $user->employee;
        $area = $employee?->area_id !== null ? $employee->area()->withoutGlobalScopes()->value('name') : null;

        if ($project->requests()->where('ai_requests.requester_id', $user->id)->exists()) {
            return 'Solicitante'.($area !== null ? " · {$area}" : '');
        }

        if ($user->can('ai.projects.approve')) {
            return 'Aprobador de proyectos';
        }

        $parts = array_filter([$employee?->job_title, $area]);

        return $parts === [] ? 'Seguidor' : implode(' · ', $parts);
    }
}
