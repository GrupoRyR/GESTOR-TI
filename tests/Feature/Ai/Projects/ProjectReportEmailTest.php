<?php

namespace Tests\Feature\Ai\Projects;

use App\Enums\Ai\RequestStatus;
use App\Mail\ProjectReportMail;
use App\Models\AiEstimate;
use App\Models\AiProject;
use App\Support\Mail\EmailTemplate;
use App\Support\Mail\ProjectEmails;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Weekly project report from the approved `project-report` template: one
 * version with time and cost for `ai.costs.view` recipients, and one whose
 * data has no cost, hour, budget or rate value at all for everyone else.
 */
class ProjectReportEmailTest extends ProjectsTestCase
{
    private const COST_KEY = '/cost|hour|budget|rate|approver|summary_with|projected|approved_date|days_late/';

    private AiProject $p;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-07 09:40', 'America/Bogota'));
        $this->segurex->update(['short_name' => 'Segurex', 'sender_email' => 'segurex.info@segurex.com']);
        $this->derivados->update(['short_name' => 'Derivados', 'sender_email' => 'derivados.info@derivadoscol.com']);
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

    private function send(AiProject $project, array $recipients): void
    {
        $this->actingAs($this->jhon)
            ->post(route('ai.projects.report.send', $project), [
                'recipients' => $recipients,
                'periodicity' => 'manual',
                'weekday' => 1,
                'time' => '07:00',
                'sections' => ['status', 'done', 'next', 'blockers', 'budget'],
                'version' => 'internal',
            ])
            ->assertSessionHasNoErrors();
    }

    private function mailTo(string $email): ProjectReportMail
    {
        $mails = Mail::sent(ProjectReportMail::class, fn (ProjectReportMail $mail) => $mail->hasTo($email));
        $this->assertCount(1, $mails, $email);

        return $mails->first();
    }

    public function test_cost_viewers_get_the_costs_and_the_others_get_no_cost_data_at_all(): void
    {
        Mail::fake();

        $this->send($this->p, [
            ['user_id' => $this->jhon->id, 'include_costs' => true],
            ['user_id' => $this->paola->id, 'include_costs' => true],
        ]);

        $internal = $this->mailTo($this->jhon->email);
        $public = $this->mailTo($this->paola->email);

        foreach ([$internal, $public] as $mail) {
            $this->assertSame(EmailTemplate::ProjectReport, $mail->template());
            $this->assertSame("[{$this->p->key}] Informe semanal: Visualizador de cartera al 7 oct", $mail->subject);
            $this->assertTrue($mail->hasFrom('segurex.info@segurex.com', 'Segurex · Gestor TI'));
            $this->assertEmpty(array_diff(array_keys($mail->variables()), EmailTemplate::ProjectReport->allowedVariables()));
            $this->assertSame(['Diseñar filtros'], $mail->variables()['done_items']);
            $this->assertSame(['Consulta SAP', 'Total vencido'], $mail->variables()['next_items']);
            $this->assertContains('Esperamos un dato.', array_column($mail->variables()['risks'], 'title'));
            $this->assertSame(route('ai.projects.show', $this->p), $mail->variables()['action_url']);
        }

        $data = $internal->variables();
        $this->assertTrue($data['can_view_costs']);
        $this->assertSame('$ 1.000.000', $data['cost_used']);
        $this->assertSame('$ 4.000.000', $data['cost_budget']);
        $this->assertSame(25, $data['cost_percent']);
        $this->assertSame('10', $data['hours_used']);
        $this->assertSame('40', $data['hours_budget']);
        $this->assertSame('$ 4.800.000', $data['cost_reapproval_limit']);
        $html = $internal->render();
        $this->assertStringContainsString('$ 4.000.000', $html);
        $this->assertStringContainsString('puedes ver sus costos', $html);

        $data = $public->variables();
        $this->assertFalse($data['can_view_costs']);
        $this->assertSame([], array_values(preg_grep(self::COST_KEY, array_diff(array_keys($data), ['can_view_costs']))));
        $this->assertSame([], array_values(preg_grep(self::COST_KEY, array_diff(array_keys($public->buildViewData()), ['can_view_costs']))));
        $html = $public->render();
        $this->assertDoesNotMatchRegularExpression('/\$\s?\d|\d+ h de|Tiempo y costo|presupuesto/u', $html);
        $this->assertStringContainsString('Recibes este informe porque pediste el proyecto', $html);
        $this->assertStringContainsString('1 de 3', $html);
    }

    public function test_approvers_are_the_current_project_approvers(): void
    {
        Mail::fake();
        $ana = $this->user($this->segurex, 'Usuario final', 'Ana Gómez');
        $ana->givePermissionTo('ai.projects.approve');

        $this->send($this->p, [['user_id' => $this->jhon->id, 'include_costs' => true]]);

        $data = $this->mailTo($this->jhon->email)->variables();
        $this->assertStringContainsString('Ana Gómez', $data['approvers_and']);
        $this->assertStringContainsString('Edward Ramanan', $data['approvers_and']);
        $this->assertMatchesRegularExpression('/ y a [^,]+$/u', $data['approvers_and']);
        $this->assertMatchesRegularExpression('/ o [^,]+$/u', $data['approvers_or']);

        Mail::fake();
        $ana->revokePermissionTo('ai.projects.approve');
        $this->edward->syncRoles([]);

        $this->send($this->p, [['user_id' => $this->jhon->id, 'include_costs' => true]]);

        $data = $this->mailTo($this->jhon->email)->variables();
        $this->assertStringNotContainsString('Ana Gómez', $data['approvers_or']);
        $this->assertStringNotContainsString('Edward Ramanan', $data['approvers_or']);
    }

    public function test_approver_names_are_joined_in_spanish(): void
    {
        $this->assertSame(['Ana', 'Ana'], ProjectEmails::joinApprovers(['Ana']));
        $this->assertSame(['Ana y a Beto', 'Ana o Beto'], ProjectEmails::joinApprovers(['Ana', 'Beto']));
        $this->assertSame(['Ana, a Beto y a Carla', 'Ana, Beto o Carla'], ProjectEmails::joinApprovers(['Ana', 'Beto', 'Carla']));
        $this->assertSame(['los aprobadores de proyectos', 'los aprobadores de proyectos'], ProjectEmails::joinApprovers([]));
    }

    public function test_the_sender_and_branding_follow_the_project_company(): void
    {
        Mail::fake();
        $project = $this->project(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'name' => 'Portafolio']);

        $this->send($project, [['user_id' => $this->jhon->id, 'include_costs' => true]]);

        $mail = $this->mailTo($this->jhon->email);
        $this->assertTrue($mail->hasFrom('derivados.info@derivadoscol.com', 'Derivados · Gestor TI'));
        $this->assertSame('Derivados', $mail->variables()['company_name']);
        $this->assertStringContainsString('logo-derivados', $mail->variables()['logo_url']);
    }
}
