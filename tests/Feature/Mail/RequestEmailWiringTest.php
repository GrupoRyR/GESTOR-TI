<?php

namespace Tests\Feature\Mail;

use App\Actions\Ai\AskForInformation;
use App\Actions\Ai\CreateRequest;
use App\Actions\Ai\ReleaseNotes;
use App\Actions\Ai\TransitionRequest;
use App\Enums\Ai\RequestStatus;
use App\Mail\TemplateMail;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\AiRatingRequested;
use App\Notifications\AiRequestCreated;
use App\Notifications\AiRequestReceived;
use App\Notifications\AiRequestReminder;
use App\Notifications\AiRequestStatusChanged;
use App\Support\Mail\EmailTemplate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Feature\Ai\AiTestCase;

/**
 * Which notification sends which template, to whom, once per event, and
 * only through the channels the person chose.
 */
class RequestEmailWiringTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex->update(['short_name' => 'Segurex', 'sender_email' => 'segurex.info@segurex.com']);
    }

    /**
     * @return array<string, mixed>
     */
    private function wizardData(): array
    {
        return ['type' => 'new', 'problem' => 'Armo el reporte de comisiones a mano cada mes.', 'area_id' => $this->ventas->id];
    }

    /**
     * Emails that really left through the `array` mailer.
     *
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $sent) => $sent->getOriginalMessage())
            ->values()
            ->all();
    }

    private function template(object $notification, User $to): EmailTemplate
    {
        $mail = $notification->toMail($to);
        $this->assertInstanceOf(TemplateMail::class, $mail);
        $this->assertTrue($mail->hasTo($to->email));

        return $mail->template();
    }

    public function test_a_new_request_notifies_the_requester_and_the_it_team_of_the_company(): void
    {
        Notification::fake();
        $derivadosIt = User::factory()->create(['name' => 'TI Derivados']);
        $derivadosIt->forceFill(['company_id' => $this->derivados->id, 'is_global' => false])->save();
        $derivadosIt->givePermissionTo('ai.manage');

        $request = app(CreateRequest::class)->handle($this->juan, $this->wizardData());

        Notification::assertSentTo($this->juan, AiRequestReceived::class, function (AiRequestReceived $notice) use ($request) {
            return $notice->requestKey === $request->key
                && $this->template($notice, $this->juan) === EmailTemplate::RequestReceived;
        });
        Notification::assertSentTo($this->jhon, AiRequestCreated::class, function (AiRequestCreated $notice) {
            return $notice->eventType() === 'ai.request.created'
                && $this->template($notice, $this->jhon) === EmailTemplate::NewRequest;
        });
        Notification::assertNotSentTo($derivadosIt, AiRequestCreated::class);
        Notification::assertNotSentTo($this->juan, AiRequestCreated::class);
    }

    public function test_status_moves_send_status_changed_or_it_question(): void
    {
        Notification::fake();
        $request = $this->request();

        app(TransitionRequest::class)->handle($request, RequestStatus::Evaluating, $this->jhon);
        app(AskForInformation::class)->handle($request->refresh(), $this->jhon, '¿Qué columnas necesitas?');
        app(AskForInformation::class)->handle($request->refresh(), $this->jhon, '¿Y cada cuánto?');

        Notification::assertSentTo($this->paola, AiRequestStatusChanged::class, function (AiRequestStatusChanged $notice) {
            return $notice->status === RequestStatus::Evaluating
                && $this->template($notice, $this->paola) === EmailTemplate::StatusChanged;
        });
        Notification::assertSentTo($this->paola, AiRequestStatusChanged::class, function (AiRequestStatusChanged $notice) {
            if ($notice->status !== RequestStatus::NeedsInfo) {
                return false;
            }

            $mail = $notice->toMail($this->paola);

            return $mail->template() === EmailTemplate::ItQuestion
                && $mail->variables()['question_text'] === '¿Qué columnas necesitas?'
                && $mail->variables()['asker_name'] === 'Jhon Castro';
        });
        Notification::assertSentTo($this->paola, AiRequestReminder::class, function (AiRequestReminder $notice) {
            $mail = $notice->toMail($this->paola);

            return $mail->template() === EmailTemplate::ItQuestion && $mail->variables()['question_text'] === '¿Y cada cuánto?';
        });
    }

    public function test_reaching_production_sends_the_delivered_email(): void
    {
        Notification::fake();
        $request = $this->request(['status' => RequestStatus::InTesting]);

        app(TransitionRequest::class)->handle($request, RequestStatus::InProduction, $this->jhon);

        Notification::assertSentTo($this->paola, AiRatingRequested::class, function (AiRatingRequested $notice) {
            return $this->template($notice, $this->paola) === EmailTemplate::Delivered;
        });
        Notification::assertNotSentTo($this->paola, AiRequestStatusChanged::class);
    }

    public function test_one_email_per_event_from_the_company_mailbox(): void
    {
        $request = $this->request();

        app(TransitionRequest::class)->handle($request, RequestStatus::Evaluating, $this->jhon);

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame($this->paola->email, $emails[0]->getTo()[0]->getAddress());
        $this->assertSame('segurex.info@segurex.com', $emails[0]->getFrom()[0]->getAddress());
        $this->assertSame('Segurex · Gestor TI', $emails[0]->getFrom()[0]->getName());
        $this->assertSame("[{$request->key}] Tu solicitud está en evaluación", $emails[0]->getSubject());
        $this->assertStringContainsString('Tu solicitud pasó a evaluación', (string) $emails[0]->getTextBody());
        $this->assertStringContainsString('<!doctype html>', (string) $emails[0]->getHtmlBody());
    }

    public function test_a_release_sends_one_delivered_email_per_requester(): void
    {
        $product = AiProduct::factory()->create(['name' => 'Portafolio']);
        $version = AiProductVersion::factory()->for($product, 'product')->create(['version' => 'v2.0.0', 'tag' => 'v2.0.0']);
        $first = $this->request(['status' => RequestStatus::InTesting, 'title' => 'Exportar PDF']);
        $second = $this->request(['status' => RequestStatus::InTesting, 'title' => 'Buscar por referencia']);
        $version->requests()->attach([$first->id, $second->id]);
        NotificationPreference::create(['user_id' => $this->paola->id, 'event_type' => 'ai.release.published', 'channels' => ['mail', 'database']]);
        $this->jhon->givePermissionTo('ai.github.manage');

        app(ReleaseNotes::class)->notify($version, 'Ya puedes exportar y buscar.', null, $this->jhon);

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails, 'one email for the release, none per moved request');
        $this->assertSame($this->paola->email, $emails[0]->getTo()[0]->getAddress());
        $this->assertSame("[{$first->key}] Ya está en producción: Exportar PDF y Buscar por referencia", $emails[0]->getSubject());
        $this->assertStringContainsString('Portafolio v2.0.0', (string) $emails[0]->getTextBody());
        $this->assertStringContainsString('Ya puedes exportar y buscar.', (string) $emails[0]->getTextBody());
        $this->assertStringContainsString("/ai/requests/{$first->key}/rating/5?", (string) $emails[0]->getTextBody());
    }

    public function test_the_mail_channel_follows_the_preferences(): void
    {
        NotificationPreference::create(['user_id' => $this->paola->id, 'event_type' => 'ai.request.status_changed', 'channels' => ['database']]);
        NotificationPreference::create(['user_id' => $this->jhon->id, 'event_type' => 'ai.request.created', 'channels' => ['database']]);

        app(CreateRequest::class)->handle($this->paola, $this->wizardData());
        app(TransitionRequest::class)->handle($this->request(), RequestStatus::Evaluating, $this->jhon);

        foreach ($this->sentEmails() as $email) {
            $this->assertNotContains($email->getTo()[0]->getAddress(), [$this->paola->email, $this->jhon->email]);
        }
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->paola->id]);
    }
}
