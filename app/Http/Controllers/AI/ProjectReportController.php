<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\ProjectReportContent;
use App\Domain\Ai\ProjectReports;
use App\Http\Controllers\Controller;
use App\Models\AiProject;
use App\Models\AiProjectReport;
use App\Models\User;
use App\Support\Ai\Presenters\ProjectPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project report (PROMPT 6.4.3, 6.4.11; `Ai/Projects/Report`), `ai.manage`
 * and AiProjectPolicy::manage:
 *
 * - Page: recipients (saved or default), candidates, schedule, sections,
 *   the content as the viewer sees it (`budget` null without
 *   `ai.costs.view`) and the size of its PDF.
 * - `ai.projects.report.pdf` `?version=internal|requester`: the PDF
 *   (requester version never carries time and cost).
 * - `ai.projects.report.send`: saves the settings and e-mails it now.
 * - `ai.projects.report.schedule`: saves the settings; weekly reports
 *   leave from `ai:project-reports`.
 * Rules on who receives time and cost: App\Domain\Ai\ProjectReports.
 */
class ProjectReportController extends Controller
{
    public function __construct(
        private readonly ProjectReports $reports,
        private readonly ProjectReportContent $content,
        private readonly ProjectPresenter $projects,
    ) {}

    public function show(Request $http, AiProject $project): Response
    {
        $user = $this->user($http);
        Gate::authorize('manage', $project);

        $sections = $this->reports->sections($project, $user);
        $content = $this->content->for($project, $user->can('ai.costs.view'));
        $pdf = ProjectReports::pdf($content, $sections);

        return Inertia::render('Ai/Projects/Report', [
            'project' => $this->projects->header($project),
            'recipients' => $this->reports->recipients($project),
            'recipient_candidates' => $this->reports->candidates($project),
            'schedule' => $this->reports->schedule($project),
            'sections' => $sections,
            'content' => $content,
            'pdf' => ['pages' => $pdf['pages'], 'size' => strlen($pdf['bytes'])],
            'now' => now()->toIso8601String(),
        ]);
    }

    public function pdf(Request $http, AiProject $project): \Illuminate\Http\Response
    {
        $user = $this->user($http);
        Gate::authorize('manage', $project);
        $data = $http->validate(['version' => ['required', Rule::in(AiProjectReport::VERSIONS)]]);

        $sections = $this->reports->sections($project, $user);
        $withCosts = $data['version'] === 'internal' && $user->can('ai.costs.view') && in_array('budget', $sections, true);
        $pdf = ProjectReports::pdf($this->content->for($project, $withCosts), $sections);

        return response($pdf['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="informe-'.$project->key.'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function send(Request $http, AiProject $project): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $report = $this->reports->save($project, $this->user($http), $this->payload($http));

        if ($report->recipients === []) {
            return back()->with('error', 'Agrega al menos una persona que reciba el informe.');
        }

        $sent = $this->reports->send($project, $report);

        return back()->with('success', $sent === 1 ? 'Informe enviado a 1 persona.' : "Informe enviado a {$sent} personas.");
    }

    public function schedule(Request $http, AiProject $project): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $report = $this->reports->save($project, $this->user($http), $this->payload($http));

        return back()->with('success', $report->periodicity === 'weekly'
            ? 'Informe programado: sale cada semana.'
            : 'Informe guardado: se envía cuando lo pidas.');
    }

    /**
     * @return array{recipients: list<array{user_id: int, include_costs: bool}>, periodicity: string, weekday: int, time: string, sections: list<string>, version: string}
     */
    private function payload(Request $http): array
    {
        /** @var array{recipients: list<array{user_id: int, include_costs: bool}>, periodicity: string, weekday: int, time: string, sections: list<string>, version: string} $data */
        $data = $http->validate([
            'recipients' => ['present', 'array', 'max:50'],
            'recipients.*.user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'recipients.*.include_costs' => ['required', 'boolean'],
            'periodicity' => ['required', Rule::in(AiProjectReport::PERIODICITIES)],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'time' => ['required', 'date_format:H:i'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', Rule::in(AiProjectReport::SECTIONS)],
            'version' => ['required', Rule::in(AiProjectReport::VERSIONS)],
        ]);

        return $data;
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
