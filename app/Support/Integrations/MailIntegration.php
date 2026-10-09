<?php

namespace App\Support\Integrations;

use App\Models\Company;
use App\Support\Settings;

/**
 * Outgoing mail as Integraciones shows it: the transport of the default
 * mailer (`graph` or `smtp`; anything else, e.g. `log`/`array`, means mail is
 * not set up yet), each active company's sender mailbox and the result of
 * the last "Enviarme un correo de prueba" (setting `integrations.mail.last_test`
 * = `{ok, at}`).
 */
class MailIntegration
{
    public const LAST_TEST_KEY = 'integrations.mail.last_test';

    public const PENDING_NOTE = 'Falta configurar el envío (Microsoft Graph o SMTP) en el servidor. Mientras tanto los avisos van por Telegram y la campana.';

    public const ERROR_NOTE = 'Falló el último correo de prueba. Revisa la configuración de correo del servidor y vuelve a probar.';

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return 'graph'|'smtp'|null
     */
    public function transport(): ?string
    {
        $mailer = (string) config('mail.default');
        $config = (array) config('mail.mailers.'.$mailer, []);
        $transport = strtolower((string) ($config['transport'] ?? $mailer));

        return match (true) {
            in_array($transport, ['graph', 'microsoft-graph', 'msgraph'], true) => 'graph',
            $transport === 'smtp' && filled($config['host'] ?? null) => 'smtp',
            default => null,
        };
    }

    public function recordTest(bool $ok): void
    {
        $this->settings->set(self::LAST_TEST_KEY, ['ok' => $ok, 'at' => now()->toIso8601String()]);
    }

    /**
     * `IntegrationsProps['mail']`.
     *
     * @return array{status: 'ok'|'pending'|'error', transport: ?string, senders: list<array{company_id: int, email: string}>, note: ?string}
     */
    public function toProps(): array
    {
        $transport = $this->transport();
        $lastTest = (array) $this->settings->get(self::LAST_TEST_KEY, []);
        $failed = ($lastTest['ok'] ?? null) === false;

        [$status, $note] = match (true) {
            $transport === null => ['pending', self::PENDING_NOTE],
            $failed => ['error', self::ERROR_NOTE],
            default => ['ok', null],
        };

        return [
            'status' => $status,
            'transport' => $transport,
            'senders' => Company::query()
                ->active()
                ->whereNotNull('sender_email')
                ->where('sender_email', '!=', '')
                ->orderBy('name')
                ->get(['id', 'sender_email'])
                ->map(fn (Company $company): array => ['company_id' => $company->id, 'email' => (string) $company->sender_email])
                ->all(),
            'note' => $note,
        ];
    }
}
