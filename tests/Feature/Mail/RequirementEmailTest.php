<?php

namespace Tests\Feature\Mail;

use App\Actions\Ai\RemindRequirement;
use App\Mail\TemplateMail;
use App\Models\NotificationPreference;
use App\Notifications\AiRequirementReminder;
use App\Notifications\AiRequirementRequested;
use App\Support\Mail\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Feature\Ai\Projects\ProjectsTestCase;

/**
 * `requirement`: sent to the person outside IT who must answer, when IT
 * registers the requirement and on each reminder, from the project
 * company's mailbox, once per event and only through the chosen channels.
 */
class RequirementEmailTest extends ProjectsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-07 09:40', 'America/Bogota'));
        $this->segurex->update(['short_name' => 'Segurex', 'sender_email' => 'segurex.info@segurex.com']);
        $this->derivados->update(['short_name' => 'Derivados', 'sender_email' => 'derivados.info@derivadoscol.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $sent) => $sent->getOriginalMessage())
            ->values()
            ->all();
    }

    public function test_registering_a_requirement_sends_the_requirement_email(): void
    {
        $project = $this->project();
        $request = $this->request(['title' => 'Portafolio: conectar la API de WooCommerce']);
        $project->requests()->attach($request->id);
        $task = $this->task($project, 'todo', ['title' => 'Conectar la API de WooCommerce']);

        $this->actingAs($this->jhon)
            ->post(route('ai.requirements.store', $project), [
                'what' => "Las credenciales de la API de WooCommerce\nClave y secreto con permiso de solo lectura.\nEl nombre del plugin que usan hoy.",
                'recipient_id' => $this->paola->id,
                'due_date' => '2026-10-09',
                'blocks_task' => $task->key,
            ])
            ->assertSessionHasNoErrors();

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $email = $emails[0];
        $text = (string) $email->getTextBody();
        $requirement = $project->requirements()->sole();

        $this->assertSame($this->paola->email, $email->getTo()[0]->getAddress());
        $this->assertSame('segurex.info@segurex.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Segurex · Gestor TI', $email->getFrom()[0]->getName());
        $this->assertSame("[{$project->key}] TI necesita las credenciales de la API de WooCommerce · vence el 9 oct", $email->getSubject());
        $this->assertStringContainsString("{$project->key} · {$request->key} · Vence el viernes 9 oct", $text);
        $this->assertStringContainsString('Hola. Jhon Castro, de TI, necesita tu ayuda para seguir con Portafolio: conectar la API de WooCommerce', $text);
        $this->assertStringContainsString("Sin esto, la tarea {$task->key} · Conectar la API de WooCommerce está detenida.", $text);
        $this->assertStringContainsString('- Clave y secreto con permiso de solo lectura.', $text);
        $this->assertStringContainsString('- El nombre del plugin que usan hoy.', $text);
        $this->assertStringContainsString('No envíes las claves por correo.', $text);
        $this->assertStringContainsString('Fecha límite: viernes 9 oct · vence en 2 días', $text);
        $this->assertStringContainsString('Responder el requerimiento: '.route('ai.requirements.respond', $requirement), $text);
        $this->assertStringContainsString('Cambiar mis avisos: '.route('profile.edit'), $text);
        $this->assertStringContainsString('No envíes las claves por correo', (string) $email->getHtmlBody());
        $this->assertStringNotContainsString('{{', (string) $email->getHtmlBody());
    }

    public function test_each_reminder_sends_the_requirement_email_with_its_count(): void
    {
        $project = $this->project();
        $requirement = $this->requirement($project, [
            'title' => 'Fotos de producto',
            'due_date' => '2026-10-01',
            'created_at' => Carbon::parse('2026-10-01 08:00', 'America/Bogota'),
            'reminders_sent' => 2,
        ]);

        app(RemindRequirement::class)->handle($requirement);

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $text = (string) $emails[0]->getTextBody();
        $this->assertSame("[{$project->key}] TI necesita fotos de producto · venció el 1 oct", $emails[0]->getSubject());
        $this->assertStringContainsString('Vencido · 6 días esperando', $text);
        $this->assertStringContainsString('Fecha límite: jueves 1 oct · venció hace 6 días', $text);
        $this->assertStringContainsString('Recordatorios: Este es el tercero', $text);
        $this->assertStringContainsString('- Fotos de producto', $text);
        $this->assertStringNotContainsString('No envíes las claves', $text);
        $this->assertStringContainsString('Sin esto, la tarea que depende de este dato está detenida.', $text);
    }

    public function test_both_notifications_build_the_template_and_keep_the_bell_text(): void
    {
        $project = $this->project();
        $requirement = $this->requirement($project, ['title' => 'Acceso SAP']);

        foreach ([AiRequirementRequested::for($requirement), AiRequirementReminder::for($requirement)] as $notice) {
            $mail = $notice->toMail($this->paola);

            $this->assertInstanceOf(TemplateMail::class, $mail);
            $this->assertSame(EmailTemplate::Requirement, $mail->template());
            $this->assertTrue($mail->hasTo($this->paola->email));
            $this->assertSame(
                [...EmailTemplate::SHARED, ...EmailTemplate::Requirement->variables()],
                array_values(array_intersect([...EmailTemplate::SHARED, ...EmailTemplate::Requirement->variables()], array_keys($mail->variables()))),
            );
            $this->assertEmpty(array_diff(array_keys($mail->variables()), EmailTemplate::Requirement->allowedVariables()));
        }

        $this->assertSame('Recordatorios: Este es el primero', 'Recordatorios: '.AiRequirementReminder::for($requirement)->toMail($this->paola)->variables()['reminder_text']);
    }

    public function test_the_mail_channel_follows_the_preferences(): void
    {
        NotificationPreference::create(['user_id' => $this->paola->id, 'event_type' => 'ai.requirement.requested', 'channels' => ['database']]);
        $requirement = $this->requirement($this->project());

        app(RemindRequirement::class)->handle($requirement);

        $this->assertSame([], $this->sentEmails());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->paola->id]);
    }

    public function test_the_sender_and_branding_follow_the_project_company(): void
    {
        $project = $this->project(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);
        $requirement = $this->requirement($project, [
            'recipient_id' => $this->derivadosUser->id,
            'recipient_label' => $this->derivadosUser->name,
            'area_id' => $this->derivadosVentas->id,
        ]);

        app(RemindRequirement::class)->handle($requirement);

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame($this->derivadosUser->email, $emails[0]->getTo()[0]->getAddress());
        $this->assertSame('derivados.info@derivadoscol.com', $emails[0]->getFrom()[0]->getAddress());
        $this->assertSame('Derivados · Gestor TI', $emails[0]->getFrom()[0]->getName());
        $this->assertStringContainsString('Lo envía derivados.info@derivadoscol.com.', (string) $emails[0]->getTextBody());
    }
}
