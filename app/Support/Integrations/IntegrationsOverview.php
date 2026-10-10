<?php

namespace App\Support\Integrations;

use App\Models\Company;
use App\Models\Employee;
use App\Models\TelegramLink;
use App\Services\Microsoft\GraphClient;
use App\Services\Telegram\TelegramClient;
use App\Support\EmployeeSyncStatus;
use App\Support\Settings;

/**
 * Props of Administración › Integraciones (`IntegrationsProps`,
 * types/pages/admin.ts). Only states, names and counts: no key, token or
 * secret ever leaves the server.
 */
class IntegrationsOverview
{
    public const TELEGRAM_LAST_UPDATE_KEY = 'telegram.last_update_at';

    public const NO_TENANT_ERROR = 'La empresa no tiene inquilino de Microsoft: agrégalo en Empresas.';

    public const NO_CREDENTIALS_ERROR = 'Faltan las credenciales de la aplicación de Microsoft (MICROSOFT_CLIENT_ID y MICROSOFT_CLIENT_SECRET) en el servidor.';

    public function __construct(
        private readonly Settings $settings,
        private readonly EmployeeSyncStatus $syncStatus,
        private readonly MailIntegration $mail,
        private readonly TelegramClient $telegram,
        private readonly TelegramWebhookStatus $webhook,
        private readonly AiProviderSettings $ai,
        private readonly GitHubIntegration $github,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toProps(): array
    {
        return [
            'microsoft' => ['companies' => $this->microsoft()],
            'mail' => $this->mail->toProps(),
            'telegram' => $this->telegram(),
            'github' => $this->github->toCardProps(),
            'ai' => $this->ai->toProps(),
        ];
    }

    /**
     * One row per active company, by name. `synced_count` = active
     * employees of the company once a sync succeeded.
     *
     * @return list<array<string, mixed>>
     */
    private function microsoft(): array
    {
        $configured = GraphClient::isConfigured();

        return Company::query()
            ->active()
            ->withCount(['employees' => fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('status', Employee::STATUS_ACTIVE)])
            ->orderBy('name')
            ->get()
            ->map(function (Company $company) use ($configured): array {
                $state = $this->syncStatus->get($company->id);
                $lastSuccess = $state['last_success_at'] ?? null;
                $error = filled($state['error'] ?? null) ? (string) $state['error'] : null;

                $status = match (true) {
                    $error !== null => 'error',
                    $lastSuccess !== null => 'ok',
                    default => 'never',
                };

                if ($error === null && $status === 'never') {
                    $error = match (true) {
                        ! $company->isSyncable() => self::NO_TENANT_ERROR,
                        ! $configured => self::NO_CREDENTIALS_ERROR,
                        default => null,
                    };
                }

                return [
                    'company_id' => $company->id,
                    'status' => $status,
                    'last_sync_at' => $lastSuccess,
                    'synced_count' => $lastSuccess !== null ? (int) $company->getAttribute('employees_count') : null,
                    'error' => $error,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function telegram(): array
    {
        $configured = $this->telegram->isConfigured();
        $webhookOk = $configured && $this->webhook->ok();
        $lastUpdate = $this->settings->get(self::TELEGRAM_LAST_UPDATE_KEY);

        return [
            'status' => ! $configured ? 'off' : ($webhookOk ? 'ok' : 'error'),
            'bot_username' => filled(config('services.telegram.bot_username')) ? ltrim((string) config('services.telegram.bot_username'), '@') : null,
            'linked_users' => TelegramLink::query()->whereNotNull('chat_id')->whereNotNull('linked_at')->count(),
            'last_message_at' => is_string($lastUpdate) && $lastUpdate !== '' ? $lastUpdate : null,
            'webhook_ok' => $webhookOk,
        ];
    }
}
