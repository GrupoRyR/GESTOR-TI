<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Administración › Integraciones › "Enviarme un correo de prueba": sent
 * right away (not queued) to the acting user, from their company's sender
 * mailbox (else the default `MAIL_FROM_*`), so the page can report failures.
 */
class IntegrationTestMail extends Mailable
{
    public function __construct(public readonly ?Company $company, public readonly string $recipientName) {}

    public function envelope(): Envelope
    {
        $from = $this->company !== null && filled($this->company->sender_email)
            ? new Address((string) $this->company->sender_email, $this->company->sender_name ?: null)
            : null;

        return new Envelope(
            from: $from,
            subject: 'Correo de prueba del Gestor TI',
        );
    }

    public function content(): Content
    {
        $name = e($this->recipientName);
        $sender = $this->company !== null && filled($this->company->sender_email)
            ? e((string) $this->company->sender_email)
            : e((string) config('mail.from.address'));

        return new Content(htmlString: "<p>Hola, {$name}:</p>"
            .'<p>Este es un correo de prueba enviado desde Administración › Integraciones del Gestor TI. '
            ."Si lo recibes, el envío desde <strong>{$sender}</strong> funciona.</p>"
            .'<p>No necesitas responderlo.</p>');
    }
}
