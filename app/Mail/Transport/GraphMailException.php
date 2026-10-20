<?php

namespace App\Mail\Transport;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * A message the `graph` mailer could not hand to Microsoft Graph.
 *
 * `getMessage()` is English, for the logs; `userMessage()` is the Spanish
 * text Integraciones shows. Neither ever carries a token, the client
 * secret or a Graph response body: only the HTTP status and Graph's
 * `error.code`. `retryable` is true for throttling (429), server errors
 * (5xx) and connection failures: the queued notification is retried by the
 * queue (any exception fails the attempt), the rest will fail again until
 * the configuration changes.
 */
final class GraphMailException extends TransportException
{
    private const CONSENT_HINT = 'Grant the Microsoft Graph Mail.Send application permission to the app in this tenant '
        .'(admin consent, or an Exchange Online "Application Mail.Send" RBAC assignment scoped to this mailbox) '
        .'/ Falta el permiso Mail.Send de la aplicación en este inquilino.';

    private function __construct(
        string $message,
        public readonly string $reason,
        private readonly string $userMessage,
        public readonly bool $retryable = false,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }

    public static function missingSender(): self
    {
        return new self('The message has no From address to send through Microsoft Graph.', 'missing_sender',
            'el correo no tiene remitente.');
    }

    public static function unknownSender(string $sender): self
    {
        return new self("No company has the sender mailbox {$sender} (sender_email) or its domain; the message was not sent.", 'unknown_sender',
            "ninguna empresa tiene el buzón remitente {$sender} ni su dominio (revisa el correo remitente de la empresa).");
    }

    public static function missingTenant(string $sender, string $company): self
    {
        return new self("Company {$company} (sender {$sender}) has no Microsoft tenant id; the message was not sent.", 'missing_tenant',
            "la empresa {$company} no tiene inquilino de Microsoft configurado.");
    }

    public static function notConfigured(): self
    {
        return new self('Microsoft app credentials (MICROSOFT_CLIENT_ID / MICROSOFT_CLIENT_SECRET) are not configured.', 'not_configured',
            'faltan las credenciales de la aplicación de Microsoft (MICROSOFT_CLIENT_ID y MICROSOFT_CLIENT_SECRET) en el servidor.');
    }

    public static function token(string $company, ?int $status): self
    {
        $code = $status === null ? 'graph_token' : "graph_token_{$status}";

        return new self("Microsoft did not issue an app token for the tenant of {$company} ({$code}).", 'token',
            "Microsoft no entregó el token del inquilino de {$company} (código {$code}).",
            retryable: $status === null || $status === 429 || $status >= 500, status: $status);
    }

    public static function attachmentsTooLarge(int $bytes, int $limit): self
    {
        $mb = number_format($bytes / 1048576, 1);
        $limitMb = (int) round($limit / 1048576);

        return new self("Attachments total {$mb} MB; Microsoft Graph sendMail accepts at most {$limitMb} MB of attachments per request. The message was not sent.", 'attachments_too_large',
            "los adjuntos suman {$mb} MB y Microsoft Graph solo acepta hasta {$limitMb} MB por correo.");
    }

    public static function connection(string $sender): self
    {
        return new self("Could not connect to Microsoft Graph to send mail as {$sender}.", 'connection',
            'no se pudo conectar con Microsoft (código graph_connection).', retryable: true);
    }

    public static function rejected(string $sender, int $status, ?string $graphCode): self
    {
        $detail = 'HTTP '.$status.($graphCode !== null ? ' '.$graphCode : '');
        $code = 'graph_'.$status.($graphCode !== null ? ' '.$graphCode : '');

        return match (true) {
            $status === 401, $status === 403 => new self(
                "Microsoft Graph refused to send mail as {$sender} ({$detail}). ".self::CONSENT_HINT, 'forbidden',
                "Microsoft rechazó el envío desde {$sender}: falta el permiso Mail.Send (aplicación) en ese inquilino o el buzón está fuera del ámbito permitido a la aplicación (código {$code}).",
                status: $status),
            $status === 429 => new self(
                "Microsoft Graph throttled mail from {$sender} ({$detail}); retry later.", 'throttled',
                "Microsoft limitó los envíos por ahora; se reintentará (código {$code}).",
                retryable: true, status: $status),
            $status >= 500 => new self(
                "Microsoft Graph failed to send mail as {$sender} ({$detail}); retry later.", 'unavailable',
                "Microsoft no pudo procesar el envío; se reintentará (código {$code}).",
                retryable: true, status: $status),
            default => new self(
                "Microsoft Graph rejected the message from {$sender} ({$detail}).", 'rejected',
                "Microsoft rechazó el correo desde {$sender} (código {$code}).",
                status: $status),
        };
    }
}
