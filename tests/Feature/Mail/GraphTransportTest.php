<?php

namespace Tests\Feature\Mail;

use App\Mail\TemplateMail;
use App\Mail\Transport\GraphMailException;
use App\Models\Company;
use App\Support\Mail\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Tests\TestCase;

/**
 * `graph` mailer (App\Mail\Transport\GraphTransport): every message goes
 * out through Microsoft Graph `POST /users/{sender}/sendMail` with an
 * app-only token of the sender company's tenant.
 */
class GraphTransportTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ms-client-secret-789';

    private const SEGUREX_TENANT = '11111111-1111-1111-1111-111111111111';

    private const DERIVADOS_TENANT = '22222222-2222-2222-2222-222222222222';

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'graph',
            'services.microsoft.client_id' => 'client-id-1',
            'services.microsoft.client_secret' => self::SECRET,
        ]);

        $this->segurex = Company::factory()->create([
            'name' => 'Segurex Latam', 'short_name' => 'Segurex', 'sender_email' => 'segurex.info@segurex.com',
            'tenant_id' => self::SEGUREX_TENANT, 'domains' => ['segurex.com', 'segurexlatam.com'],
        ]);
        $this->derivados = Company::factory()->create([
            'name' => 'Soluciones y Derivados', 'short_name' => 'Derivados', 'sender_email' => 'derivados.info@derivadoscol.com',
            'tenant_id' => self::DERIVADOS_TENANT, 'domains' => ['derivadoscol.com'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $sendMail
     */
    private function fakeGraph(int $status = 202, array $sendMail = [], array $headers = []): void
    {
        Http::fake([
            'login.microsoftonline.com/'.self::SEGUREX_TENANT.'/*' => Http::response(['access_token' => 'tok-segurex', 'expires_in' => 3600]),
            'login.microsoftonline.com/'.self::DERIVADOS_TENANT.'/*' => Http::response(['access_token' => 'tok-derivados', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response($sendMail, $status, $headers),
        ]);
    }

    private function templateMail(Company $company): TemplateMail
    {
        return TemplateMail::make(EmailTemplate::ItQuestion, $company, [
            'action_url' => 'https://gestor.test/ai/requests/1',
            'request_key' => 'GIA-7',
            'request_title' => 'Tablero de cartera',
            'asker_name' => 'Jhon Castro',
            'asked_at' => '20 oct, 9:00',
            'question_text' => '¿Qué columnas necesitas?',
            'reply_by_email' => false,
        ]);
    }

    private function sendMailRequest(): Request
    {
        $requests = collect(Http::recorded())->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/sendMail'))
            ->values();

        $this->assertCount(1, $requests);

        return $requests->first();
    }

    public function test_a_segurex_template_mail_goes_through_the_segurex_tenant_and_mailbox(): void
    {
        $this->fakeGraph();

        $mail = $this->templateMail($this->segurex)
            ->cc('paola@segurex.com', 'Paola')
            ->bcc('auditoria@segurex.com')
            ->replyTo('ti@segurex.com', 'Mesa TI')
            ->attachData('%PDF-1.4 informe', 'informe-GIA-7.pdf', ['mime' => 'application/pdf']);

        Mail::to('ana@segurex.com', 'Ana Ruiz')->send($mail);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://login.microsoftonline.com/'.self::SEGUREX_TENANT.'/oauth2/v2.0/token');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::DERIVADOS_TENANT));

        $request = $this->sendMailRequest();
        $this->assertSame('POST', $request->method());
        $this->assertSame('https://graph.microsoft.com/v1.0/users/segurex.info%40segurex.com/sendMail', $request->url());
        $this->assertSame(['Bearer tok-segurex'], $request->header('Authorization'));

        $message = $request['message'];
        $this->assertSame('[GIA-7] TI tiene una pregunta sobre tu solicitud', $message['subject']);
        $this->assertSame('HTML', $message['body']['contentType']);
        $this->assertStringContainsString('¿Qué columnas necesitas?', $message['body']['content']);
        $this->assertStringContainsString('<html', $message['body']['content']);
        $this->assertSame(['emailAddress' => ['address' => 'segurex.info@segurex.com', 'name' => 'Segurex · Gestor TI']], $message['from']);
        $this->assertSame([['emailAddress' => ['address' => 'ana@segurex.com', 'name' => 'Ana Ruiz']]], $message['toRecipients']);
        $this->assertSame([['emailAddress' => ['address' => 'paola@segurex.com', 'name' => 'Paola']]], $message['ccRecipients']);
        $this->assertSame([['emailAddress' => ['address' => 'auditoria@segurex.com']]], $message['bccRecipients']);
        $this->assertSame([['emailAddress' => ['address' => 'ti@segurex.com', 'name' => 'Mesa TI']]], $message['replyTo']);
        $this->assertSame([[
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => 'informe-GIA-7.pdf',
            'contentType' => 'application/pdf',
            'contentBytes' => base64_encode('%PDF-1.4 informe'),
            'isInline' => false,
        ]], $message['attachments']);
        $this->assertTrue($request['saveToSentItems']);
    }

    public function test_a_derivados_mail_uses_the_derivados_tenant_and_mailbox(): void
    {
        $this->fakeGraph();

        Mail::to('luis@derivadoscol.com')->send($this->templateMail($this->derivados));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://login.microsoftonline.com/'.self::DERIVADOS_TENANT.'/'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::SEGUREX_TENANT));

        $request = $this->sendMailRequest();
        $this->assertSame('https://graph.microsoft.com/v1.0/users/derivados.info%40derivadoscol.com/sendMail', $request->url());
        $this->assertSame(['Bearer tok-derivados'], $request->header('Authorization'));
        $this->assertSame([['emailAddress' => ['address' => 'luis@derivadoscol.com']]], $request['message']['toRecipients']);
        $this->assertArrayNotHasKey('ccRecipients', $request['message']);
        $this->assertArrayNotHasKey('attachments', $request['message']);
    }

    public function test_a_text_only_mail_is_sent_as_text_and_the_sent_items_copy_is_configurable(): void
    {
        $this->fakeGraph();
        config(['mail.mailers.graph.save_to_sent_items' => false]);

        Mail::raw('Hola desde el Gestor', fn ($message) => $message->from('ti@segurex.com')->to('ana@segurex.com')->subject('Texto'));

        $request = $this->sendMailRequest();
        // Not a company sender: the tenant comes from the sender's domain.
        $this->assertSame('https://graph.microsoft.com/v1.0/users/ti%40segurex.com/sendMail', $request->url());
        $this->assertSame(['contentType' => 'Text', 'content' => 'Hola desde el Gestor'], $request['message']['body']);
        $this->assertFalse($request['saveToSentItems']);
    }

    public function test_an_unknown_sender_is_refused_without_any_request(): void
    {
        $this->fakeGraph();

        try {
            Mail::raw('Hola', fn ($message) => $message->from('alguien@gmail.com')->to('ana@segurex.com')->subject('x'));
            $this->fail('An unknown sender must not be sent.');
        } catch (GraphMailException $exception) {
            $this->assertInstanceOf(TransportExceptionInterface::class, $exception);
            $this->assertSame('unknown_sender', $exception->reason);
            $this->assertFalse($exception->retryable);
            $this->assertStringContainsString('alguien@gmail.com', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_company_without_tenant_is_refused_without_any_request(): void
    {
        $this->fakeGraph();
        $this->derivados->update(['tenant_id' => null]);

        $this->expectException(GraphMailException::class);
        $this->expectExceptionMessage('tenant');

        try {
            Mail::to('luis@derivadoscol.com')->send($this->templateMail($this->derivados));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_attachments_over_the_graph_inline_limit_are_refused(): void
    {
        $this->fakeGraph();

        try {
            Mail::to('ana@segurex.com')->send(
                $this->templateMail($this->segurex)->attachData(str_repeat('a', 3 * 1024 * 1024 + 1), 'grande.pdf', ['mime' => 'application/pdf'])
            );
            $this->fail('Oversized attachments must not be sent.');
        } catch (GraphMailException $exception) {
            $this->assertSame('attachments_too_large', $exception->reason);
            $this->assertStringContainsString('3 MB', $exception->userMessage());
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'graph.microsoft.com'));
    }

    public function test_a_forbidden_send_explains_the_mail_send_consent_and_never_logs_secrets(): void
    {
        $this->fakeGraph(403, ['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied. Check credentials and try again.']]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        try {
            Mail::to('ana@segurex.com')->send($this->templateMail($this->segurex));
            $this->fail('A 403 must fail the send.');
        } catch (GraphMailException $exception) {
            $this->assertSame('forbidden', $exception->reason);
            $this->assertSame(403, $exception->status);
            $this->assertFalse($exception->retryable);
            $this->assertStringContainsString('Mail.Send', $exception->getMessage());
            $this->assertStringContainsString('ErrorAccessDenied', $exception->getMessage());
            $this->assertStringContainsString('Mail.Send', $exception->userMessage());
            report($exception);
        }

        $this->assertNotEmpty($logged);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('tok-segurex', $line);
            $this->assertStringNotContainsString(self::SECRET, $line);
        }
    }

    public function test_throttling_and_server_errors_are_retryable_transport_errors(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok-segurex', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::sequence()
                ->push(['error' => ['code' => 'ApplicationThrottled']], 429, ['Retry-After' => '5'])
                ->push(['error' => ['code' => 'ServiceUnavailable']], 503),
        ]);

        foreach ([429 => 'throttled', 503 => 'unavailable'] as $status => $reason) {
            try {
                Mail::to('ana@segurex.com')->send($this->templateMail($this->segurex));
                $this->fail("A {$status} must fail the send.");
            } catch (GraphMailException $exception) {
                $this->assertSame($reason, $exception->reason);
                $this->assertSame($status, $exception->status);
                $this->assertTrue($exception->retryable);
            }
        }
    }

    public function test_the_token_is_cached_per_tenant_between_sends(): void
    {
        $this->fakeGraph();

        Mail::to('ana@segurex.com')->send($this->templateMail($this->segurex));
        Mail::to('ana@segurex.com')->send($this->templateMail($this->segurex));

        $tokens = collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'login.microsoftonline.com'));
        $this->assertCount(1, $tokens);
    }

    public function test_a_plain_mailable_with_explicit_from_resolves_by_sender_email_case_insensitively(): void
    {
        $this->fakeGraph();

        Mail::to('ana@segurex.com')->send(new class extends Mailable
        {
            public function envelope(): Envelope
            {
                return new Envelope(from: 'Segurex.Info@Segurex.com', subject: 'Prueba');
            }

            public function content(): Content
            {
                return new Content(htmlString: '<p>Hola</p>');
            }
        });

        $request = $this->sendMailRequest();
        $this->assertStringContainsString('/users/segurex.info%40segurex.com/sendMail', $request->url());
        $this->assertSame(['contentType' => 'HTML', 'content' => '<p>Hola</p>'], $request['message']['body']);
    }
}
