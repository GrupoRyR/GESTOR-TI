<?php

namespace Tests\Feature\Ai\Projects;

use App\Enums\Ai\RequestStatus;
use App\Mail\ProjectReportMail;
use App\Models\AiEstimate;
use App\Models\AiProject;
use App\Models\AiProjectReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

class ProjectReportTest extends ProjectsTestCase
{
    private AiProject $p;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-07 09:40', 'America/Bogota'));
        $this->p = $this->project(['name' => 'Visualizador de cartera', 'status' => RequestStatus::InDevelopment]);
        $request = $this->request(['status' => RequestStatus::InDevelopment, 'first_responded_at' => now()]);
        $request->criteria()->create(['position' => 1, 'text' => 'Filtra por asesor', 'result' => 'pass', 'validated_at' => now()]);
        $request->criteria()->create(['position' => 2, 'text' => 'Filtra por zona']);
        $this->p->requests()->attach($request->id);
        $this->p->members()->create(['user_id' => $this->jhon->id, 'role' => 'Líder del proyecto']);
        $this->task($this->p, 'done', ['title' => 'Diseñar filtros', 'completed_at' => now()->subDays(2)]);
        $this->task($this->p, 'in_progress', ['title' => 'Consulta SAP']);
        $blocked = $this->task($this->p, 'todo', ['title' => 'Total vencido']);
        $this->requirement($this->p, ['title' => 'Acceso SAP', 'area_name' => 'Contabilidad', 'blocks_task_id' => $blocked->id, 'due_date' => '2026-10-09']);
        $this->rate($this->jhon, 100_000);
        AiEstimate::create(['ai_project_id' => $this->p->id, 'hours_total' => 40, 'external_costs' => 0, 'total_cost' => 4_000_000, 'due_date' => '2026-10-31']);
        $this->hours($this->p, $this->jhon, 10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_report_page_returns_exactly_the_contract_props(): void
    {
        $this->assertSame(
            $this->withShared(['project', 'recipients', 'recipient_candidates', 'schedule', 'sections', 'content', 'pdf', 'now']),
            $this->propKeys(route('ai.projects.report', $this->p)),
        );

        $this->actingAs($this->paola)->get(route('ai.projects.report', $this->p))->assertForbidden();
    }

    public function test_report_page_content_and_defaults(): void
    {
        $this->actingAs($this->jhon)
            ->get(route('ai.projects.report', $this->p))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Projects/Report', false)
                ->where('schedule', ['periodicity' => 'manual', 'weekday' => 1, 'time' => '07:00', 'last_sent_at' => null, 'next_send_at' => null])
                ->where('sections', ['status', 'done', 'next', 'blockers', 'budget'])
                ->where('recipients.0.person.id', $this->jhon->id)
                ->where('recipients.0.role_label', 'Líder del proyecto')
                ->where('recipients.0.can_view_costs', true)
                ->where('recipients.0.include_costs', true)
                ->where('recipients.1.person.id', $this->paola->id)
                ->where('recipients.1.can_view_costs', false)
                ->where('recipients.1.include_costs', false)
                ->where('content.as_of', '2026-10-07')
                ->where('content.company_id', $this->segurex->id)
                ->where('content.project', ['key' => $this->p->key, 'name' => 'Visualizador de cartera', 'status' => 'in_development'])
                ->where('content.requester_name', 'Paola Ríos')
                ->where('content.tasks', ['done' => 1, 'total' => 3])
                ->where('content.criteria', ['done' => 1, 'total' => 2])
                ->where('content.done', ['Diseñar filtros'])
                ->where('content.next', ['Consulta SAP', 'Total vencido'])
                ->where('content.blockers.0', fn ($line) => str_contains($line, 'Contabilidad') && str_contains($line, 'Acceso SAP'))
                ->where('content.budget.cost', ['estimate' => 4_000_000, 'actual' => 1_000_000])
                ->where('content.project_url', route('ai.projects.show', $this->p))
                ->where('pdf.pages', fn ($pages) => $pages >= 1)
                ->where('pdf.size', fn ($size) => $size > 0)
            );

        $this->actingAs($this->itWithoutCosts())
            ->get(route('ai.projects.report', $this->p))
            ->assertInertia(fn (Assert $page) => $page
                ->where('content.budget', null)
                ->where('sections', ['status', 'done', 'next', 'blockers'])
            );
    }

    public function test_pdf_download(): void
    {
        $response = $this->actingAs($this->jhon)->get(route('ai.projects.report.pdf', [$this->p, 'version' => 'internal']));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->actingAs($this->jhon)->get(route('ai.projects.report.pdf', [$this->p, 'version' => 'nope']))->assertSessionHasErrors('version');
    }

    public function test_send_now_hides_costs_from_recipients_without_permission(): void
    {
        Mail::fake();

        $this->actingAs($this->jhon)
            ->post(route('ai.projects.report.send', $this->p), [
                'recipients' => [
                    ['user_id' => $this->jhon->id, 'include_costs' => true],
                    ['user_id' => $this->edward->id, 'include_costs' => false],
                    ['user_id' => $this->paola->id, 'include_costs' => true],
                ],
                'periodicity' => 'manual',
                'weekday' => 1,
                'time' => '07:00',
                'sections' => ['status', 'done', 'next', 'blockers', 'budget'],
                'version' => 'internal',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        Mail::assertSent(ProjectReportMail::class, 3);
        Mail::assertSent(ProjectReportMail::class, fn (ProjectReportMail $mail) => $mail->hasTo($this->jhon->email) && $mail->content['budget'] !== null);
        Mail::assertSent(ProjectReportMail::class, fn (ProjectReportMail $mail) => $mail->hasTo($this->edward->email) && $mail->content['budget'] === null);
        Mail::assertSent(ProjectReportMail::class, fn (ProjectReportMail $mail) => $mail->hasTo($this->paola->email) && $mail->content['budget'] === null);
        // E-mail body and PDF: the internal one shows the money, the others never do.
        Mail::assertSent(ProjectReportMail::class, fn (ProjectReportMail $mail) => $mail->hasTo($this->jhon->email)
            && str_contains($mail->render(), '4.000.000')
            && str_starts_with($mail->pdfBytes(), '%PDF'));
        Mail::assertSent(ProjectReportMail::class, fn (ProjectReportMail $mail) => $mail->hasTo($this->paola->email)
            && ! str_contains($mail->render(), '4.000.000')
            && str_starts_with($mail->pdfBytes(), '%PDF'));

        $this->assertNotNull(AiProjectReport::query()->sole()->last_sent_at);

        // The requester version never carries money, whoever receives it.
        Mail::fake();
        $this->actingAs($this->jhon)->post(route('ai.projects.report.send', $this->p), [
            'recipients' => [['user_id' => $this->jhon->id, 'include_costs' => true]],
            'periodicity' => 'manual', 'weekday' => 1, 'time' => '07:00',
            'sections' => ['status', 'budget'], 'version' => 'requester',
        ]);
        Mail::assertSent(ProjectReportMail::class, fn (ProjectReportMail $mail) => $mail->content['budget'] === null && ! str_contains($mail->render(), '4.000.000'));
    }

    public function test_sender_without_costs_view_never_sends_costs(): void
    {
        Mail::fake();

        $this->actingAs($this->itWithoutCosts())->post(route('ai.projects.report.send', $this->p), [
            'recipients' => [['user_id' => $this->jhon->id, 'include_costs' => true]],
            'periodicity' => 'manual', 'weekday' => 1, 'time' => '07:00',
            'sections' => ['status', 'budget'], 'version' => 'internal',
        ])->assertSessionHasNoErrors();

        Mail::assertSent(ProjectReportMail::class, fn (ProjectReportMail $mail) => $mail->content['budget'] === null);
    }

    public function test_schedule_and_the_scheduled_command(): void
    {
        Mail::fake();

        $this->actingAs($this->jhon)
            ->put(route('ai.projects.report.schedule', $this->p), [
                'recipients' => [['user_id' => $this->jhon->id, 'include_costs' => true], ['user_id' => $this->paola->id, 'include_costs' => false]],
                'periodicity' => 'weekly',
                'weekday' => 1,
                'time' => '07:00',
                'sections' => ['status', 'done', 'budget'],
                'version' => 'internal',
            ])
            ->assertSessionHasNoErrors();
        Mail::assertNothingSent();

        $report = AiProjectReport::query()->sole();
        // Wednesday 7 Oct → Monday 12 Oct 07:00 Bogotá.
        $this->assertSame('2026-10-12 07:00', $report->next_send_at->timezone('America/Bogota')->format('Y-m-d H:i'));

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.report', $this->p))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.periodicity', 'weekly')
                ->where('schedule.next_send_at', $report->next_send_at->toIso8601String())
                ->where('sections', ['status', 'done', 'budget'])
                ->has('recipients', 2)
            );

        $this->artisan('ai:project-reports')->assertSuccessful();
        Mail::assertNothingSent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 07:05', 'America/Bogota'));
        $this->artisan('ai:project-reports')->assertSuccessful();
        Mail::assertSent(ProjectReportMail::class, 2);
        $this->assertSame('2026-10-19 07:00', $report->fresh()->next_send_at->timezone('America/Bogota')->format('Y-m-d H:i'));

        $this->actingAs($this->jhon)->put(route('ai.projects.report.schedule', $this->p), [
            'recipients' => [], 'periodicity' => 'yearly', 'weekday' => 9, 'time' => '25:00', 'sections' => ['nope'], 'version' => 'x',
        ])->assertSessionHasErrors(['periodicity', 'weekday', 'time', 'sections.0', 'version']);
    }

    public function test_report_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('ai:project-reports')->assertSuccessful();
    }
}
